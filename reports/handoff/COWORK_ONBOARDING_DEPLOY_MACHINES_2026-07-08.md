# 🚀 ONBOARDING + MISSION COWORK (session neuve) — Déployer + MAJ machines + tuer le flash

> **Lis ce document EN ENTIER avant d'agir.** Tu démarres sans contexte : tout est ici.
> **Rôle** : tu as accès (que l'orchestrateur n'a pas) au **VPS** (SSH) et aux **PC Windows**
> caisse + borne (via **AnyDesk**). Ta mission : (1) déployer le dernier code, (2) mettre à
> jour la caisse et la borne, (3) **supprimer le « flash terminal »** à chaque commande,
> (4) faire que la borne charge la **dernière** version au redémarrage.

---

## 0. CONTEXTE EN 1 MINUTE

- **FoodKing / Le Cayenne** = logiciel de resto : une **caisse** (PC Windows + Chrome + imprimante
  SAGA + tiroir), une **borne** de commande client (PC Windows + Chrome kiosque plein écran +
  imprimante Sanei SK1-31 USB), un **écran cuisine** (KDS). Le tout parle à un **VPS** (serveur cloud).
- **Le code est déjà écrit, testé et POUSSÉ.** HEAD à déployer :
  `17d65e435` — branche `pos/category-first-caisse-2026-06-23` — repo `git@github.com:loeymot-sketch/testttt.git`.
- Ce que ce déploiement apporte (déjà validé côté code) : bouton Modifier panier réparé, 2e menu
  enfant « Chicken Burger », n° de commande qui démarre à 32, crudités sur les tacos, images upsell
  entières, boutons lisibles, **TPE carte simulé**, **bouton Imprimer cuisine/client dans la file à
  encaisser**, **ouverture tiroir au paiement espèces**, commande téléphone, liste à-encaisser, split.

### ⚠️ LES 3 ENDROITS — NE PAS CONFONDRE (source de la confusion « ~/testttt introuvable »)
| Ce que tu fais | OÙ ça tourne | COMMENT y accéder |
|---|---|---|
| **Déployer** (`deploy-final`) | sur le **VPS** (cloud) | **SSH** vers le VPS, puis `cd <dossier-projet-VPS>` |
| **Fix flash + bridges + Chrome** | sur les **PC Windows** (caisse + borne) | **AnyDesk** (prise de contrôle du PC) |
| Le repo | GitHub | `git` (clone facultatif, voir plus bas) |

