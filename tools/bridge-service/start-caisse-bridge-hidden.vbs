' ============================================================================
'  LE CAYENNE — Lanceur CACHE du pont d'impression CAISSE (imprimante SAGA / winspool)
'  [FIX-FLASH 2026-07-07]  ZERO fenetre, ZERO flash — meme au demarrage.
' ----------------------------------------------------------------------------
'  POURQUOI CE FICHIER : lancer "node caisse-bridge.js" depuis une tache planifiee,
'  un .bat, ou "powershell -WindowStyle Hidden" ouvre (ne serait-ce qu'une fraction
'  de seconde) une fenetre console => le "flash de terminal" que voit l'owner.
'  Ce VBS lance node avec le mode fenetre 0 (SW_HIDE) : le process est cree DEJA
'  cache, donc la console n'est JAMAIS dessinee. Le pont lui-meme n'ouvre deja plus
'  de fenetre (son worker PowerShell winspool est spawn avec windowsHide:true) ;
'  ce lanceur elimine le DERNIER flash restant, celui du LANCEMENT de node.
'
'  UTILISATION :
'    1. Placer ce .vbs A COTE de caisse-bridge.js sur le PC caisse.
'    2. Regler PRINTER_NAME ci-dessous = NOM EXACT de l'imprimante (Panneau de
'       configuration -> Peripheriques et imprimantes), ex "Epson TM-m30II".
'    3. Double-clic pour lancer maintenant (rien ne s'affiche = c'est NORMAL).
'    4. Demarrage auto : Win+R -> shell:startup -> y deposer un RACCOURCI de ce .vbs.
'    5. SUPPRIMER tout ancien lanceur qui relance node en boucle (tache planifiee
'       "toutes les 1 min", .bat, watchdog powershell non-VBS) — c'est LUI le flash.
'
'  Si "node" n'est pas dans le PATH, mettre le chemin complet dans NODE_EXE.
' ============================================================================
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, printerName, cmd

Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")

' Dossier de CE .vbs ; caisse-bridge.js est suppose a cote. Sinon, chemin absolu.
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\caisse-bridge.js"

' "node" si Node est dans le PATH ; sinon chemin complet, ex :
'   nodeExe = "C:\Program Files\nodejs\node.exe"
nodeExe = "node"

' NOM EXACT de l'imprimante caisse (a adapter) — passe en argument au pont.
' [CHANGEMENT-IMPRIMANTE 2026-08-09] SAGA remplacee par une Epson TM-m30II.
' Verifier le nom AU CARACTERE PRES avec :  Get-Printer | Select-Object Name
' (le pilote Epson cree parfois "EPSON TM-m30II Receipt" et non "Epson TM-m30II").
printerName = "Epson TM-m30II"

' Guillemets autour des chemins + du nom d'imprimante (espaces eventuels).
cmd = """" & nodeExe & """ """ & bridgeJs & """ """ & printerName & """"

' Run(commande, style, attendre) : style 0 = fenetre CACHEE ; False = ne pas
' attendre (le pont tourne en continu, un seul process persistant).
shell.Run cmd, 0, False
