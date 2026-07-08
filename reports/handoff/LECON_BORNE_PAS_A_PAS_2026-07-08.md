# 🎓 LEÇON COMPLÈTE — Mettre à jour la BORNE + tuer le flash (100% manuel, copier-coller)

> **Pourquoi manuel** : le pilotage AUTOMATIQUE de la borne via AnyDesk **ne marche pas**
> (AnyDesk ne relaie pas les clics/touches synthétiques d'une automatisation — seulement
> ton input HUMAIN). Donc : **TOI tu exécutes**, en collant chaque bloc ci-dessous. Cowork
> te dicte/vérifie. Le presse-papier AnyDesk fait passer le copier-coller Mac → borne.
>
> **Chaque bloc = 1 seul copier-coller.** Les blocs **créent les fichiers tout seuls** (pas
> de Notepad, pas de clic). Fais-les **dans l'ordre**. Après chaque bloc, je dis le **résultat attendu**.

> **🔑 ARCHITECTURE URL (règle owner, à ne JAMAIS confondre)** :
> - **`https://vps-418872ac.vps.ovh.net`** = **serveur privé** = l'app **borne / caisse / KDS / admin**. Les machines restent **là-dessus**.
> - **`lecayenne.fr`** = **UNIQUEMENT le site web public** (commande client standalone), séparé. **On n'y pointe JAMAIS la borne/caisse.**
> - URL borne exacte = `https://vps-418872ac.vps.ovh.net/kiosk?machine_key=…` (le `machine_key` = identité de CETTE borne, à garder verbatim).

---

## ÉTAPE 0 — Ouvrir PowerShell EN ADMIN sur la borne (via AnyDesk)
1. Dans la fenêtre AnyDesk (image de la borne), **clique une fois** dessus pour donner le focus.
2. Appuie sur la touche **Windows** → tape **`powershell`**.
3. **Clic droit** sur « Windows PowerShell » → **« Exécuter en tant qu'administrateur »** → **Oui**.
4. Une fenêtre bleue PowerShell s'ouvre. C'est là qu'on colle les blocs.

> **Coller dans la borne** : copie le bloc ici (sur le Mac), va dans la fenêtre PowerShell de la
> borne, **clic droit** (colle) ou **Ctrl+V**, puis **Entrée**. Si le copier-coller AnyDesk ne
> passe pas : dans AnyDesk, active la **synchro du presse-papier** (icône presse-papier de la
> barre AnyDesk), ou tape les commandes à la main.

---

## ÉTAPE 1 — Diagnostic : QU'EST-CE qui lance la borne / fait flasher ?
Colle ce bloc (lecture seule, ne casse rien) :
```powershell
Write-Host "=== Taches planifiees suspectes ===" -ForegroundColor Cyan
schtasks /Query /FO LIST /V | Select-String -Pattern "Cayenne|Borne|Pont|bridge|node|chrome|kiosk"
Write-Host "=== Dossier demarrage (raccourcis lances au boot) ===" -ForegroundColor Cyan
Get-ChildItem "$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup" | Select Name
Write-Host "=== Process node/powershell/chrome en cours ===" -ForegroundColor Cyan
Get-Process node,powershell,chrome -ErrorAction SilentlyContinue | Select Id,ProcessName | Format-Table -Auto
```
**Résultat attendu** : une liste. **Note** tout ce qui contient `Borne`, `Pont`, `node`, `kiosk`
(c'est ce qu'on remplacera/supprimera). Copie-colle-moi la sortie si tu veux que je confirme.

---

## ÉTAPE 2 — Télécharger le PONT d'impression à jour
```powershell
New-Item -ItemType Directory -Force -Path C:\borne-print | Out-Null
Invoke-WebRequest "https://vps-418872ac.vps.ovh.net/dl/bridge.js" -OutFile C:\borne-print\bridge.js
Write-Host "pont telecharge : $((Get-Item C:\borne-print\bridge.js).Length) octets" -ForegroundColor Green
```
**Résultat attendu** : `pont telecharge : ~11000 octets` (en vert). Si erreur/HTML → le
domaine est faux ou la borne n'a pas internet.

---

## ÉTAPE 3 — Créer le LANCEUR SANS FENÊTRE du pont (le fichier se crée tout seul)
```powershell
@'
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, cmd
Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\bridge.js"
nodeExe = "node"
cmd = """" & nodeExe & """ """ & bridgeJs & """"
shell.Run cmd, 0, False
'@ | Set-Content -Encoding ASCII C:\borne-print\start-borne-bridge-hidden.vbs
Write-Host "lanceur VBS cree." -ForegroundColor Green
```
**Résultat attendu** : `lanceur VBS cree.` (Ce VBS lance node **fenêtre cachée** = 0 flash.)

> Si `node` n'est pas trouvé plus tard : refais ce bloc en remplaçant `nodeExe = "node"` par
> `nodeExe = "C:\Program Files\nodejs\node.exe"`.

---

