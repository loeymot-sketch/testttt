# 🎯 MISSION COWORK — Mettre à jour CAISSE + BORNE & supprimer le FLASH terminal (ultra-détaillé)

> **Pour** : Claude cowork (accès VPS + PC caisse + PC borne).
> **Objectif** : (1) tout déployer, (2) mettre à jour la caisse ET la borne, (3) **supprimer le flash terminal** à chaque commande, (4) que la borne charge la **dernière** version au redémarrage.
> **HEAD à déployer** : `89f8288d4` — branche `pos/category-first-caisse-2026-06-23` (poussée).

**Diagnostic (déjà fait, à confirmer sur place)** : les ponts d'impression ne flashent PAS.
- Borne `bridge.js` → écrit direct en USB (node-usb), **aucun** process ni fenêtre.
- Caisse `caisse-bridge.js` → 1 worker PowerShell **persistant**, déjà `windowsHide:true`.

**Le flash vient du LANCEUR** (auto-démarrage) resté sur les machines : ancienne tâche
planifiée `schtasks /SC MINUTE` (relance node → fenêtre à chaque tick) **ou**
`powershell -WindowStyle Hidden` (flashe **quand même** : conhost dessine la fenêtre
AVANT de la cacher). C'est aussi pourquoi la **borne relance l'ancienne version** au reboot.
Le fix = **lanceur sans fenêtre** (VBS window-0 ou service NSSM) + **suppression** de
l'ancien auto-démarrage + **cache Chrome propre**.

---

# ÉTAPE 0 — Prérequis (5 min)

