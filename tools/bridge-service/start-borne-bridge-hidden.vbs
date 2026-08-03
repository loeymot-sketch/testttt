' ============================================================================
'  LE CAYENNE — Lanceur CACHE du pont d'impression BORNE (Sanei SK1-31 / node-usb)
'  [FIX-FLASH 2026-07-07]  ZERO fenetre, ZERO flash — meme au demarrage.
' ----------------------------------------------------------------------------
'  POURQUOI CE FICHIER : lancer "node bridge.js" depuis une tache planifiee, un
'  .bat, ou "powershell -WindowStyle Hidden" ouvre (ne serait-ce qu'une fraction
'  de seconde) une fenetre console => le "flash de terminal" que voit l'owner.
'  Ce VBS lance node avec le mode fenetre 0 (SW_HIDE) : le process est cree DEJA
'  cache, donc la console n'est JAMAIS dessinee. C'est le seul moyen fiable, cote
'  session utilisateur, d'avoir 0 flash (window.Run style 0 pose SW_HIDE au
'  CreateProcess, alors que -WindowStyle Hidden agit APRES la creation de conhost).
'
'  UTILISATION :
'    1. Placer ce .vbs A COTE de bridge.js (chemin reel constate : C:\borne-print\).
'    2. Double-clic pour lancer maintenant (rien ne s'affiche = c'est NORMAL, ca tourne).
'    3. Demarrage auto : Win+R -> shell:startup -> y deposer un RACCOURCI de ce .vbs.
'    4. SUPPRIMER tout ancien lanceur qui relance node en boucle (tache planifiee
'       "toutes les 1 min", .bat, watchdog powershell non-VBS) — c'est LUI le flash.
'
'  Si "node" n'est pas dans le PATH, mettre le chemin complet dans NODE_EXE.
' ============================================================================
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, cmd

Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")

' Dossier de CE .vbs ; bridge.js est suppose a cote. Sinon, remplacer la ligne
' bridgeJs par un chemin absolu, ex. bridgeJs = "C:\borne-print\bridge.js".
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\bridge.js"

' "node" si Node est dans le PATH ; sinon chemin complet, ex :
'   nodeExe = "C:\Program Files\nodejs\node.exe"
nodeExe = "node"

' Guillemets autour des chemins (espaces eventuels).
cmd = """" & nodeExe & """ """ & bridgeJs & """"

' Run(commande, style, attendre) : style 0 = fenetre CACHEE ; False = ne pas
' attendre (le pont tourne en continu, un seul process persistant).
shell.Run cmd, 0, False
