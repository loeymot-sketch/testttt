#requires -Version 3.0
<#
  LE CAYENNE — Installe le pont d'impression BORNE en VRAI service Windows (NSSM).
  [FIX-FLASH 2026-07-07]

  POURQUOI UN SERVICE (et pas une tache planifiee) :
    - Un service Windows tourne en SESSION 0 (session des services), qui n'a AUCUN
      bureau interactif -> rien n'y est jamais dessine -> 0 fenetre, 0 flash, STRUCTUREL.
    - NSSM garde le process VIVANT avec un redemarrage NATIF (AppExit Default Restart).
      => On SUPPRIME la tache planifiee "relance node toutes les 1 min" qui, elle,
         faisait apparaitre une console a chaque relance (= le flash recurrent).

  PRE-REQUIS :
    - Lancer ce script en ADMINISTRATEUR (un service Windows exige des droits admin).
    - NSSM present (https://nssm.cc/download) : mettre nssm.exe dans le PATH, ou
      passer -NssmPath "C:\nssm\win64\nssm.exe".
    - Node.js installe (node.exe). Passer -NodePath si absent du PATH.

  EXEMPLE :
    powershell -ExecutionPolicy Bypass -File install-borne-service.ps1 `
      -BridgePath "C:\borne-print\bridge.js"

  DESINSTALLER :  nssm stop FoodKingBorneBridge ; nssm remove FoodKingBorneBridge confirm

  NOTE USB : node-usb accede au SK1-31 au niveau kernel (WinUSB) -> fonctionne depuis
  un service (session 0). Si jamais le service ne "voit" pas l'imprimante USB sur une
  machine particuliere, utiliser a la place le lanceur VBS cache
  (start-borne-bridge-hidden.vbs), qui tourne dans la session utilisateur — 0 flash aussi.
#>
[CmdletBinding()]
param(
  [string]$ServiceName = "FoodKingBorneBridge",
  [string]$BridgePath  = "C:\borne-print\bridge.js",
  [string]$NodePath    = "node",
  [string]$NssmPath    = "nssm",
  [string]$LogDir      = "C:\borne-print\logs"
)

$ErrorActionPreference = "Stop"

function Resolve-Exe([string]$name) {
  $cmd = Get-Command $name -ErrorAction SilentlyContinue
  if ($cmd -and $cmd.Source) { return $cmd.Source }
  if (Test-Path $name) { return (Resolve-Path $name).Path }
  return $null
}

# 1. Droits admin obligatoires
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltinRole]::Administrator)) {
  Write-Error "Lance ce script en ADMINISTRATEUR (clic droit -> Executer en tant qu'administrateur)."
  exit 1
}

# 2. Resoudre nssm / node / bridge.js
$nssm = Resolve-Exe $NssmPath
if (-not $nssm) { Write-Error "nssm.exe introuvable. Telecharge NSSM (https://nssm.cc) puis passe -NssmPath."; exit 1 }
$node = Resolve-Exe $NodePath
if (-not $node) { Write-Error "node.exe introuvable. Installe Node.js ou passe -NodePath."; exit 1 }
if (-not (Test-Path $BridgePath)) { Write-Error "bridge.js introuvable : $BridgePath (passe -BridgePath)."; exit 1 }
$BridgePath = (Resolve-Path $BridgePath).Path
$appDir = Split-Path -Parent $BridgePath
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

Write-Host "NSSM   : $nssm"
Write-Host "Node   : $node"
Write-Host "Bridge : $BridgePath"

# 3. Reinstallation propre (supprime un ancien service homonyme s'il existe)
& $nssm stop   $ServiceName 2>$null | Out-Null
& $nssm remove $ServiceName confirm 2>$null | Out-Null

# 4. Installer + configurer
& $nssm install $ServiceName $node
& $nssm set $ServiceName AppParameters "`"$BridgePath`""
& $nssm set $ServiceName AppDirectory $appDir
& $nssm set $ServiceName DisplayName "FoodKing - Pont impression BORNE (Le Cayenne)"
& $nssm set $ServiceName Description "Pont ESC/POS local borne (node-usb). Service cache session 0, sans fenetre, redemarrage auto."
& $nssm set $ServiceName Start SERVICE_AUTO_START
# Redemarrage NATIF si le process meurt (remplace le schtasks "toutes les 1 min").
& $nssm set $ServiceName AppExit Default Restart
& $nssm set $ServiceName AppRestartDelay 3000
& $nssm set $ServiceName AppStdout (Join-Path $LogDir "borne-bridge.out.log")
& $nssm set $ServiceName AppStderr (Join-Path $LogDir "borne-bridge.err.log")
& $nssm set $ServiceName AppRotateFiles 1
& $nssm set $ServiceName AppRotateBytes 1048576

# 5. Demarrer + statut
& $nssm start $ServiceName
Start-Sleep -Seconds 2
& $nssm status $ServiceName

Write-Host ""
Write-Host "OK - le pont BORNE tourne en SERVICE (session 0, invisible), redemarrage auto natif."
Write-Host "Verifier : ouvrir http://127.0.0.1:9100/health -> doit repondre UP."
Write-Host ""
Write-Host "IMPORTANT - supprime l'ancien lanceur qui causait le flash, par ex. :"
Write-Host "   schtasks /Delete /TN \"LeCayenne-BornePont\" /F"
Write-Host "   (et tout .bat / VBS de demarrage qui lancait deja node bridge.js)"
