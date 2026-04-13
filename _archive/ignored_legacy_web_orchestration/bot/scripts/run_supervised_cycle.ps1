<#
.SYNOPSIS
  Guided supervised cycle runner for FoodKing bot.

.DESCRIPTION
  Interactive step-by-step script that walks the operator through a full
  bot cycle using the inbox/outbox supervisor model.

  No browser, no APIs, no Telegram, no Git, no daemon.
  One cycle at a time. Human confirms every transition.

.USAGE
  From repo root:
    .\bot\scripts\run_supervised_cycle.ps1
#>

$ErrorActionPreference = "Continue"
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location -LiteralPath $RepoRoot

# -- Paths --
$BotCli = Join-Path $RepoRoot "bot-cli.ps1"
if (-not (Test-Path -LiteralPath $BotCli)) {
    Write-Host "FATAL: bot-cli.ps1 not found at $BotCli" -ForegroundColor Red
    exit 1
}

$InboxClaudePlan   = Join-Path $RepoRoot "bot\inbox\claude_plan"
$InboxClaudeReview = Join-Path $RepoRoot "bot\inbox\claude_review"
$InboxCursorResult = Join-Path $RepoRoot "bot\inbox\cursor_result"
$OutboxClaude      = Join-Path $RepoRoot "bot\outbox\claude"
$OutboxCursor      = Join-Path $RepoRoot "bot\outbox\cursor"

# -- Helpers --
function Write-Phase {
    param([string]$Phase, [string]$Detail)
    Write-Host ""
    Write-Host "===== $Phase =====" -ForegroundColor Cyan
    if ($Detail) { Write-Host $Detail -ForegroundColor Gray }
    Write-Host ""
}

function Write-Action {
    param([string]$Msg)
    Write-Host "  >> $Msg" -ForegroundColor Yellow
}

function Write-FilePath {
    param([string]$Label, [string]$FilePath)
    Write-Host "     $Label : $FilePath" -ForegroundColor Green
}

function Write-Ok {
    param([string]$Msg)
    Write-Host "  OK: $Msg" -ForegroundColor Green
}

function Write-Err {
    param([string]$Msg)
    Write-Host "  ERROR: $Msg" -ForegroundColor Red
}

function Invoke-BotCli {
    param([string[]]$CliArgs)
    & $BotCli $CliArgs 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Err "bot-cli.ps1 $($CliArgs -join ' ') failed (exit $LASTEXITCODE)"
        return $false
    }
    return $true
}

function Get-CycleState {
    $raw = & $BotCli show-state 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $raw) { return $null }
    try {
        $joined = $raw -join "`n"
        return $joined | ConvertFrom-Json
    } catch {
        return $null
    }
}

function Wait-ForEnter {
    param([string]$Msg = "Press ENTER when ready...")
    Write-Host ""
    Write-Host "  $Msg" -ForegroundColor Magenta
    Read-Host | Out-Null
}

function Run-SupervisorTick {
    Write-Host "  Running supervisor tick..." -ForegroundColor DarkGray
    $tickOut = & $BotCli run-supervisor-once 2>&1
    $tickCode = $LASTEXITCODE
    if ($tickOut) {
        $tickOut | ForEach-Object {
            $line = "$_"
            if ($line -match "ERROR|error|failed") {
                Write-Host "    $_" -ForegroundColor Red
            } else {
                Write-Host "    $_" -ForegroundColor DarkGray
            }
        }
    }
    return $tickCode
}

function Test-StateChanged {
    param($Before, $After)
    if (-not $Before -or -not $After) { return $true }
    if ($Before.state -ne $After.state) { return $true }
    if ($Before.claude_round -ne $After.claude_round) { return $true }
    if ($Before.updated_at -ne $After.updated_at) { return $true }
    return $false
}

