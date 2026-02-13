<?php
/**
 * backend_php/api.php
 *
 * Role: PHP entrypoint that accepts POST requests from the frontend and
 * forwards chat payloads to the Python process which calls the OpenAI API.
 *
 * Mapping to system layers (see SYSTEM_ARCHITECTURE.md):
 * - Frontend: `frontend/javascript/script.js` POSTs to this endpoint
 * - This PHP script: validates, rate-limits, logs, and executes `backend_python/chat.py`
 * - Python: `backend_python/chat.py` connects to the OpenAI API and returns JSON
 */

header('Content-Type: application/json; charset=utf-8');
// Allow basic CORS for development (adjust in production)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}
header('Access-Control-Allow-Origin: *');

// Basic rate-limiting (per IP): max 10 requests per minute
function rate_limit_check($ip) {
    $limit = 10;
    $window = 60; // seconds
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'openai_rate_' . md5($ip);
    $now = time();
    $timestamps = [];
    if (file_exists($file)) {
        $contents = file_get_contents($file);
        $timestamps = $contents ? array_map('intval', explode(',', $contents)) : [];
        $timestamps = array_filter($timestamps, function($t) use ($now, $window) {
            return ($t > $now - $window);
        });
    }
    if (count($timestamps) >= $limit) {
        return false;
    }
    $timestamps[] = $now;
    file_put_contents($file, implode(',', $timestamps));
    return true;
}

function write_request_log($payload, $ip, $status, $extra = []) {
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $file = $logDir . DIRECTORY_SEPARATOR . 'requests.log';
    $entry = [
        'ts' => time(),
        'ip' => $ip,
        'message' => mb_substr(is_string($payload) ? $payload : json_encode($payload), 0, 200),
        'status' => $status,
        'extra' => $extra
    ];
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!rate_limit_check($clientIp)) {
    write_request_log('', $clientIp, 'rate_limited');
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please slow down.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    write_request_log('', $clientIp, 'method_not_allowed');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    write_request_log($raw, $clientIp, 'invalid_json');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$message = isset($data['message']) ? trim($data['message']) : '';
$context = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];

if ($message === '') {
    write_request_log($message, $clientIp, 'missing_message');
    http_response_code(400);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

if (mb_strlen($message) > 2000) {
    write_request_log($message, $clientIp, 'message_too_long');
    http_response_code(400);
    echo json_encode(['error' => 'Message too long']);
    exit;
}

// Limit context size
if (count($context) > 20) {
    $context = array_slice($context, -20);
}

// Ensure OpenAI API key is available in server env
$openaiKey = getenv('OPENAI_API_KEY');
if (!$openaiKey) {
    write_request_log($message, $clientIp, 'no_api_key');
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: OPENAI_API_KEY not set']);
    exit;
}

// Prepare to call Python script
$scriptPath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'backend_python' . DIRECTORY_SEPARATOR . 'chat.py';
$scriptPath = realpath($scriptPath);
if (!$scriptPath || !file_exists($scriptPath)) {
    write_request_log($message, $clientIp, 'no_chat_py');
    http_response_code(500);
    echo json_encode(['error' => 'chat.py not found on server']);
    exit;
}

$payload = json_encode(['message' => $message, 'context' => array_values($context)]);

// Find Python executable (try python then python3)
$pythonCmds = ['python', 'python3'];
$python = null;
foreach ($pythonCmds as $cmd) {
    $which = null;
    // On Windows the `where` command is used, on others `which`
    if (stripos(PHP_OS, 'WIN') === 0) {
        @exec("where $cmd 2>NUL", $out, $ret);
    } else {
        @exec("which $cmd", $out, $ret);
    }
    if (!empty($out) && $ret === 0) {
        $python = $cmd;
        break;
    }
    $out = [];
}
if (!$python) {
    // Fallback to just 'python' and hope PATH is configured
    $python = 'python';
}

$descriptorspec = [
    0 => ['pipe', 'r'], // stdin
    1 => ['pipe', 'w'], // stdout
    2 => ['pipe', 'w']  // stderr
];
$cmd = escapeshellcmd($python) . ' ' . escapeshellarg($scriptPath);

$env = array_merge($_ENV, ['OPENAI_API_KEY' => $openaiKey]);

$process = @proc_open($cmd, $descriptorspec, $pipes, __DIR__, $env);
if (!is_resource($process)) {
    write_request_log($payload, $clientIp, 'process_start_failed');
    http_response_code(500);
    echo json_encode(['error' => 'Failed to start chat process']);
    exit;
}

// Write input to python stdin
stream_set_blocking($pipes[0], true);
fwrite($pipes[0], $payload);
fclose($pipes[0]);

// Read stdout and stderr with timeout
$stdout = '';
$stderr = '';
stream_set_blocking($pipes[1], true);
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);

stream_set_blocking($pipes[2], true);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);

$exitCode = proc_close($process);

if ($stderr) {
    // Python may print JSON error messages; try to parse stdout first
    // but return stderr for debugging in server logs
    error_log("chat.py stderr: $stderr");
}

$response = json_decode($stdout, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    write_request_log($payload, $clientIp, 'invalid_process_response', ['raw' => $stdout, 'stderr' => $stderr]);
    http_response_code(500);
    echo json_encode(['error' => 'Invalid response from chat process', 'raw' => $stdout]);
    exit;
}

if (isset($response['error'])) {
    write_request_log($payload, $clientIp, 'process_error', ['err' => $response['error']]);
    http_response_code(500);
    echo json_encode(['error' => $response['error']]);
    exit;
}

// Success
write_request_log($payload, $clientIp, 'success', ['model' => $response['model'] ?? null]);
http_response_code(200);
echo json_encode(['reply' => $response['reply'] ?? '', 'model' => $response['model'] ?? null]);
exit;
