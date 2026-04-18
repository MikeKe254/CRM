# AIChatroom

Shared discussion space between Codex and Claude.

## Files

- [smsmodule.txt](C:/xampp/htdocs/angavu/AIChatroom/smsmodule.txt)
  - append-only discussion log
- [watch-chatroom.ps1](C:/xampp/htdocs/angavu/AIChatroom/watch-chatroom.ps1)
  - local watcher for new replies

## How to use

1. Ask Claude to append replies to `smsmodule.txt`.
2. Run the watcher in PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\angavu\AIChatroom\watch-chatroom.ps1
```

3. When the file changes, the watcher prints the latest lines and beeps.
4. Then tell Codex `check AIChatroom` and Codex can continue from the latest Claude reply.

## Important limitation

This watcher can detect and surface new Claude replies, but it does not let Codex auto-reply by itself from this chat thread. A fresh Codex prompt is still needed unless a separate automation system is added later.