function Wait-ForInboxFile {
    param(
        [string]$InboxPath,
        [string]$Description,
        [string]$FilePattern = "*.json"
    )
    $found = $false
    while (-not $found) {
        Wait-ForEnter "Press ENTER after you saved the $Description into the inbox..."

        if (Test-Path $InboxPath) {
            $files = Get-ChildItem -Path $InboxPath -Filter $FilePattern -File -ErrorAction SilentlyContinue
            if ($files -and $files.Count -gt 0) {
                $found = $true
            }
        }

        if (-not $found) {
            Write-Host "  No $FilePattern file found in: $InboxPath" -ForegroundColor Yellow
            Write-Host "  Make sure you saved the file there before pressing ENTER." -ForegroundColor Yellow
        }
    }
}

function Invoke-TickUntilTransition {
    param([string]$ErrorHint)

    $stateBefore = Get-CycleState
    $maxRetries = 5
    $attempt = 0

    while ($attempt -lt $maxRetries) {
        $attempt++
        $tickResult = Run-SupervisorTick
        $stateAfter = Get-CycleState

        if ($tickResult -ne 0) {
            Write-Err "Supervisor error (exit $tickResult). $ErrorHint"
            Wait-ForEnter "Fix the file and press ENTER to retry..."
            continue
        }

        if (Test-StateChanged $stateBefore $stateAfter) {
            return $true
        }

        Write-Host "  State unchanged -- supervisor found nothing to process." -ForegroundColor Yellow
        Write-Host "  Check that your file is in the right inbox folder with correct format." -ForegroundColor Yellow
        Wait-ForEnter "Fix and press ENTER to retry..."
    }

    Write-Err "Failed after $maxRetries attempts. Run .\bot-cli.ps1 show-state to diagnose."
    return $false
}

# ============================================================================
# STEP 1 -- Cycle setup
# ============================================================================
Write-Phase "STEP 1: CYCLE SETUP" "Configure a new cycle with task-id and goal."

$currentState = Get-CycleState
if ($currentState -and $currentState.state -notin @("idle", "completed")) {
    Write-Host "  Active cycle detected: state=$($currentState.state), cycle_id=$($currentState.cycle_id)" -ForegroundColor Yellow
    $choice = Read-Host "  Reset to idle and start fresh? (y/N)"
    if ($choice -ne "y") {
        Write-Host "  Aborting. Resolve the active cycle first." -ForegroundColor Red
        exit 0
    }
    Invoke-BotCli @("reset-idle") | Out-Null
    Write-Ok "Reset to idle."
}

$taskId = Read-Host "  Task ID (e.g. T-001)"
if (-not $taskId.Trim()) {
    Write-Err "Task ID is required."; exit 1
}

$goal = Read-Host "  Goal (one line describing the objective)"
if (-not $goal.Trim()) {
    Write-Err "Goal is required."; exit 1
}

Write-Host "  Resetting to idle..." -ForegroundColor DarkGray
Invoke-BotCli @("reset-idle") | Out-Null

Write-Host "  Starting cycle..." -ForegroundColor DarkGray
& $BotCli begin-cycle --task-id $taskId --goal $goal --trigger human 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Err "begin-cycle failed (exit $LASTEXITCODE). Run: .\bot-cli.ps1 show-state"
    exit 1
}

$state = Get-CycleState
if (-not $state -or -not $state.cycle_id) {
    Write-Err "Failed to read cycle state after begin-cycle."; exit 1
}

$cycleId = $state.cycle_id
Write-Ok "Cycle started: $cycleId"
Write-Host "  State: $($state.state) | Claude round: $($state.claude_round)" -ForegroundColor Gray

# ============================================================================
# MAIN LOOP
# ============================================================================
$loopCount = 0
$maxLoops = 50

