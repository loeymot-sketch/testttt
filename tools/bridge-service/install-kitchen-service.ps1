#requires -Version 3.0
<#
  LE CAYENNE — Installe le pont d'impression CUISINE en VRAI service Windows (NSSM).
  [KITCHEN-AUTOSTART 2026-08-09]

  POURQUOI CE FICHIER EXISTE :
    Le pont CUISINE (port 9101) etait le SEUL des trois ponts sans artefact de
    demarrage automatique (la caisse et la borne en avaient un depuis le 07/07).
    Consequence terrain : apres un redemarrage du PC cuisine, le pont restait MORT
    et plus aucun ticket ne sortait — en SILENCE, car le KDS continue d'afficher
    les commandes a l'ecran. Ce script ferme ce trou.

  POURQUOI UN SERVICE (et pas une tache planifiee) :
    - Un service Windows tourne en SESSION 0 (session des services), qui n'a AUCUN
      bureau interactif -> rien n'y est jamais dessine -> 0 fenetre, 0 flash, STRUCTUREL.
    - NSSM garde le process VIVANT avec un redemarrage NATIF (AppExit Default Restart).
    - Le pont cuisine spawn deja son worker winspool avec windowsHide:true ; ce service
      elimine le DERNIER flash restant, celui du LANCEMENT de node lui-meme.

  PRE-REQUIS :
    - Lancer ce script en ADMINISTRATEUR (un service Windows exige des droits admin).
    - NSSM present (https://nssm.cc/download) : nssm.exe dans le PATH, ou -NssmPath.
    - Node.js installe (node.exe). Passer -NodePath si absent du PATH.
    - Connaitre le NOM EXACT de l'imprimante -> `Get-Printer | Select-Object Name`
      Le pilote Epson cree parfois la file sous "EPSON TM-m30II Receipt" : recopier la
      chaine EXACTE, au caractere pres (un nom errone n'echoue PAS bruyamment, il
      empile les tickets dans une file morte — exactement l'incident SAGA du 09/08).

  EXEMPLE :
    powershell -ExecutionPolicy Bypass -File install-kitchen-service.ps1 `
      -BridgePath "C:\kitchen-bridge\kitchen-bridge.js" -Printer "Epson TM-m30 Cuisine"

  DESINSTALLER :  nssm stop FoodKingCuisineBridge ; nssm remove FoodKingCuisineBridge confirm

  NOTE IMPRIMANTE : un service tourne par defaut sous LocalSystem. Si l'imprimante est
  installee "par utilisateur" (et non pour "tous les utilisateurs"), le service peut ne
  PAS la voir. Deux options alors :
    a) Installer l'imprimante pour TOUS les utilisateurs (recommande cuisine), OU
    b) Faire tourner le service sous le compte de l'utilisateur cuisine :
         nssm set FoodKingCuisineBridge ObjectName ".\NOM_UTILISATEUR" "MOT_DE_PASSE"
    c) A defaut, utiliser le lanceur VBS cache (start-kitchen-bridge-hidden.vbs), qui
       tourne dans la session utilisateur (voit les imprimantes user) — 0 flash aussi.
#>
[CmdletBinding()]
param(
  [string]$ServiceName = "FoodKingCuisineBridge",
  [string]$BridgePath  = "C:\kitchen-bridge\kitchen-bridge.js",
  [string]$Printer     = "Epson TM-m30 Cuisine",
  [int]   $Port        = 9101,
  [string]$NodePath    = "node",
  [string]$NssmPath    = "nssm",
  [string]$LogDir      = "C:\kitchen-bridge\logs"
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
if (-not (Test-Path $BridgePath)) { Write-Error "kitchen-bridge.js introuvable : $BridgePath (passe -BridgePath)."; exit 1 }
$BridgePath = (Resolve-Path $BridgePath).Path
$appDir = Split-Path -Parent $BridgePath
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

# 3. Verifier que l'imprimante demandee EXISTE vraiment (garde anti-"file morte")
#    Une file inexistante ne fait PAS echouer bruyamment le pont : les tickets s'empilent
#    dans le vide, en silence. C'est l'incident SAGA du 09/08 — on le bloque ici.
#    NB : si Get-Printer est indisponible (module absent, poste verrouille), on ne peut
#    RIEN affirmer -> on avertit mais on N'EMPECHE PAS l'installation, sinon ce garde-fou
#    devient lui-meme un blocage injustifie.
$known = @(Get-Printer -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Name)
if ($known.Count -eq 0) {
  Write-Warning "Get-Printer n'a renvoye AUCUNE imprimante (module absent ou droits insuffisants)."
  Write-Warning "Impossible de verifier '$Printer' -> installation poursuivie SANS garantie."
  Write-Warning "A VERIFIER A LA MAIN : un ticket doit sortir au test d'acceptation."
}
elseif ($known -notcontains $Printer) {
  Write-Warning "L'imprimante '$Printer' n'apparait PAS dans Get-Printer."
  Write-Warning "Imprimantes installees sur ce poste :"
  $known | ForEach-Object { Write-Warning "   - $_" }
  Write-Error  "Recopie le nom EXACT ci-dessus dans -Printer, sinon les tickets partiront dans le vide."
  exit 1
}
else {
  # Aligner la CASSE exacte relevee par Get-Printer (le nom saisi peut differer en casse).
  $Printer = $known | Where-Object { $_ -eq $Printer } | Select-Object -First 1
}

Write-Host "NSSM       : $nssm"
Write-Host "Node       : $node"
Write-Host "Bridge     : $BridgePath"
Write-Host "Imprimante : $Printer  (verifiee presente)"
Write-Host "Port       : $Port"

# 4. Reinstallation propre (supprime un ancien service homonyme s'il existe)
& $nssm stop   $ServiceName 2>$null | Out-Null
& $nssm remove $ServiceName confirm 2>$null | Out-Null

# 5. Installer + configurer (le nom d'imprimante est passe en argument au pont)
& $nssm install $ServiceName $node
& $nssm set $ServiceName AppParameters "`"$BridgePath`" `"$Printer`""
& $nssm set $ServiceName AppDirectory $appDir
& $nssm set $ServiceName AppEnvironmentExtra "KITCHEN_BRIDGE_PORT=$Port"
& $nssm set $ServiceName DisplayName "FoodKing - Pont impression CUISINE (Le Cayenne)"
& $nssm set $ServiceName Description "Pont ESC/POS local cuisine (winspool RAW, port $Port). Service cache session 0, sans fenetre, redemarrage auto."
& $nssm set $ServiceName Start SERVICE_AUTO_START
# Redemarrage NATIF si le process meurt (remplace tout schtasks "toutes les 1 min").
& $nssm set $ServiceName AppExit Default Restart
& $nssm set $ServiceName AppRestartDelay 3000
& $nssm set $ServiceName AppStdout (Join-Path $LogDir "kitchen-bridge.out.log")
& $nssm set $ServiceName AppStderr (Join-Path $LogDir "kitchen-bridge.err.log")
& $nssm set $ServiceName AppRotateFiles 1
& $nssm set $ServiceName AppRotateBytes 1048576

# 6. Demarrer + statut
& $nssm start $ServiceName
Start-Sleep -Seconds 2
& $nssm status $ServiceName

Write-Host ""
Write-Host "OK - le pont CUISINE tourne en SERVICE (session 0, invisible), redemarrage auto natif."
Write-Host "Verifier : ouvrir http://127.0.0.1:$Port/health -> doit repondre UP."
Write-Host ""
Write-Host "RAPPEL - Chrome/KDS doit tourner avec le flag reseau local, sinon le navigateur"
Write-Host "         REFUSE d'appeler 127.0.0.1 depuis une page HTTPS :"
Write-Host "   chrome.exe --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks"
