<#
.SYNOPSIS
  Boucle d'automatisation FoodKing (une commande, pas de service Windows).

.DESCRIPTION
  Enchaîne de façon bornée : browser-bridge-prepare, browser-bridge-next-action,
  éventuellement browser-run-step, puis run-supervisor-once.

  Arrêt immédiat sur états terminaux / pause humaine / échec navigateur.
  Un seul cycle actif : ne lance pas plusieurs instances en parallèle.

.PARAMETER MaxSteps
  Nombre maximum d'itérations (garde-fou anti-boucle infinie).

.PARAMETER Config
  Chemin optionnel vers bot_config.json (transmis à chaque appel CLI).

.USAGE
  Depuis la racine du dépôt :
    .\bot\scripts\run_auto_cycle.ps1
    .\bot\scripts\run_auto_cycle.ps1 -MaxSteps 30 -Config .\bot\config\bot_config.json
#>
param(
    [int]$MaxSteps = 50,
    [string]$Config = ""
)

$ErrorActionPreference = "Continue"
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location -LiteralPath $RepoRoot

# Réduit le mojibake (ex. « RÃ©sumÃ© ») dans les consoles Windows en CP1252.
try {
    [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
    $OutputEncoding = [System.Text.UTF8Encoding]::new($false)
} catch { }

$BotCli = Join-Path $RepoRoot "bot-cli.ps1"
if (-not (Test-Path -LiteralPath $BotCli)) {
    Write-Host "FATAL: bot-cli.ps1 introuvable: $BotCli" -ForegroundColor Red
    exit 1
}

$NextActionPath = Join-Path $RepoRoot "bot\state\browser_bridge_next_action.json"

function Get-ConfigCliArgs {
    if ($Config -and $Config.Trim()) {
        return @("--config", $Config.Trim())
    }
    return @()
}

function Invoke-BotCli {
    param([string[]]$CliArgs)
    $cfg = Get-ConfigCliArgs
    # Ne pas renvoyer la sortie au pipeline (sinon $code = Invoke-BotCli capture mélange stdout + code).
    & $BotCli @cfg @CliArgs 2>&1 | ForEach-Object {
        Write-Host "    $_" -ForegroundColor DarkGray
    }
    return [int]$LASTEXITCODE
}

function Get-CycleState {
    $raw = & $BotCli (Get-ConfigCliArgs) show-state 2>&1
    if ($LASTEXITCODE -ne 0 -or -not $raw) { return $null }
    try {
        $joined = ($raw | ForEach-Object { "$_" }) -join "`n"
        return $joined | ConvertFrom-Json
    } catch {
        return $null
    }
}

function Test-FsmShouldStop {
    param($State)
    if (-not $State) { return @{ Stop = $true; Reason = "cycle_state_unreadable" } }
    $s = "$($State.state)"
    switch ($s) {
        "completed" { return @{ Stop = $true; Reason = "fsm_completed" } }
        "blocked" { return @{ Stop = $true; Reason = "fsm_blocked" } }
        "manual_gate" { return @{ Stop = $true; Reason = "fsm_manual_gate" } }
        "waiting_playwright" { return @{ Stop = $true; Reason = "fsm_waiting_playwright" } }
        "idle" { return @{ Stop = $true; Reason = "fsm_idle" } }
        "preparing_intake" { return @{ Stop = $true; Reason = "fsm_preparing_intake" } }
        default { return @{ Stop = $false; Reason = "" } }
    }
}

function Test-NextActionShouldStop {
    param($Na)
    if (-not $Na) { return @{ Stop = $true; Reason = "next_action_missing" } }
    if ($Na.paused -eq $true) { return @{ Stop = $true; Reason = "bridge_paused" } }
    $ak = "$($Na.action_kind)"
    switch ($ak) {
        "paused" { return @{ Stop = $true; Reason = "action_kind_paused" } }
        "playwright_pending" { return @{ Stop = $true; Reason = "action_kind_playwright_pending" } }
        "local_validation" { return @{ Stop = $true; Reason = "action_kind_local_validation_human_ci" } }
        "terminal_or_idle" { return @{ Stop = $true; Reason = "action_kind_terminal_or_idle" } }
        "unknown_fsm_wait" { return @{ Stop = $true; Reason = "action_kind_unknown_fsm_wait" } }
        "noop" { return @{ Stop = $true; Reason = "action_kind_noop" } }
        default { return @{ Stop = $false; Reason = "" } }
    }
}

function Get-NextActionFromDisk {
    if (-not (Test-Path -LiteralPath $NextActionPath)) { return $null }
    try {
        $raw = Get-Content -LiteralPath $NextActionPath -Raw -Encoding UTF8
        return $raw | ConvertFrom-Json
    } catch {
        return $null
    }
}

function Test-HasBridgeBlockers {
    param($Na)
    if (-not $Na) { return $false }
    $b = $Na.blockers
    if ($null -eq $b) { return $false }
    $arr = @($b | ForEach-Object { "$_" })
    return ($arr.Count -gt 0)
}

function Invoke-BrowserRunStepJson {
    $out = & $BotCli (Get-ConfigCliArgs) browser-run-step 2>&1
    $code = $LASTEXITCODE
    $text = ($out | ForEach-Object { "$_" }) -join "`n"
    $obj = $null
    try {
        $obj = $text | ConvertFrom-Json
    } catch {
        $obj = $null
    }
    return @{
        ExitCode = $code
        Raw      = $text
        Json     = $obj
    }
}

# --- run ---
$iter = 0
$stopReason = "max_steps_reached"
$lastActionKind = ""
$lastBrowserStatus = ""
$lastBrowserMessage = ""
$inboxWrittenLastStep = $false
$anyInboxWritten = $false
$finalState = $null

Write-Host ""
Write-Host "======== FoodKing run_auto_cycle (max $MaxSteps steps) ========" -ForegroundColor Cyan
Write-Host "Repo: $RepoRoot" -ForegroundColor Gray
Write-Host ""

while ($iter -lt $MaxSteps) {
    $iter++
    Write-Host "--- Iteration $iter / $MaxSteps ---" -ForegroundColor DarkCyan

    $finalState = Get-CycleState
    $fsm = Test-FsmShouldStop $finalState
    if ($fsm.Stop) {
        $stopReason = $fsm.Reason
        break
    }

    $code = Invoke-BotCli @("browser-bridge-prepare")
    if ($code -ne 0) {
        $stopReason = "browser_bridge_prepare_failed_exit_$code"
        break
    }

    $code = Invoke-BotCli @("browser-bridge-next-action")
    if ($code -ne 0) {
        $stopReason = "browser_bridge_next_action_failed_exit_$code"
        break
    }

    $na = Get-NextActionFromDisk
    $nas = Test-NextActionShouldStop $na
    $lastActionKind = if ($na) { "$($na.action_kind)" } else { "" }

    if ($nas.Stop) {
        $stopReason = $nas.Reason
        break
    }

    if (Test-HasBridgeBlockers $na) {
        $bl = @($na.blockers | ForEach-Object { "$_" }) -join "; "
        $stopReason = "bridge_blockers:$bl"
        break
    }

    $ak = "$($na.action_kind)"
    if ($ak -in @("claude_project_browser", "cursor_agent")) {
        Write-Host "  browser-run-step ($ak)..." -ForegroundColor Yellow
        $br = Invoke-BrowserRunStepJson
        $j = $br.Json
        if ($null -eq $j) {
            $stopReason = "browser_run_step_non_json_stdout"
            if ($br.Raw) {
                $lastBrowserMessage = $br.Raw.Substring(0, [Math]::Min(400, $br.Raw.Length))
            }
            break
        }
        $lastBrowserStatus = "$($j.status)"
        $lastBrowserMessage = "$($j.message)"
        $inboxWrittenLastStep = $false
        if ($j.PSObject.Properties.Name -contains "wrote" -and $j.wrote) {
            $inboxWrittenLastStep = $true
            $anyInboxWritten = $true
        }

        if ($j.ok -ne $true) {
            $stopReason = "browser_run_step_failed:$($j.status)"
            break
        }
        if ("$($j.status)" -eq "human_must_complete_cursor") {
            $stopReason = "cursor_clipboard_human_required"
            break
        }
    } else {
        $inboxWrittenLastStep = $false
        Write-Host "  (skip browser-run-step: action_kind=$ak)" -ForegroundColor DarkGray
    }

    Write-Host "  run-supervisor-once..." -ForegroundColor Yellow
    $code = Invoke-BotCli @("run-supervisor-once")
    if ($code -ne 0) {
        $stopReason = "run_supervisor_once_failed_exit_$code"
        break
    }

    $finalState = Get-CycleState
    $fsm2 = Test-FsmShouldStop $finalState
    if ($fsm2.Stop) {
        $stopReason = $fsm2.Reason
        break
    }
}

# --- summary ---
Write-Host ""
Write-Host "======== Résumé opérateur ========" -ForegroundColor Cyan
Write-Host "Stop reason     : $stopReason" -ForegroundColor $(if ($stopReason -match "fsm_completed") { "Green" } else { "Yellow" })
if ($finalState) {
    Write-Host "FSM state       : $($finalState.state)" -ForegroundColor White
    Write-Host "Claude round    : $($finalState.claude_round)" -ForegroundColor White
    Write-Host "Cycle ID        : $($finalState.cycle_id)" -ForegroundColor White
} else {
    Write-Host "FSM state       : (unreadable)" -ForegroundColor Red
}
Write-Host "Last action_kind: $lastActionKind" -ForegroundColor White
Write-Host "Inbox written   : $(if ($anyInboxWritten) { 'yes (at least once)' } else { 'no' })" -ForegroundColor White
if ($lastBrowserStatus) {
    Write-Host "Last browser status : $lastBrowserStatus" -ForegroundColor Gray
}
if ($lastBrowserMessage) {
    $short = $lastBrowserMessage
    if ($short.Length -gt 200) { $short = $short.Substring(0, 200) + "..." }
    Write-Host "Last browser message: $short" -ForegroundColor Gray
}
Write-Host "Iterations used : $iter" -ForegroundColor White
Write-Host ""

$summary = [ordered]@{
    schema_version      = 1
    stop_reason         = $stopReason
    iterations_used     = $iter
    max_steps           = $MaxSteps
    final_fsm_state     = if ($finalState) { "$($finalState.state)" } else { $null }
    final_claude_round  = if ($finalState) { "$($finalState.claude_round)" } else { $null }
    final_cycle_id      = if ($finalState) { "$($finalState.cycle_id)" } else { $null }
    last_action_kind    = $lastActionKind
    any_inbox_written   = $anyInboxWritten
    inbox_written_last  = $inboxWrittenLastStep
    last_browser_status = $lastBrowserStatus
    last_browser_message = $lastBrowserMessage
}
Write-Host "---- JSON (machine-readable) ----" -ForegroundColor DarkGray
($summary | ConvertTo-Json -Depth 4 -Compress)

# Code sortie : 0 = arrêt attendu (humain ou FSM nominal), 1 = erreur / garde-fou / échec technique
$exit0Reasons = @(
    "fsm_completed",
    "fsm_blocked",
    "fsm_manual_gate",
    "fsm_waiting_playwright",
    "fsm_idle",
    "fsm_preparing_intake",
    "action_kind_terminal_or_idle",
    "action_kind_playwright_pending",
    "action_kind_local_validation_human_ci",
    "action_kind_unknown_fsm_wait",
    "action_kind_noop",
    "cursor_clipboard_human_required",
    "bridge_paused",
    "action_kind_paused"
)
if ($exit0Reasons -contains $stopReason) {
    exit 0
}
exit 1
