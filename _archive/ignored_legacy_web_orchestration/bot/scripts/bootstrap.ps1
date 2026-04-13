# FoodKing bot — minimal bootstrap (Windows). Does not start a daemon.
$ErrorActionPreference = "Stop"
# Script path: repo/bot/scripts → bot root is parent of scripts
$BotRoot = Split-Path -Parent $PSScriptRoot
$StateDir = Join-Path $BotRoot "state"
$LogsDir = Join-Path $BotRoot "logs"
New-Item -ItemType Directory -Force -Path $StateDir | Out-Null
New-Item -ItemType Directory -Force -Path $LogsDir | Out-Null
Write-Host "bot root: $BotRoot"
Write-Host "state/ and logs/ ensured. Copy bot/config/*.example.json to real configs when ready."
exit 0
