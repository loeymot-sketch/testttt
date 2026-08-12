#requires -Version 3.0
<#
  LE CAYENNE — Installe le pont d'impression CAISSE en VRAI service Windows (NSSM).
  [FIX-FLASH 2026-07-07]

  POURQUOI UN SERVICE (et pas une tache planifiee) :
    - Un service Windows tourne en SESSION 0 (session des services), qui n'a AUCUN
      bureau interactif -> rien n'y est jamais dessine -> 0 fenetre, 0 flash, STRUCTUREL.
    - NSSM garde le process VIVANT avec un redemarrage NATIF (AppExit Default Restart).
      => On SUPPRIME la tache planifiee "relance node toutes les 1 min" qui, elle,
         faisait apparaitre une console a chaque relance (= le flash recurrent).
    - Le pont caisse spawn deja son worker winspool avec windowsHide:true ; ce service
      elimine le DERNIER flash restant, celui du LANCEMENT de node lui-meme.

  PRE-REQUIS :
    - Lancer ce script en ADMINISTRATEUR (un service Windows exige des droits admin).
    - NSSM present (https://nssm.cc/download) : nssm.exe dans le PATH, ou -NssmPath.
    - Node.js installe (node.exe). Passer -NodePath si absent du PATH.
    - Connaitre le NOM EXACT de l'imprimante -> `Get-Printer | Select-Object Name`
      (Panneau de config -> Peripheriques et imprimantes donne le meme nom).
      [CHANGEMENT-IMPRIMANTE 2026-08-09] La SAGA a ete remplacee par une Epson TM-m30II.
      Le pilote Epson cree parfois la file sous "EPSON TM-m30II Receipt" : recopier la
      chaine EXACTE renvoyee par Get-Printer, au caractere pres (un nom errone n'echoue
      PAS bruyamment, il empile les tickets dans une file morte).

  EXEMPLE :
    powershell -ExecutionPolicy Bypass -File install-caisse-service.ps1 `
      -BridgePath "C:\caisse-bridge\caisse-bridge.js" -Printer "Epson TM-m30II"

  DESINSTALLER :  nssm stop FoodKingCaisseBridge ; nssm remove FoodKingCaisseBridge confirm

  NOTE IMPRIMANTE : un service tourne par defaut sous LocalSystem. Si l'imprimante est
  installee "par utilisateur" (et non pour "tous les utilisateurs"), le service peut ne
  PAS la voir. Deux options alors :
    a) Installer l'imprimante pour TOUS les utilisateurs (recommande caisse), OU
    b) Faire tourner le service sous le compte de l'utilisateur caisse :
         nssm set FoodKingCaisseBridge ObjectName ".\NOM_UTILISATEUR" "MOT_DE_PASSE"
    c) A defaut, utiliser le lanceur VBS cache (start-caisse-bridge-hidden.vbs), qui
       tourne dans la session utilisateur (voit les imprimantes user) — 0 flash aussi.
#>
[CmdletBinding()]
param(
  [string]$ServiceName = "FoodKingCaisseBridge",
  [string]$BridgePath  = "C:\caisse-bridge\caisse-bridge.js",
  [string]$Printer     = "Epson TM-m30II",
  [string]$NodePath    = "node",
  [string]$NssmPath    = "nssm",
  [string]$LogDir      = "C:\caisse-bridge\logs"
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
if (-not (Test-Path $BridgePath)) { Write-Error "caisse-bridge.js introuvable : $BridgePath (passe -BridgePath)."; exit 1 }
$BridgePath = (Resolve-Path $BridgePath).Path
$appDir = Split-Path -Parent $BridgePath
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

Write-Host "NSSM     : $nssm"
Write-Host "Node     : $node"
Write-Host "Bridge   : $BridgePath"
Write-Host "Imprimante : $Printer"

# 3. Reinstallation propre (supprime un ancien service homonyme s'il existe)
& $nssm stop   $ServiceName 2>$null | Out-Null
& $nssm remove $ServiceName confirm 2>$null | Out-Null

# 4. Installer + configurer (le nom d'imprimante est passe en argument au pont)
& $nssm install $ServiceName $node
& $nssm set $ServiceName AppParameters "`"$BridgePath`" `"$Printer`""
& $nssm set $ServiceName AppDirectory $appDir
& $nssm set $ServiceName DisplayName "FoodKing - Pont impression CAISSE (Le Cayenne)"
& $nssm set $ServiceName Description "Pont ESC/POS local caisse (winspool RAW). Service cache session 0, sans fenetre, redemarrage auto."
& $nssm set $ServiceName Start SERVICE_AUTO_START
# Redemarrage NATIF si le process meurt (remplace le schtasks "toutes les 1 min").
& $nssm set $ServiceName AppExit Default Restart
& $nssm set $ServiceName AppRestartDelay 3000
& $nssm set $ServiceName AppStdout (Join-Path $LogDir "caisse-bridge.out.log")
& $nssm set $ServiceName AppStderr (Join-Path $LogDir "caisse-bridge.err.log")
& $nssm set $ServiceName AppRotateFiles 1
& $nssm set $ServiceName AppRotateBytes 1048576

# 5. Demarrer + statut
& $nssm start $ServiceName
Start-Sleep -Seconds 2
& $nssm status $ServiceName

Write-Host ""
Write-Host "OK - le pont CAISSE tourne en SERVICE (session 0, invisible), redemarrage auto natif."
Write-Host "Verifier : ouvrir http://127.0.0.1:9100/health -> doit repondre UP."
Write-Host ""
Write-Host "IMPORTANT - supprime l'ancien lanceur qui causait le flash, par ex. :"
Write-Host "   schtasks /Delete /TN \"LeCayenne-CaissePont\" /F"
Write-Host "   (et tout .bat / VBS de demarrage qui lancait deja node caisse-bridge.js)"
