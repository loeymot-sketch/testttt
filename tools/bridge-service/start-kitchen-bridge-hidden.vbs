' ============================================================================
'  LE CAYENNE — Lanceur CACHE du pont d'impression CUISINE (KDS -> imprimante Epson)
'  [KITCHEN-AUTOSTART 2026-08-09]  ZERO fenetre, ZERO flash — meme au demarrage.
' ----------------------------------------------------------------------------
'  POURQUOI CE FICHIER : le pont cuisine (port 9101) etait le SEUL des trois ponts
'  sans artefact de demarrage automatique — la caisse et la borne en avaient un,
'  pas la cuisine. Consequence : apres chaque redemarrage du PC cuisine, le pont
'  restait MORT et plus AUCUN ticket ne sortait, en silence (le KDS continue
'  d'afficher les commandes, il ne signale pas que l'imprimante est absente).
'
'  Lancer "node kitchen-bridge.js" depuis une tache planifiee, un .bat, ou
'  "powershell -WindowStyle Hidden" ouvre (ne serait-ce qu'une fraction de seconde)
'  une fenetre console => le "flash de terminal" en pleine cuisine. Ce VBS lance
'  node avec le mode fenetre 0 (SW_HIDE) : le process est cree DEJA cache, donc la
'  console n'est JAMAIS dessinee.
'
'  UTILISATION :
'    1. Placer ce .vbs A COTE de kitchen-bridge.js sur le PC cuisine.
'    2. Regler printerName ci-dessous = NOM EXACT de l'imprimante cuisine.
'       Le relever avec :  Get-Printer | Select-Object Name
'       (le pilote Epson cree parfois "EPSON TM-m30II Receipt" et non "Epson TM-m30II" ;
'        un nom errone n'echoue PAS bruyamment, il empile dans une file morte).
'    3. Double-clic pour lancer maintenant (rien ne s'affiche = c'est NORMAL).
'    4. Demarrage auto : Win+R -> shell:startup -> y deposer un RACCOURCI de ce .vbs.
'
'  Si "node" n'est pas dans le PATH, mettre le chemin complet dans nodeExe.
' ============================================================================
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, printerName, cmd

Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")

' Dossier de CE .vbs ; kitchen-bridge.js est suppose a cote. Sinon, chemin absolu.
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\kitchen-bridge.js"

' "node" si Node est dans le PATH ; sinon chemin complet, ex :
'   nodeExe = "C:\Program Files\nodejs\node.exe"
nodeExe = "node"

' NOM EXACT de l'imprimante CUISINE (a adapter) — passe en argument au pont.
printerName = "Epson TM-m30II"

' Guillemets autour des chemins + du nom d'imprimante (espaces eventuels).
cmd = """" & nodeExe & """ """ & bridgeJs & """ """ & printerName & """"

' Run(commande, style, attendre) : style 0 = fenetre CACHEE ; False = ne pas
' attendre (le pont tourne en continu, un seul process persistant).
shell.Run cmd, 0, False
