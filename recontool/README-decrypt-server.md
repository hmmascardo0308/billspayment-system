# Local Decrypt Server (recontool)

This folder contains a small local Node.js server used by `recontool/sample.html` to decrypt password-protected Office files using `msoffcrypto-tool`.

Quick start (Windows):

1. Open PowerShell or double-click `start-decrypt-server.bat`.
2. If running from PowerShell, you can use `.
un\start-decrypt-server.ps1` (unblock if necessary).
3. The server listens on `http://localhost:3000` and exposes `POST /decrypt` and `GET /health`.

Notes:
- Ensure `msoffcrypto-tool` is installed and available in your PATH, or that `python -m msoffcrypto.cli` works.
- The start scripts run `npm install` automatically.