> Il n'y a **AUCUN** dossier `~/testttt` à trouver sur ton Mac. Le déploiement se fait **sur le VPS
> en SSH**, pas dans un dossier local. Si tu veux quand même une copie locale des fichiers :
> `git clone git@github.com:loeymot-sketch/testttt.git` (n'importe où), ou télécharge juste les
> ponts depuis `<DOMAINE>/dl/bridge.js` et `<DOMAINE>/dl/caisse-bridge.js`.

---

## 1. À DEMANDER À L'OWNER AVANT DE COMMENCER (3 infos)
1. **VPS** : l'hôte/IP SSH **et** le chemin du projet sur le VPS (ex. `/var/www/lecayenne`). Astuce :
   sur le VPS, dans le dossier de déploiement habituel, `pwd` donne le chemin exact.
2. **`<DOMAINE>`** : l'URL cloud (ex. `https://caisse.lecayenne.fr`) — sert aux téléchargements `/dl/…`
   et à l'URL kiosque de la borne.
3. **AnyDesk** : confirme que **cette session cowork tourne sur le Mac où AnyDesk est ouvert** vers la
   borne (sinon, la voir/piloter est impossible — voir §Dépannage AnyDesk).

Sur chaque **PC Windows** : Node.js installé (`node -v` répond), droits **administrateur**, et pour
l'option service : [NSSM](https://nssm.cc/download) (`nssm.exe`).

---

## 2. PARTIE A — DÉPLOYER (VPS, SSH, ~5 min) — pas besoin d'AnyDesk

```bash
ssh <user>@<hote-VPS>
cd <dossier-projet-VPS>                 # celui que l'owner t'a donné (PAS ~/testttt)
git fetch origin
git reset --hard origin/pos/category-first-caisse-2026-06-23   # → HEAD 17d65e435
bash tools/deploy-final-2026-07-07.sh
```
Le script (idempotent) : `npm ci` + **`npm run production`** (rebuild de TOUS les bundles),
`migrate --force`, triggers NF525 (install+verify **8/8**), vignettes WebP, **seeders**
(TacosCrudités, **MenuEnfantChickenBurger**, **SimulatedTpeTerminal**, OnionCuit, DrinksUpdate),
publication des ponts dans `public/dl`, `POS_PRINT_SILENT_ONLY=true`, `fiscal:verify-chain --all`
(= **CHAIN OK ×4**), `queue:restart`.

✅ **Vérif A** : la sortie finit par `CHAIN OK`. Dans un navigateur **rechargé sans cache** (Ctrl+Maj+R)
sur `<DOMAINE>`, la caisse doit montrer : catégorie **Menu enfant → « Menu Enfant Chicken Burger »**,
un **Tacos** avec étape **crudités**, le **n° du jour = A0032**, et au paiement carte le TPE
**« TPE Le Cayenne #1 · simulation »** (plus de « Aucun TPE »).

> Si `git reset` refuse (modifs locales sur le VPS) : `git stash` puis relance. Si `deploy-final`
> n'existe pas sur le VPS après le reset, c'est que le reset n'a pas pris → vérifie la branche.

---

## 3. DIAGNOSTIC DU FLASH (déjà établi — à confirmer sur place)

Les **ponts d'impression ne flashent PAS** : borne = écrit direct en USB (aucun process), caisse =
1 worker PowerShell déjà lancé caché (`windowsHide`) et **persistant**. **Le flash vient du LANCEUR /
auto-démarrage** encore installé sur les machines :
- ❌ une **tâche planifiée qui relance node** (`schtasks … /SC MINUTE`) → une console à chaque relance ;
- ❌ un `powershell -WindowStyle Hidden` → **flashe quand même** (Windows dessine la fenêtre AVANT de la cacher) ;
- ❌ un **ancien pont** qui ouvrait une console par ticket.

C'est aussi pourquoi la **borne relance l'ancienne version** au reboot. **Fix** = lanceur **sans
fenêtre** (service NSSM ou VBS window-0) + **suppression** de l'ancien auto-démarrage + **cache Chrome propre**.

---

## 4. PARTIE B — CAISSE (PC Windows, via AnyDesk, imprimante SAGA)

### B.1 Diagnostiquer (PowerShell **admin**)
```powershell
schtasks /Query /FO LIST /V | Select-String -Pattern "Cayenne|Caisse|Pont|bridge|node" -Context 0,3
Get-CimInstance Win32_Process -Filter "Name='node.exe' OR Name='powershell.exe' OR Name='conhost.exe'" | Select ProcessId,CommandLine | Format-List
explorer shell:startup     # regarder les raccourcis/.bat présents
```
Passe **1 commande de test** à la caisse et note le **titre** de la fenêtre qui flashe.

### B.2 Mettre à jour le pont
```powershell
New-Item -ItemType Directory -Force -Path C:\caisse-bridge | Out-Null
Invoke-WebRequest "<DOMAINE>/dl/caisse-bridge.js" -OutFile C:\caisse-bridge\caisse-bridge.js
```

### B.3 Lanceur SANS fenêtre (choisir **A** ou **B**)
**A — Service NSSM (idéal)** — admin, `nssm.exe` dans le PATH (récupérer `install-caisse-service.ps1`
du repo `tools/bridge-service/`) :
```powershell
powershell -ExecutionPolicy Bypass -File install-caisse-service.ps1 -BridgePath "C:\caisse-bridge\caisse-bridge.js" -Printer "SAGA"
```
**B — VBS window-0 (repli sans admin)** — créer `C:\caisse-bridge\start-caisse-bridge-hidden.vbs` avec
EXACTEMENT (adapter `printerName` = nom EXACT de l'imprimante caisse, cf. Périphériques et imprimantes) :
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
Double-clic (rien ne s'affiche = normal). Auto-démarrage : **Win+R → `shell:startup`** → y déposer un
**raccourci** du .vbs.

### B.4 SUPPRIMER l'ancien auto-démarrage (LA source du flash)
```powershell
schtasks /Delete /TN "LeCayenne-CaissePont" /F 2>$null
schtasks /Delete /TN "*Caisse*"             /F 2>$null
# Retirer de shell:startup tout .bat / raccourci "node …" / watchdog powershell.
# Tuer les process orphelins de l'ancien lanceur (Gestionnaire des tâches → node/powershell).
```
Puis relancer le pont proprement (service ou double-clic VBS).

### B.5 Rafraîchir la page caisse
La caisse est une page Chrome → **Ctrl+Maj+R** (ou vider le cache) pour charger le dernier bundle
(sinon « bouton Modifier qui ne fait rien », vieux TPE, etc.).

### ✅ Vérif CAISSE
- `Invoke-WebRequest http://127.0.0.1:9100/health` → **`UP`**.
- 4 commandes de test → **0 fenêtre** qui flashe, ticket imprimé à chaque fois.
- Bouton **Modifier (✎)** d'une ligne panier → rouvre le wizard.
- **Menu enfant** → Nuggets + Chicken Burger. Paiement **Carte (TPE)** → dropdown simulation, OK.
- File **« à encaisser »** → chaque ligne a **🖨️ Cuisine / 🖨️ Client** à côté d'Encaisser.

---

## 5. PARTIE C — BORNE (PC Windows, via AnyDesk, Sanei SK1-31 + Chrome kiosque)

### C.1 Diagnostiquer (identique à B.1, motifs « Borne »)
```powershell
schtasks /Query /FO LIST /V | Select-String -Pattern "Cayenne|Borne|Pont|bridge|node" -Context 0,3
Get-CimInstance Win32_Process -Filter "Name='node.exe' OR Name='powershell.exe' OR Name='conhost.exe'" | Select ProcessId,CommandLine | Format-List
explorer shell:startup
```

### C.2 Mettre à jour le pont
```powershell
New-Item -ItemType Directory -Force -Path C:\borne-print | Out-Null
Invoke-WebRequest "<DOMAINE>/dl/bridge.js" -OutFile C:\borne-print\bridge.js
```

### C.3 Lanceur SANS fenêtre (VBS window-0 recommandé)
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
Double-clic ; **raccourci** dans **`shell:startup`**. (Alternative service : `install-borne-service.ps1
-BridgePath "C:\borne-print\bridge.js"`.)

### C.4 SUPPRIMER l'ancien auto-démarrage
```powershell
schtasks /Delete /TN "LeCayenne-BornePont" /F 2>$null
schtasks /Delete /TN "*Borne*"             /F 2>$null
# + retirer .bat/raccourci/watchdog de shell:startup, tuer les node.exe orphelins.
```

### C.5 🔴 Chrome kiosque = DERNIÈRE version au reboot (fin de « ancienne version »)
La borne rouvre Chrome mais garde un **bundle en cache**. Remplacer le lancement kiosque par un
lancement **cache-propre**. Créer `C:\borne-print\start-borne-kiosk.bat` :
```bat
@echo off
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Cache" 2>nul
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Code Cache" 2>nul
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" ^
  --kiosk --user-data-dir="%LOCALAPPDATA%\BorneKiosk" ^
  --disk-cache-dir="%TEMP%\borne-cache" --disk-cache-size=1 --aggressive-cache-discard ^
  --noerrdialogs --disable-session-crashed-bubble --disable-features=Translate ^
  --disable-pinch --overscroll-history-navigation=0 --autoplay-policy=no-user-gesture-required ^
  "<DOMAINE>/kiosk"
```
Mettre un **raccourci de ce .bat dans `shell:startup`** (à la place de l'ancien lancement Chrome).
Vérifier que l'URL `<DOMAINE>/kiosk` est la **courante** (pas une vieille IP/URL).

### ✅ Vérif BORNE
- `Invoke-WebRequest http://127.0.0.1:9100/health` → **`UP`** ; `/test` imprime un ticket démo.
- **Redémarrer physiquement la borne** → **dernière** version : un **Tacos** propose l'étape **crudités**,
  passer une commande = **0 flash** + ticket, clic **« Payer »** = **pas** de page blanche.

---

## 6. DÉPANNAGE

**AnyDesk introuvable / écran non détecté**
- La prise de contrôle ne voit AnyDesk **que si cette session cowork tourne sur le Mac où AnyDesk est
  ouvert**. Si c'est une autre machine → relancer cowork **sur ce Mac**.
- Mettre la **fenêtre AnyDesk au premier plan** (cliquer dessus), vérifier qu'elle affiche l'écran de
  la borne, puis réessayer.
- Si AnyDesk n'est pas lancé : l'ouvrir et se connecter à la borne d'abord.
- Rappel : AnyDesk sert **uniquement** aux PC Windows. Le **déploiement VPS (Partie A) n'a pas besoin
  d'AnyDesk** — juste SSH. On peut donc faire la Partie A tout de suite.

**Divers**
- Pont ne voit pas l'imprimante (caisse) : nom `SAGA` exact ; imprimante « par utilisateur » → préférer
  le **VBS** (session user) au service.
- `node` inconnu : mettre le chemin complet `C:\Program Files\nodejs\node.exe`.
- Ça flashe encore : reste un ancien auto-démarrage → re-vérifier `schtasks /Query`, `shell:startup`,
  Gestionnaire des tâches → onglet **Démarrage**. `powershell -WindowStyle Hidden` flashe TOUJOURS → le remplacer par le VBS.
- Borne toujours vieille version : le `.bat` cache-propre n'est pas celui lancé au boot → vérifier
  `shell:startup` ; au besoin vider tout `%LOCALAPPDATA%\BorneKiosk`.
- `/dl/*.vbs` renvoie du HTML : normal (la route ne sert que les `.js`) → créer les .vbs à la main (contenus ci-dessus).

---

## 7. DEFINITION OF DONE + rapport à l'owner
- [ ] **VPS** : `deploy-final` OK, `CHAIN OK ×4`, triggers 8/8 ; Chicken Burger + crudités tacos + A0032 + TPE simulé visibles.
- [ ] **Caisse** : pont à jour + lanceur sans fenêtre, ancienne tâche supprimée, **0 flash** sur 4 commandes ;
      Modifier OK ; boutons 🖨️ Cuisine/Client présents dans la file à encaisser ; TPE carte OK.
- [ ] **Borne** : pont + lanceur sans fenêtre + Chrome cache-propre ; reboot → **dernière** version, **0 flash**, pas de page blanche.
- [ ] Envoyer à l'owner **2 captures** (caisse + borne) prouvant 0 flash + dernière version.

**Réfs repo** (contexte technique complet) : `tools/bridge-service/README.md`, `tools/bridge-service/*.vbs|*.ps1`,
`tools/borne/bridge.js`, `tools/caisse-bridge/caisse-bridge.js`, `tools/deploy-final-2026-07-07.sh`,
et les missions sœurs `reports/handoff/MISSION_COWORK_CAISSE_BORNE_ULTRA_2026-07-07.md`.
