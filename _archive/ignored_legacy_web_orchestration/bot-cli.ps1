<#
.SYNOPSIS
  Lance la CLI du bot FoodKing avec le bon Python (Windows).

.DESCRIPTION
  Evite le faux "python.exe" du dossier WindowsApps (alias Store) en preferant:
  - la variable d'environnement FOODKING_PYTHON (chemin absolu vers python.exe)
  - un venv du depot: .venv\Scripts\python.exe
  - les installations utilisateur typiques: Python312, Python311, Python310
  - en dernier recours: "python" du PATH si sa source n'est PAS WindowsApps

  Usage (depuis la racine du repo):
    .\bot-cli.ps1 show-state
    .\bot-cli.ps1 begin-cycle --task-id T-1 --goal "..." --trigger human
#>
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$CliArgs
)

$ErrorActionPreference = "Stop"
$RepoRoot = $PSScriptRoot
Set-Location -LiteralPath $RepoRoot
$env:PYTHONPATH = $RepoRoot

function Test-RealPython {
    param([string]$ExePath)
    if (-not (Test-Path -LiteralPath $ExePath)) { return $false }
    try {
        $ver = & $ExePath "--version" 2>&1
        return ($LASTEXITCODE -eq 0) -and ($ver -match "Python \d")
    } catch {
        return $false
    }
}

function Find-PythonExe {
    if ($env:FOODKING_PYTHON) {
        $p = $env:FOODKING_PYTHON.Trim()
        if ($p -and (Test-RealPython $p)) { return $p }
    }

    $venvPy = Join-Path $RepoRoot ".venv\Scripts\python.exe"
    if (Test-RealPython $venvPy) { return $venvPy }

    $localBase = Join-Path $env:LOCALAPPDATA "Programs\Python"
    foreach ($dir in @("Python312", "Python311", "Python310")) {
        $p = Join-Path $localBase "$dir\python.exe"
        if (Test-RealPython $p) { return $p }
    }

    foreach ($dir in @("Python312", "Python311", "Python310")) {
        $candidates = @(
            (Join-Path ${env:ProgramFiles} "Python\$dir\python.exe"),
            (Join-Path ${env:ProgramFiles} "$dir\python.exe")
        )
        foreach ($p in $candidates) {
            if (Test-RealPython $p) { return $p }
        }
    }

    $cmd = Get-Command "python.exe" -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source -notmatch "WindowsApps") {
        if (Test-RealPython $cmd.Source) { return $cmd.Source }
    }

    return $null
}

$python = Find-PythonExe
if (-not $python) {
    Write-Host ""
    Write-Host "Python introuvable (installation reelle ou stub Microsoft Store)." -ForegroundColor Red
    Write-Host "Corrections possibles:" -ForegroundColor Yellow
    Write-Host "  1) winget install -e --id Python.Python.3.12"
    Write-Host "  2) Fermer puis rouvrir le terminal apres installation."
    Write-Host "  3) Desactiver les alias Store: Parametres > Applications > Alias d'execution des applications (python.exe)."
    Write-Host "  4) Definir FOODKING_PYTHON vers python.exe, ex.:"
    Write-Host '     $env:FOODKING_PYTHON = "$env:LOCALAPPDATA\Programs\Python\Python312\python.exe"'
    Write-Host ""
    exit 127
}

$cli = Join-Path $RepoRoot "bot\cli.py"
if (-not (Test-Path -LiteralPath $cli)) {
    Write-Error "Fichier introuvable: $cli"
    exit 2
}

$ErrorActionPreference = "Continue"
& $python $cli @CliArgs
exit $LASTEXITCODE
