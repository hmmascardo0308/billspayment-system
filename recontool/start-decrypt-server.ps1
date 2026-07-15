# PowerShell start script for decrypt server
Set-StrictMode -Version Latest
Push-Location $PSScriptRoot
Write-Host "Installing node dependencies (if missing)..."
npm install
Write-Host "Starting decrypt server on http://localhost:3000"
node decrypt-server.js
Pop-Location