## ÉTAPE 4 — Créer le LANCEUR CHROME « cache propre » (fin de « ancienne version au reboot »)
```powershell
@'
@echo off
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Cache" 2>nul
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Code Cache" 2>nul
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --user-data-dir="%LOCALAPPDATA%\BorneKiosk" --disk-cache-dir="%TEMP%\borne-cache" --disk-cache-size=1 --aggressive-cache-discard --noerrdialogs --disable-session-crashed-bubble --disable-pinch --overscroll-history-navigation=0 --autoplay-policy=no-user-gesture-required "https://vps-418872ac.vps.ovh.net/kiosk"
'@ | Set-Content -Encoding ASCII C:\borne-print\start-borne-kiosk.bat
Write-Host "lanceur Chrome cache-propre cree." -ForegroundColor Green
```
**Résultat attendu** : `lanceur Chrome cache-propre cree.`
> Vérifie que Chrome est bien dans `C:\Program Files\Google\Chrome\Application\chrome.exe`
> (sinon adapte le chemin — parfois `Program Files (x86)`).

---

## ÉTAPE 5 — SUPPRIMER l'ancien auto-démarrage (LA source du flash + de l'ancienne version)
```powershell
schtasks /Delete /TN "LeCayenne-BornePont" /F 2>$null
schtasks /Query /FO LIST | Select-String "Borne|Cayenne|Pont|kiosk" | ForEach-Object { $_ }
Write-Host "--- Si une tache 'Borne/Pont/kiosk' apparait ci-dessus, supprime-la : schtasks /Delete /TN \"LE_NOM_EXACT\" /F" -ForegroundColor Yellow
Get-ChildItem "$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup"
Write-Host "--- Supprime du Startup ci-dessus tout ancien raccourci .bat/node/chrome (on remet les bons a l'etape 6) ---" -ForegroundColor Yellow
```
**Action** : si une tâche planifiée ou un raccourci ancien apparaît, supprime-le
(`schtasks /Delete /TN "nom" /F`, ou supprime le .lnk du dossier Startup). **C'est LUI le flash.**

---

## ÉTAPE 6 — Mettre les BONS lanceurs au démarrage (raccourcis créés automatiquement)
```powershell
$startup = "$env:APPDATA\Microsoft\Windows\Start Menu\Programs\Startup"
$W = New-Object -ComObject WScript.Shell
$s1 = $W.CreateShortcut("$startup\BornePont.lnk");   $s1.TargetPath = "C:\borne-print\start-borne-bridge-hidden.vbs"; $s1.Save()
$s2 = $W.CreateShortcut("$startup\BorneKiosk.lnk");  $s2.TargetPath = "C:\borne-print\start-borne-kiosk.bat";        $s2.Save()
Write-Host "raccourcis de demarrage crees (pont + chrome)." -ForegroundColor Green
Get-ChildItem $startup | Select Name
```
**Résultat attendu** : `BornePont.lnk` et `BorneKiosk.lnk` présents (+ plus aucun ancien).

---

## ÉTAPE 7 — Lancer MAINTENANT + vérifier (sans redémarrer)
```powershell
# tuer d'eventuels vieux node, puis lancer le pont proprement (fenetre cachee)
Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Process "wscript.exe" "C:\borne-print\start-borne-bridge-hidden.vbs"
Start-Sleep -Seconds 2
try { (Invoke-WebRequest "http://127.0.0.1:9100/health" -UseBasicParsing).Content } catch { "PONT PAS ENCORE UP - reessaie dans 3s" }
```
**Résultat attendu** : **`UP`**. (Si « pas encore up », re-colle la dernière ligne 3 s après.)
Puis pour tester l'impression + l'absence de flash :
```powershell
Invoke-WebRequest "http://127.0.0.1:9100/test" -UseBasicParsing | Out-Null ; "ticket de test envoye"
```
**Résultat attendu** : un **ticket démo sort de l'imprimante**, et **AUCUNE fenêtre noire ne flashe**.

---

## ÉTAPE 8 — Test final : REDÉMARRER la borne
Redémarre le PC borne. Au retour, la borne doit :
- ouvrir Chrome kiosque sur la **dernière** version (un **Tacos** propose l'étape **crudités**),
- **0 flash** quand tu passes une commande,
- **pas de page blanche** au clic « Payer ».

---

## ✅ Definition of Done (borne)
- [ ] `http://127.0.0.1:9100/health` → `UP`
- [ ] 4 commandes de test → **0 fenêtre qui flashe**, ticket imprimé à chaque fois
- [ ] reboot → dernière version (étape crudités tacos), pas de page blanche
- [ ] capture d'écran envoyée à l'owner

## 🆘 Si ça bloque
- **Copier-coller ne passe pas** → active la synchro presse-papier AnyDesk, ou tape à la main.
- **`node` inconnu** → refais l'étape 3 avec le chemin complet `C:\Program Files\nodejs\node.exe`.
- **Ça flashe encore** → il reste un ancien auto-démarrage : re-fais l'étape 1, trouve-le, supprime-le.
- **Borne toujours vieille** → le `.bat` cache-propre n'est pas celui lancé au boot : vérifie le
  dossier Startup (étape 6) ; au pire, dans PowerShell : `Remove-Item "$env:LOCALAPPDATA\BorneKiosk" -Recurse -Force`.

*(La caisse suit exactement la même logique — voir `COWORK_ONBOARDING_DEPLOY_MACHINES_2026-07-08.md` §4,
avec `caisse-bridge.js`, imprimante `SAGA`, et le nom d'imprimante dans le VBS.)*
