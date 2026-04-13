<#
.SYNOPSIS
  Guided local operator flow — FoodKing bot v0 (human-in-the-loop, no APIs).

.DESCRIPTION
  - Prompts for task_id and goal
  - reset-idle → begin-cycle → build-claude-handoff
  - Opens claude_handoff.md with the default Windows association (local file only)
  - Prints exact next steps for the operator

  Does NOT: call Claude/Cursor APIs, automate a browser, Telegram, or Git.

  Run from anywhere; repo root is resolved from this script location:
  <repo>/bot/scripts/run_manual_cycle.ps1 → <repo> = parent(parent($PSScriptRoot))

.EXAMPLE
  cd C:\path\to\FoodKing
  powershell -NoProfile -ExecutionPolicy Bypass -File .\bot\scripts\run_manual_cycle.ps1
#>
$ErrorActionPreference = "Stop"

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$Launcher = Join-Path $RepoRoot "bot-cli.ps1"
if (-not (Test-Path -LiteralPath $Launcher)) {
    Write-Host "Fichier introuvable: $Launcher" -ForegroundColor Red
    Write-Host "Le lanceur bot-cli.ps1 doit exister a la racine du depot." -ForegroundColor Yellow
    exit 2
}

function Invoke-BotCli {
    param([string[]]$Arguments)
    # stderr Python (ex. message begin-cycle) ne doit pas faire echouer le script (ErrorAction Stop).
    $prev = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        & $Launcher @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "Commande bot CLI en echec (code $LASTEXITCODE): bot-cli.ps1 $($Arguments -join ' ')"
        }
    } finally {
        $ErrorActionPreference = $prev
    }
}

Set-Location -LiteralPath $RepoRoot

$taskId = Read-Host "task_id (ex. BOT-SMOKE-001)"
if ([string]::IsNullOrWhiteSpace($taskId)) {
    Write-Host "task_id vide — arret." -ForegroundColor Red
    exit 1
}
$goal = Read-Host "goal (description courte)"
if ([string]::IsNullOrWhiteSpace($goal)) {
    Write-Host "goal vide — arret." -ForegroundColor Red
    exit 1
}

Write-Host "`n>>> reset-idle" -ForegroundColor Cyan
Invoke-BotCli @("reset-idle") | Out-Null

Write-Host ">>> begin-cycle" -ForegroundColor Cyan
Invoke-BotCli @("begin-cycle", "--task-id", $taskId.Trim(), "--goal", $goal.Trim(), "--trigger", "human") | Out-Null

$stateAfterBegin = (Invoke-BotCli @("show-state") | Out-String | ConvertFrom-Json)
Write-Host "cycle_id courant: $($stateAfterBegin.cycle_id)" -ForegroundColor Green
Write-Host "Dossier handoff: bot\state\handoffs\$($stateAfterBegin.cycle_id)\" -ForegroundColor Green

Write-Host ">>> build-claude-handoff" -ForegroundColor Cyan
$mdPathLine = Invoke-BotCli @("build-claude-handoff") 2>$null
$mdPath = ($mdPathLine | Select-Object -Last 1).Trim()
if (-not (Test-Path -LiteralPath $mdPath) -and $stateAfterBegin.cycle_id) {
    $mdPath = Join-Path $RepoRoot "bot\state\handoffs\$($stateAfterBegin.cycle_id)\claude_handoff.md"
}

if (Test-Path -LiteralPath $mdPath) {
    Write-Host "Ouverture: $mdPath" -ForegroundColor Green
    Invoke-Item -LiteralPath $mdPath
} else {
    Write-Host "Impossible de localiser claude_handoff.md (chemin attendu: $mdPath)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "========== Prochaines etapes (operateur) ==========" -ForegroundColor Cyan
Write-Host "1) Copier le contenu de claude_handoff.md (ou le fichier) vers ton projet / conversation Claude."
Write-Host "2) Obtenir la reponse Claude au format JSON **plan** (voir templates bot/ et docs/ops)."
Write-Host "3) Enregistrer ce JSON sous:"
Write-Host "     bot\state\handoffs\<cycle_id>\plan.json   (nom libre, ex. _plan.json)"
Write-Host "   Le cycle_id courant est dans: python bot/cli.py show-state   (champ cycle_id)"
Write-Host "4) Enregistrer le plan aupres du bot:"
Write-Host "     .\bot-cli.ps1 register-claude-response --file bot\state\handoffs\<cycle_id>\ton_plan.json"
Write-Host "5) Generer le handoff Cursor:"
Write-Host "     .\bot-cli.ps1 build-cursor-handoff"
Write-Host "6) Executer le travail dans Cursor puis: register-cursor-finished, validation, etc."
Write-Host "   (boucle complete: voir bot\docs\BOT_WINDOWS_OPERATOR_FLOW.md)"
Write-Host "====================================================`n"