- **Accès** : SSH VPS (cf. `DEPLOY_RUNBOOK_2026-07-02.md`) ; RDP/AnyDesk sur PC **caisse** et PC **borne**, en **administrateur**.
- **Sur chaque PC** : Node.js installé (`node -v` doit répondre). Sinon installer Node LTS.
- **Noms d'imprimante** : caisse = généralement **`SAGA`** (Panneau de config → Périphériques et imprimantes → nom EXACT). Borne = Sanei **SK1-31** en WinUSB (pas de nom Windows, accès USB direct).
- Le domaine cloud = `<DOMAINE>` (remplacer partout ci-dessous par l'URL réelle, ex `https://caisse.lecayenne.fr`).

---

# ÉTAPE 1 — DÉPLOYER TOUT (VPS, une seule fois, ~5 min)

```bash
cd <dossier-du-projet-sur-le-VPS>
git fetch origin
git reset --hard origin/pos/category-first-caisse-2026-06-23      # → 89f8288d4
bash tools/deploy-final-2026-07-07.sh
```
Le script (idempotent) fait : `npm ci` + **`npm run production`** (rebuild TOUS les
bundles — obligatoire pour le bouton Modifier caisse, crudités tacos, upsell, n°32),
`migrate --force`, triggers NF525 (8/8), seeders (Tacos crudités, **Menu Enfant Chicken
Burger**, Oignons cuits, boissons), publication des ponts dans `public/dl`,
`fiscal:verify-chain` = **CHAIN OK ×4**.

✅ **Vérif VPS** : la sortie finit par `CHAIN OK`. Ouvrir la caisse dans un navigateur
« propre » (Ctrl+Maj+R) → catégorie **Menu enfant** montre **« Menu Enfant Chicken
Burger »**, un **Tacos** propose une étape **crudités**, le n° du jour démarre à **A0032**.

---

# 🖥️ MACHINE 1 — CAISSE (imprimante SAGA)

### 1.1 — Diagnostiquer le flash actuel (2 min)
En **PowerShell admin** sur le PC caisse :
```powershell
# a) Tâche planifiée coupable ?
schtasks /Query /FO LIST /V | Select-String -Pattern "Cayenne|Caisse|Pont|bridge|node" -Context 0,3
# b) Process en cours (repérer un ancien lanceur) :
Get-CimInstance Win32_Process -Filter "Name='node.exe' OR Name='powershell.exe' OR Name='conhost.exe'" | Select ProcessId,CommandLine | Format-List
# c) Contenu du dossier démarrage (raccourcis/.bat cachés) :
explorer shell:startup
```
Passer **1 commande de test** à la caisse et **noter le titre** de la fenêtre qui flashe
(PowerShell bleu ? cmd ? conhost ?). → confirme que c'est bien le lanceur.

### 1.2 — Mettre à jour le pont caisse
```powershell
# Créer le dossier si absent :
New-Item -ItemType Directory -Force -Path C:\caisse-bridge | Out-Null
# Télécharger la DERNIÈRE version du pont (route .js whitelistée) :
Invoke-WebRequest "<DOMAINE>/dl/caisse-bridge.js" -OutFile C:\caisse-bridge\caisse-bridge.js
```
(si le download renvoie du HTML → copier `tools/caisse-bridge/caisse-bridge.js` du repo à la main.)

### 1.3 — Installer le lanceur SANS fenêtre (choisir A **ou** B)

**Option A — Service NSSM (idéal, 0 flash structurel, redémarre seul)** — en admin, `nssm.exe` dans le PATH :
```powershell
powershell -ExecutionPolicy Bypass -File C:\caisse-bridge\install-caisse-service.ps1 -BridgePath "C:\caisse-bridge\caisse-bridge.js" -Printer "SAGA"
```
(récupérer `install-caisse-service.ps1` depuis le repo `tools/bridge-service/`.)

**Option B — Lanceur VBS fenêtre-0 (repli, sans admin)** — créer le fichier
`C:\caisse-bridge\start-caisse-bridge-hidden.vbs` avec EXACTEMENT ce contenu
(⚠️ vérifier `printerName = "SAGA"`) :
```vbs
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, printerName, cmd
Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\caisse-bridge.js"
nodeExe = "node"                 ' sinon "C:\Program Files\nodejs\node.exe"
printerName = "SAGA"             ' ← NOM EXACT de l'imprimante caisse
cmd = """" & nodeExe & """ """ & bridgeJs & """ """ & printerName & """"
shell.Run cmd, 0, False          ' 0 = fenêtre cachée dès la création (SW_HIDE)
```
Puis : **double-clic** sur le .vbs (rien ne s'affiche = normal, ça tourne). Démarrage
auto : **Win+R → `shell:startup`** → y déposer un **raccourci** du .vbs.

### 1.4 — SUPPRIMER l'ancien auto-démarrage (c'est LUI le flash)
```powershell
schtasks /Delete /TN "LeCayenne-CaissePont" /F 2>$null
schtasks /Delete /TN "*Caisse*"             /F 2>$null   # tout nom approchant repéré en 1.1
# Retirer du dossier shell:startup tout .bat / raccourci "node …" / watchdog powershell.
# Tuer les process orphelins de l'ancien lanceur :
Get-Process node,powershell -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "*caisse*" -or $_.StartInfo } | Stop-Process -Force -ErrorAction SilentlyContinue
```
Puis relancer proprement le pont (service NSSM **ou** double-clic VBS).

### 1.5 — Rafraîchir la page caisse (bundle à jour)
La caisse est aussi une page Chrome → forcer un rechargement sans cache : **Ctrl+Maj+R**
sur la page caisse, **ou** vider le cache Chrome. (But : plus de « bouton Modifier qui ne
fait rien », plus de vieux bundle.)

### ✅ Vérif CAISSE
- `Invoke-WebRequest http://127.0.0.1:9100/health` → **`UP`**.
- Passer **4 commandes** de test → **0 fenêtre** qui flashe, le ticket sort à chaque fois.
- Ouvrir une ligne au panier → le bouton **Modifier (✎)** **rouvre le wizard** (fix de cette semaine).
- Catégorie **Menu enfant** → **Nuggets + Chicken Burger**.

---

# 🍔 MACHINE 2 — BORNE (Sanei SK1-31, WinUSB) + Chrome kiosque

### 2.1 — Diagnostiquer (identique à 1.1)
```powershell
schtasks /Query /FO LIST /V | Select-String -Pattern "Cayenne|Borne|Pont|bridge|node" -Context 0,3
Get-CimInstance Win32_Process -Filter "Name='node.exe' OR Name='powershell.exe' OR Name='conhost.exe'" | Select ProcessId,CommandLine | Format-List
explorer shell:startup
```
Passer 1 commande à la borne, noter le titre de la fenêtre qui flashe.

### 2.2 — Mettre à jour le pont borne
```powershell
New-Item -ItemType Directory -Force -Path C:\borne-print | Out-Null
Invoke-WebRequest "<DOMAINE>/dl/bridge.js" -OutFile C:\borne-print\bridge.js
```

### 2.3 — Lanceur SANS fenêtre (VBS fenêtre-0 recommandé pour la borne)
Créer `C:\borne-print\start-borne-bridge-hidden.vbs` avec EXACTEMENT :
```vbs
Option Explicit
Dim shell, fso, scriptDir, nodeExe, bridgeJs, cmd
Set shell = CreateObject("WScript.Shell")
Set fso   = CreateObject("Scripting.FileSystemObject")
scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
bridgeJs  = scriptDir & "\bridge.js"
nodeExe = "node"                 ' sinon chemin complet node.exe
cmd = """" & nodeExe & """ """ & bridgeJs & """"
shell.Run cmd, 0, False          ' 0 = SW_HIDE (aucune console dessinée)
```
Double-clic pour lancer ; **raccourci** dans **`shell:startup`** pour l'auto-démarrage.
(Alternative service : `install-borne-service.ps1 -BridgePath "C:\borne-print\bridge.js"`.)

### 2.4 — SUPPRIMER l'ancien auto-démarrage
```powershell
schtasks /Delete /TN "LeCayenne-BornePont" /F 2>$null
schtasks /Delete /TN "*Borne*"             /F 2>$null
# + retirer .bat/raccourci/watchdog de shell:startup, tuer les node.exe orphelins.
```

### 2.5 — 🔴 IMPORTANT : Chrome kiosque = DERNIÈRE version au reboot (fin de « l'ancienne version »)
La borne rouvre Chrome kiosque mais garde un **bundle en cache** → ancienne UI / page
blanche paiement. Remplacer le lancement kiosque par un lancement **cache-propre**.
Créer `C:\borne-print\start-borne-kiosk.bat` :
```bat
@echo off
:: purge le cache Chrome de la borne à CHAQUE démarrage → toujours le dernier bundle
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Cache" 2>nul
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Code Cache" 2>nul
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" ^
  --kiosk --user-data-dir="%LOCALAPPDATA%\BorneKiosk" ^
  --disk-cache-dir="%TEMP%\borne-cache" --disk-cache-size=1 --aggressive-cache-discard ^
  --noerrdialogs --disable-session-crashed-bubble --disable-features=Translate ^
  --disable-pinch --overscroll-history-navigation=0 --autoplay-policy=no-user-gesture-required ^
  "<DOMAINE>/kiosk"
```
Mettre un **raccourci de ce .bat dans `shell:startup`** (à la place de l'ancien lancement
Chrome). Vérifier que l'URL `<DOMAINE>/kiosk` est bien la **courante** (pas une vieille IP).
> Après `deploy-final`, les bundles ont de **nouveaux hashes** (`mix-manifest.json`) → un
> cache propre suffit à charger la dernière version.

### ✅ Vérif BORNE
- `Invoke-WebRequest http://127.0.0.1:9100/health` → **`UP`**, `/test` imprime un ticket démo.
- **Redémarrer physiquement la borne** → elle ouvre la **dernière** version :
  - un **Tacos** propose une étape **crudités**,
  - passer une commande → **0 flash**, ticket imprimé,
  - clic **« Payer »** → **pas** de page blanche (chunk à jour).

---

# 🧰 DÉPANNAGE
- **Le pont ne voit pas l'imprimante** (caisse) : nom SAGA exact (Périphériques et imprimantes) ; si l'imprimante est « par utilisateur », préférer le **VBS** (session user) au service.
- **`node` inconnu** : mettre le chemin complet `C:\Program Files\nodejs\node.exe` dans le .vbs / le service.
- **Ça flashe encore** : il reste un ancien auto-démarrage → re-checker `schtasks /Query` + `shell:startup` + Gestionnaire des tâches → onglet Démarrage. Un `powershell -WindowStyle Hidden` flashe **toujours** : le remplacer par le VBS window-0.
- **Borne toujours vieille version** : le `.bat` cache-propre n'est pas celui lancé au boot → vérifier `shell:startup` ; sinon vider tout le dossier `%LOCALAPPDATA%\BorneKiosk`.
- **`/dl/*.vbs` renvoie du HTML** : normal (route sert que .js) → créer les .vbs à la main (contenus ci-dessus).

# ✅ DEFINITION OF DONE
- [ ] VPS : `deploy-final` OK, CHAIN OK ×4, Chicken Burger + crudités tacos + A0032 visibles.
- [ ] **Caisse** : pont à jour + lanceur sans fenêtre, ancienne tâche supprimée, **0 flash** sur 4 commandes, bouton Modifier OK.
- [ ] **Borne** : pont à jour + lanceur sans fenêtre + Chrome cache-propre ; reboot → **dernière** version, **0 flash**, pas de page blanche.
- [ ] Envoyer à l'owner : 2 captures (caisse + borne) prouvant 0 flash + dernière version.

**Réfs repo** : `tools/bridge-service/README.md`, `tools/bridge-service/*.vbs|*.ps1`,
`tools/borne/bridge.js`, `tools/caisse-bridge/caisse-bridge.js`, `tools/deploy-final-2026-07-07.sh`.
