
#!/usr/bin/env python3
import os
import sys
import json
from openai import OpenAI


def main():
    try:
        payload = sys.stdin.read()
        data = json.loads(payload) if payload else {}

        message = data.get("message", "")
        context = data.get("context", [])

        if not os.environ.get("OPENAI_API_KEY"):
            print(json.dumps({"error": "OPENAI_API_KEY not set"}))
            return

        client = OpenAI()

        # Proper role-based messages
        messages = [{"role": "system", "content": "You are a helpful assistant."}]

        # If context is already role-based, use it directly
        for msg in context[-6:]:
            if isinstance(msg, dict) and "role" in msg and "content" in msg:
                messages.append(msg)

        messages.append({"role": "user", "content": message})

        resp = client.chat.completions.create(
            model="gpt-4.1-mini",
            messages=messages,
            temperature=0.7,
            max_tokens=500
        )

        reply = resp.choices[0].message.content.strip()

        print(json.dumps({
            "reply": reply,
            "model": resp.model
        }))

    except Exception as e:
        print(json.dumps({"error": str(e)}))


if __name__ == "__main__":
    main()

from dotenv import load_dotenv
import os

load_dotenv()

api_key = os.getenv("OPENAI_API_KEY")
