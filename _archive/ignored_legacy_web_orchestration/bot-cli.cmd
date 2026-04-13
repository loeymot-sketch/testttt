@echo off
setlocal
REM Lance bot/cli.py via PowerShell (resolution Python fiable sous Windows).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0bot-cli.ps1" %*
exit /b %ERRORLEVEL%
