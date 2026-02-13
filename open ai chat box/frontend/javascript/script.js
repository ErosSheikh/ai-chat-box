// Minimal frontend script for Nutron Chat
// Restores basic UI interactions: theme, sidebar, welcome banner, send message

(function(){
  'use strict';

  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));

  // Elements
  const chatMessages = $('#chat-messages');
  const userInput = $('#user-input');
  const sendBtn = $('#send-btn');
  const typingIndicator = $('#typing-indicator');
  const micBtn = $('#mic-btn');
  const welcome = document.querySelector('.welcome-banner');
  const welcomeClose = document.querySelector('.welcome-close');
  const sidebar = document.querySelector('.sidebar');
  const sidebarOverlay = $('#sidebar-overlay');
  const sidebarToggle = $('#sidebar-toggle');

  // Text-to-Speech setup
  const synth = window.speechSynthesis;
  let isReadingEnabled = false;

  // Utilities
  function setTheme(isDark){
    if(isDark) document.body.classList.add('dark-theme');
    else document.body.classList.remove('dark-theme');
    localStorage.setItem('darkTheme', !!isDark);
  }

  function showTyping(show){
    if(!typingIndicator) return;
    typingIndicator.style.display = show ? 'block' : 'none';
  }

  function readMessageAloud(text){
    if(!isReadingEnabled || !synth) return;
    synth.cancel();
    const utterance = new SpeechSynthesisUtterance(text);
    utterance.rate = 1;
    utterance.pitch = 1;
    utterance.volume = 1;
    synth.speak(utterance);
  }

  function appendMessage(sender, text){
    if(!chatMessages) return;
    const wrap = document.createElement('div');
    wrap.className = 'message ' + (sender === 'user' ? 'user' : 'ai');
    const row = document.createElement('div');
    row.className = 'message-row';
    const bubble = document.createElement('div');
    bubble.className = 'bubble ' + (sender === 'user' ? 'user' : 'ai');
    bubble.textContent = text;
    row.appendChild(bubble);
    wrap.appendChild(row);
    chatMessages.appendChild(wrap);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // Read aloud if AI message and enabled
    if(sender === 'ai' && isReadingEnabled){
      setTimeout(()=>readMessageAloud(text), 300);
    }
  }

  async function sendMessage(){
    const text = userInput.value.trim();
    if(!text) return;
    appendMessage('user', text);
    userInput.value = '';
    showTyping(true);

    try{
      const resp = await fetch('../backend_php/api.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ message: text })
      });
      const data = await resp.json();
      const reply = data && (data.reply || data.error) ? (data.reply || data.error) : 'No response.';
      appendMessage('ai', reply);
    }catch(err){
      appendMessage('ai', 'Oops — something went wrong on our side. Please try again in a moment.');
    }finally{
      showTyping(false);
    }
  }

  function initTheme(){
    const stored = localStorage.getItem('darkTheme');
    const isDark = (stored === null) ? true : (stored === 'true');
    setTheme(isDark);
    
    // Setup theme toggle button
    const themeToggle = $('#theme-toggle');
    if(themeToggle){
      themeToggle.addEventListener('click', ()=>{
        const isDarkNow = document.body.classList.contains('dark-theme');
        setTheme(!isDarkNow);
      });
    }
  }

  function initWelcome(){
    if(!welcome) return;
    const dismissed = localStorage.getItem('welcomeDismissed');
    if(dismissed === 'true') welcome.classList.add('collapsed');
    if(welcomeClose){
      welcomeClose.addEventListener('click', ()=>{
        welcome.classList.add('collapsed');
        localStorage.setItem('welcomeDismissed', 'true');
      });
    }
  }

  function initSidebar(){
    if(!sidebar) return;
    const open = localStorage.getItem('sidebarOpen') === 'true';
    if(open) document.body.classList.add('sidebar-open');
    if(sidebarToggle){
      sidebarToggle.addEventListener('click', ()=>{
        const isOpen = document.body.classList.toggle('sidebar-open');
        localStorage.setItem('sidebarOpen', isOpen);
        sidebarOverlay.hidden = !isOpen;
      });
    }
    if(sidebarOverlay){
      sidebarOverlay.addEventListener('click', ()=>{
        document.body.classList.remove('sidebar-open');
        sidebarOverlay.hidden = true;
        localStorage.setItem('sidebarOpen', 'false');
      });
    }
  }

  function initSendHandlers(){
    if(sendBtn) sendBtn.addEventListener('click', sendMessage);
    if(userInput) userInput.addEventListener('keydown', (e)=>{ if(e.key === 'Enter') sendMessage(); });
  }

  // DOM ready
  document.addEventListener('DOMContentLoaded', ()=>{
    initTheme();
    initWelcome();
    initSidebar();
    initSendHandlers();

    // Setup read aloud toggle
    const readAloudToggle = $('#read-aloud-toggle');
    if(readAloudToggle) {
      readAloudToggle.addEventListener('change', (e)=>{
        isReadingEnabled = e.target.checked;
      });
    }

    // Compact toggle button
    const compactToggle = $('#compact-toggle');
    if(compactToggle) {
      compactToggle.addEventListener('click', ()=>{
        const isCompact = document.body.classList.toggle('compact');
        localStorage.setItem('compactMode', isCompact);
      });
      // Restore compact mode from localStorage
      if(localStorage.getItem('compactMode') === 'true') {
        document.body.classList.add('compact');
      }
    }

    // New chat button
    const newChatBtn = $('#new-chat-btn');
    if(newChatBtn) {
      newChatBtn.addEventListener('click', ()=>{
        if(chatMessages) chatMessages.innerHTML = '';
        if(userInput) userInput.value = '';
      });
    }

    // Save chat button
    const saveChatBtn = $('#save-chat-btn');
    if(saveChatBtn) {
      saveChatBtn.addEventListener('click', ()=>{
        if(chatMessages) {
          const messages = Array.from(chatMessages.querySelectorAll('.bubble')).map(b => b.textContent);
          if(messages.length > 0) {
            localStorage.setItem('savedChat', JSON.stringify(messages));
            alert('Conversation saved!');
          } else {
            alert('No messages to save.');
          }
        }
      });
    }

    // Clear all chats button
    const clearChatsBtn = $('#clear-chats-btn');
    if(clearChatsBtn) {
      clearChatsBtn.addEventListener('click', ()=>{
        if(confirm('Are you sure? All conversations will be deleted.')) {
          if(chatMessages) chatMessages.innerHTML = '';
          localStorage.removeItem('savedChat');
          alert('All conversations cleared!');
        }
      });
    }

    // Report issue link
    const reportIssueLink = $('#report-issue-link');
    if(reportIssueLink) {
      reportIssueLink.addEventListener('click', (e)=>{
        e.preventDefault();
        window.location.href = 'report-issue.html';
      });
    }

    // basic mic button hook
    if(micBtn) micBtn.addEventListener('click', ()=>{
      micBtn.classList.toggle('listening');
      const status = $('#mic-status');
      if(micBtn.classList.contains('listening')) status && (status.style.display = 'inline');
      else status && (status.style.display = 'none');
    });
  });

})();