while ($loopCount -lt $maxLoops) {
    $loopCount++
    $state = Get-CycleState
    if (-not $state) {
        Write-Err "Cannot read cycle state."; exit 1
    }

    $phase = $state.state
    $round = $state.claude_round

    # -- Terminal states --
    if ($phase -eq "completed") {
        Write-Phase "CYCLE COMPLETED" "The cycle has been approved and completed."
        Write-Ok "Cycle $cycleId finished successfully."
        Write-Host "  Verdict: APPROVED" -ForegroundColor Green
        Write-Host ""
        Write-Host "  Next steps:" -ForegroundColor Gray
        Write-Host "    1. Review cycle artifacts:  .\bot-cli.ps1 show-cycle-files" -ForegroundColor Gray
        Write-Host "    2. Update MEMORY.md if needed" -ForegroundColor Gray
        Write-Host "    3. Start next cycle when ready" -ForegroundColor Gray
        exit 0
    }

    if ($phase -eq "blocked") {
        Write-Phase "CYCLE BLOCKED" "The cycle has been blocked."
        Write-Host "  Reason: $($state.block_reason)" -ForegroundColor Red
        Write-Host ""
        Write-Host "  To recover:" -ForegroundColor Gray
        Write-Host "    1. Fix the blocking issue" -ForegroundColor Gray
        Write-Host "    2. Run: .\bot-cli.ps1 reset-idle" -ForegroundColor Gray
        Write-Host "    3. Start a new cycle" -ForegroundColor Gray
        exit 1
    }

    if ($phase -eq "manual_gate") {
        Write-Phase "MANUAL GATE" "Human decision required before proceeding."
        Write-Host "  Reason: $($state.manual_gate_reason)" -ForegroundColor Yellow
        Write-Host ""
        Write-Host "  To continue after resolving:" -ForegroundColor Gray
        Write-Host "    1. Make the human decision" -ForegroundColor Gray
        Write-Host "    2. Run: .\bot-cli.ps1 reset-idle" -ForegroundColor Gray
        Write-Host "    3. Start a new cycle incorporating the decision" -ForegroundColor Gray
        exit 0
    }

    # -- Phase: waiting_claude (plan) --
    if ($phase -eq "waiting_claude" -and $round -eq "plan") {
        Write-Phase "PHASE: PLANNING (Claude)" "Claude must produce a plan for this cycle."

        Run-SupervisorTick | Out-Null

        $handoffFile = Join-Path $OutboxClaude "claude_handoff.md"
        if (Test-Path $handoffFile) {
            Write-Action "SEND THIS FILE to Claude (browser / Project conversation):"
            Write-FilePath "Handoff" $handoffFile
        } else {
            Write-Err "Handoff file not generated. Check bot state."
            exit 1
        }

        Write-Host ""
        Write-Host "  WHAT TO DO:" -ForegroundColor White
        Write-Host "    1. Open Claude (browser) in your FoodKing Project" -ForegroundColor Gray
        Write-Host "    2. Copy-paste the content of claude_handoff.md into the conversation" -ForegroundColor Gray
        Write-Host "    3. Claude will produce a plan JSON" -ForegroundColor Gray
        Write-Host "    4. Save Claude's JSON response as a .json file into:" -ForegroundColor Gray
        Write-FilePath "Inbox" $InboxClaudePlan
        Write-Host ""
        Write-Host "  JSON must contain:" -ForegroundColor Gray
        Write-Host "    - response_kind: `"plan`"" -ForegroundColor DarkGray
        Write-Host "    - cycle_id: `"$cycleId`"" -ForegroundColor DarkGray
        Write-Host "    - task_id: `"$taskId`"" -ForegroundColor DarkGray
        Write-Host "    - verdict: null" -ForegroundColor DarkGray
        Write-Host "    - suggested_next_actor: `"cursor_execute`"" -ForegroundColor DarkGray
        Write-Host ""
        Write-Host "  Template: bot\examples\plan_response.example.json" -ForegroundColor DarkGray

        Wait-ForInboxFile -InboxPath $InboxClaudePlan -Description "plan JSON"

        $ok = Invoke-TickUntilTransition -ErrorHint "Check cycle_id, response_kind, JSON syntax."
        if (-not $ok) { exit 1 }

        Write-Ok "Plan registered. Moving to next phase."
        continue
    }

    # -- Phase: waiting_cursor --
    if ($phase -eq "waiting_cursor") {
        Write-Phase "PHASE: EXECUTION (Cursor)" "Cursor must execute the plan."

        Run-SupervisorTick | Out-Null

        $cursorHandoff = Join-Path $OutboxCursor "cursor_handoff.md"
        if (Test-Path $cursorHandoff) {
            Write-Action "SEND THIS FILE to Cursor (paste into Cursor Agent conversation):"
            Write-FilePath "Handoff" $cursorHandoff
        } else {
            Write-Err "Cursor handoff file not generated."
            exit 1
        }

        Write-Host ""
        Write-Host "  WHAT TO DO:" -ForegroundColor White
        Write-Host "    1. Open Cursor IDE" -ForegroundColor Gray
        Write-Host "    2. Start a new Agent conversation (or continue the project one)" -ForegroundColor Gray
        Write-Host "    3. Copy-paste the content of cursor_handoff.md" -ForegroundColor Gray
        Write-Host "    4. Let Cursor implement the plan" -ForegroundColor Gray
        Write-Host "    5. When Cursor is done, save a cursor_done.json file into:" -ForegroundColor Gray
        Write-FilePath "Inbox" $InboxCursorResult
        Write-Host ""
        Write-Host "  cursor_done.json must contain:" -ForegroundColor Gray
        Write-Host "    {" -ForegroundColor DarkGray
        Write-Host "      `"kind`": `"cursor_done`"," -ForegroundColor DarkGray
        Write-Host "      `"cycle_id`": `"$cycleId`"," -ForegroundColor DarkGray
        Write-Host "      `"status`": `"done`"," -ForegroundColor DarkGray
        Write-Host "      `"files_changed`": [`"file1.php`", `"file2.vue`"]," -ForegroundColor DarkGray
        Write-Host "      `"summary`": `"Brief description of what was done`"" -ForegroundColor DarkGray
        Write-Host "    }" -ForegroundColor DarkGray

        Wait-ForInboxFile -InboxPath $InboxCursorResult -Description "cursor_done.json" -FilePattern "cursor_done.json"

        $ok = Invoke-TickUntilTransition -ErrorHint "Check kind='cursor_done', cycle_id, filename."
        if (-not $ok) { exit 1 }

        Write-Ok "Cursor execution registered. Moving to next phase."
        continue
    }

    # -- Phase: waiting_validation --
    if ($phase -eq "waiting_validation") {
        Write-Phase "PHASE: VALIDATION" "Local tests/linting must be run and reported."

        Write-Host "  WHAT TO DO:" -ForegroundColor White
        Write-Host "    1. Run the relevant tests locally:" -ForegroundColor Gray
        Write-Host "       - PHPUnit:  php artisan test" -ForegroundColor DarkGray
        Write-Host "       - Jest:     npm test" -ForegroundColor DarkGray
        Write-Host "       - Lint:     ./vendor/bin/phpcs / npx eslint" -ForegroundColor DarkGray
        Write-Host "    2. Save a validation.json file into:" -ForegroundColor Gray
        Write-FilePath "Inbox" $InboxCursorResult
        Write-Host ""
        Write-Host "  validation.json must contain:" -ForegroundColor Gray
        Write-Host "    {" -ForegroundColor DarkGray
        Write-Host "      `"kind`": `"validation_result`"," -ForegroundColor DarkGray
        Write-Host "      `"cycle_id`": `"$cycleId`"," -ForegroundColor DarkGray
        Write-Host "      `"status`": `"passed`"," -ForegroundColor DarkGray
        Write-Host "      `"detail`": `"PHPUnit 42 tests OK, eslint clean`"" -ForegroundColor DarkGray
        Write-Host "    }" -ForegroundColor DarkGray
        Write-Host ""
        Write-Host "  Valid status values: passed | failed | skipped" -ForegroundColor Gray
        Write-Host "  NOTE: 'failed' will BLOCK the cycle." -ForegroundColor Yellow

        Wait-ForInboxFile -InboxPath $InboxCursorResult -Description "validation.json" -FilePattern "validation.json"

        $ok = Invoke-TickUntilTransition -ErrorHint "Check kind='validation_result', status, cycle_id."
        if (-not $ok) { exit 1 }

        Write-Ok "Validation registered. Moving to next phase."
        continue
    }

    # -- Phase: waiting_claude (review) --
    if ($phase -eq "waiting_claude" -and $round -eq "review") {
        Write-Phase "PHASE: REVIEW (Claude)" "Claude must review the execution and produce a verdict."

        Run-SupervisorTick | Out-Null

        $reviewHandoff = Join-Path $OutboxClaude "claude_review_handoff.md"
        if (Test-Path $reviewHandoff) {
            Write-Action "SEND THIS FILE to Claude (same Project conversation):"
            Write-FilePath "Handoff" $reviewHandoff
        } else {
            Write-Err "Review handoff file not generated."
            exit 1
        }

        Write-Host ""
        Write-Host "  WHAT TO DO:" -ForegroundColor White
        Write-Host "    1. Go back to the same Claude conversation (FoodKing Project)" -ForegroundColor Gray
        Write-Host "    2. Copy-paste the content of claude_review_handoff.md" -ForegroundColor Gray
        Write-Host "    3. Claude will produce a review verdict JSON" -ForegroundColor Gray
        Write-Host "    4. Save Claude's JSON response as a .json file into:" -ForegroundColor Gray
        Write-FilePath "Inbox" $InboxClaudeReview
        Write-Host ""
        Write-Host "  JSON must contain:" -ForegroundColor Gray
        Write-Host "    - response_kind: `"review`"" -ForegroundColor DarkGray
        Write-Host "    - cycle_id: `"$cycleId`"" -ForegroundColor DarkGray
        Write-Host "    - verdict: one of `"APPROVED`" | `"NEEDS_FIX`" | `"NEEDS_PLAYWRIGHT`" | `"BLOCKED`" | `"MANUAL_GATE`"" -ForegroundColor DarkGray
        Write-Host ""
        Write-Host "  Template: bot\examples\review_response.example.json" -ForegroundColor DarkGray
        Write-Host ""
        Write-Host "  VERDICTS:" -ForegroundColor Gray
        Write-Host "    APPROVED        -> cycle completes successfully" -ForegroundColor DarkGreen
        Write-Host "    NEEDS_FIX       -> new cycle needed for corrections" -ForegroundColor DarkYellow
        Write-Host "    NEEDS_PLAYWRIGHT -> E2E testing required" -ForegroundColor DarkYellow
        Write-Host "    BLOCKED         -> hard stop, investigation needed" -ForegroundColor DarkRed
        Write-Host "    MANUAL_GATE     -> human decision required" -ForegroundColor DarkMagenta

        Wait-ForInboxFile -InboxPath $InboxClaudeReview -Description "review JSON"

        $ok = Invoke-TickUntilTransition -ErrorHint "Check response_kind='review', cycle_id, verdict."
        if (-not $ok) { exit 1 }

        Write-Ok "Review registered. Checking final state..."
        continue
    }

    # -- Unknown phase --
    Write-Phase "UNKNOWN PHASE" "state=$phase, claude_round=$round"
    Write-Err "The supervisor does not know how to handle this state."
    Write-Host "  Possible recovery:" -ForegroundColor Gray
    Write-Host "    .\bot-cli.ps1 show-state" -ForegroundColor Gray
    Write-Host "    .\bot-cli.ps1 reset-idle" -ForegroundColor Gray
    exit 1
}

Write-Err "Maximum loop iterations ($maxLoops) exceeded. Possible stuck cycle."
exit 1
