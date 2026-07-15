@echo off
REM Start script for decrypt server (Windows)
SETLOCAL
cd /d %~dp0
echo Installing node dependencies (if missing)...
npm install
echo Starting decrypt server on http://localhost:3000
node decrypt-server.js
ENDLOCAL
