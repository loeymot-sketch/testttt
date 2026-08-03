# Mission COWORK — Configuration définitive de la borne Le Cayenne (de A à Z, via AnyDesk)

**Objectif** : installer et valider la borne tactile Le Cayenne sur le PC Windows (« SAGA ») piloté en AnyDesk, en Chrome plein écran pointant vers le **cloud OVH déjà déployé**. À la fin : la borne démarre seule, imprime ses tickets, accepte toutes les commandes (y compris multi-viandes), et l'encaissement se fait à la caisse (Plan B).

**Ce qui est DÉJÀ fait côté serveur (ne rien y toucher)** : le backend Laravel tourne sur le VPS, à jour. Le bundle `app.js` (md5 **bca5e5c2**, commit `c325a8610`) embarque le fix « signal jaune » (un aperçu tarif 422 = composition incomplète = silencieux). La taille de ticket est déjà réglée serveur (corps `0x01` double hauteur, titre `0x11` 2×2). Le routage paiement borne→caisse (`payment_route_all_to_counter=true`) est actif.

**Ce que le cowork fait côté borne uniquement** : flag Chrome (PNA loopback), liaison WinUSB de l'imprimante (Zadig), pont d'impression local `bridge.js`, cache Chrome, lanceur + watchdog + autostart, et les tests de validation depuis la console (CDP).

**LES 3 RÈGLES D'OR**
1. **Piloter par `.click()` JS (CDP `Runtime.evaluate`) ou `Input.dispatchMouseEvent`** — JAMAIS `synthesizeTapGesture` (geste tactile, échoue à distance). Le wizard est fluide en tactile physique, lent en remote.
2. **Ne toucher AUCUN fichier serveur ni `.env`** du VPS. Tout se règle côté PC borne.
3. **`WebSocket` / `kiosk-event` 401 en console = NORMAL** (synchro KDS par polling). À ignorer.

> **PowerShell — note de syntaxe critique** : sous Windows PowerShell 5.1 (shell par défaut), `curl` est un ALIAS de `Invoke-WebRequest` et n'accepte PAS `-X`/`-H`/`-d`. Dans TOUS les blocs PowerShell de ce doc, on utilise donc `Invoke-RestMethod` (renvoie directement le corps de la réponse) — ou, si tu préfères le vrai cURL, le binaire explicite **`curl.exe`** (livré par Windows depuis la build 1803). Ne jamais écrire `curl` nu dans PowerShell.

---

## 0. Pré-vol

Avant toute commande, vérifier :

1. **AnyDesk connecté** au PC borne, session active, droits **administrateur Windows** (PowerShell « Exécuter en tant qu'administrateur »).
2. **Google Chrome installé** sur le PC borne (`C:\Program Files\Google\Chrome\Application\chrome.exe` ou `(x86)`).
3. **Imprimante ticket SK1-31 branchée (USB) + papier chargé**, allumée.
4. **Node.js LTS installé** (pour le pont d'impression) — sinon https://nodejs.org. Vérifier que `node.exe` est dans le `PATH` (`node --version`).
5. **Accès réseau au VPS** : ouvrir une fois l'URL borne dans Chrome normal pour confirmer que le cloud répond ET que la `machine_key` s'authentifie (page kiosk = accueil orange, pas une erreur d'auth).

Valeurs de référence (à utiliser telles quelles) :

```text
URL borne   : https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972
URL caisse  : https://vps-418872ac.vps.ovh.net/admin/pos
URL KDS     : https://vps-418872ac.vps.ovh.net/kds
machine_key : lcb-227b5373163391c875eeb43f7ee1affe3972   (NE JAMAIS CHANGER — identité fiscale/branche de CETTE borne)
```

> `machine_key` est consommée serveur à `resources/views/master.blade.php:139` (`request()->query('machine_key')`).
> **Caveat** : la VALEUR `lcb-227b...` est un enregistrement de la table `kiosk_machines` **sur le VPS** ; elle n'existe PAS dans le repo (aucun `grep 'lcb-'`/générateur de préfixe). On ne peut donc ni la confirmer ni l'infirmer ici → **la valider via l'étape 5 ci-dessus** (charger l'URL borne dans Chrome normal AVANT l'install). Si la page kiosk ne s'authentifie pas, la clé est périmée/fausse → **escalader au dev, ne jamais en inventer une autre.**

---

## 1. Installation Chrome kiosk

### 1.1 — Pré-vol cache (débloque un « configurateur vide / ChunkLoadError » dû à un vieux cache)

Fermer Chrome et vider le cache du profil kiosk (ne sert qu'à débloquer immédiatement avant la 1re install ; ensuite le `.bat` le refait à chaque ouverture) :

```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Remove-Item -Recurse -Force "$env:LOCALAPPDATA\LeCayenneKiosk\Default\Cache" -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force "$env:LOCALAPPDATA\LeCayenneKiosk\Default\Code Cache" -ErrorAction SilentlyContinue
Remove-Item -Recurse -Force "$env:LOCALAPPDATA\LeCayenneKiosk\Default\Service Worker" -ErrorAction SilentlyContinue
```

> Le « configurateur vide / ChunkLoadError » N'EST PAS un bug serveur : les chunks JS sont en 200/JS et md5-identiques sur le VPS. Cause = cache Chrome borne resté sur un `app.js` intermédiaire.

### 1.2 — Bloc d'installation UNIQUE (idempotent, ré-exécutable)

Coller **tel quel** dans PowerShell. Crée : dossier `C:\LeCayenne`, `start-kiosk.bat`, `watchdog-kiosk.ps1`, icône Bureau, raccourci autostart (`shell:startup`), tâche planifiée watchdog.

```powershell
$dir = "C:\LeCayenne"; New-Item -ItemType Directory -Force -Path $dir | Out-Null
$bat = @'
@echo off
set "CHROME=C:\Program Files\Google\Chrome\Application\chrome.exe"
if not exist "%CHROME%" set "CHROME=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
set "URL=https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972"
set "PROFILE=%LOCALAPPDATA%\LeCayenneKiosk"
rmdir /s /q "%PROFILE%\Default\Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Code Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Service Worker" 2>nul
start "" "%CHROME%" --kiosk "%URL%" --user-data-dir="%PROFILE%" --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --kiosk-printing --disable-pinch --overscroll-history-navigation=0 --disable-session-crashed-bubble --noerrdialogs --disable-infobars --disable-translate --no-first-run --hide-crash-restore-bubble
'@
Set-Content -Path "$dir\start-kiosk.bat" -Value $bat -Encoding ASCII
$wd = 'if (-not (Get-Process chrome -ErrorAction SilentlyContinue)) { Start-Process "C:\LeCayenne\start-kiosk.bat" }'
Set-Content -Path "$dir\watchdog-kiosk.ps1" -Value $wd -Encoding ASCII
$ws = New-Object -ComObject WScript.Shell
foreach ($loc in @(([Environment]::GetFolderPath("Desktop")+"\BORNE Le Cayenne.lnk"), ([Environment]::GetFolderPath("Startup")+"\BORNE Le Cayenne.lnk"))) {
  $sc = $ws.CreateShortcut($loc); $sc.TargetPath = "$dir\start-kiosk.bat"; $sc.WorkingDirectory = $dir
  $sc.IconLocation = "$env:ProgramFiles\Google\Chrome\Application\chrome.exe,0"; $sc.Save()
}
$action  = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-WindowStyle Hidden -ExecutionPolicy Bypass -File C:\LeCayenne\watchdog-kiosk.ps1"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 3650)
Register-ScheduledTask -TaskName "LeCayenneKioskWatchdog" -Action $action -Trigger $trigger -RunLevel Highest -Force | Out-Null
Write-Host "INSTALL OK : lanceur + icone bureau + demarrage auto + watchdog crees."
```

**Attendu** : `INSTALL OK : lanceur + icone bureau + demarrage auto + watchdog crees.`

> **Deux corrections importantes par rapport aux anciennes missions** :
> - `%CHROME%` est désormais **entre guillemets** dans les deux lignes (`if not exist "%CHROME%"` et `start "" "%CHROME%" ...`). Sans guillemets, l'espace de « Program Files » fait que cmd lance `C:\Program` → « Windows ne trouve pas 'C:\Program' » → **Chrome ne démarre jamais**.
> - `-RepetitionInterval` est accompagné de `-RepetitionDuration` (3650 jours). Sans durée, selon la build Windows, la répétition peut être rejetée ou ne se déclencher qu'**une fois** → watchdog inopérant. Vérifier après coup : `(Get-ScheduledTask LeCayenneKioskWatchdog).Triggers.Repetition`.

### 1.3 — Explication de chaque flag du `start-kiosk.bat`

| Flag | Rôle |
|---|---|
| `--kiosk "%URL%"` | Vrai plein écran verrouillé : le client ne peut PAS sortir. On quitte par **Alt+F4**. |
| `--user-data-dir="%PROFILE%"` (`LeCayenneKiosk`) | Profil Chrome dédié isolé : pas pollué par tes sessions/extensions. |
| `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks` | **OBLIGATOIRE** : autorise l'appel public-HTTPS → loopback `127.0.0.1:9100` (pont d'impression). Sans lui, Chrome bloque (« Local Network Access ») = **aucune impression**. L'en-tête PNA côté pont ne suffit pas. |
| `--kiosk-printing` | Impression silencieuse, sans boîte de dialogue. |
| `--disable-pinch` | Désactive le zoom pincement tactile. |
| `--overscroll-history-navigation=0` | Désactive le swipe arrière/avant. |
| `--disable-session-crashed-bubble` / `--hide-crash-restore-bubble` | Supprime la bulle « Restaurer » après Alt+F4/coupure. |
| `--noerrdialogs` | Supprime les boîtes d'erreur bloquantes. |
| `--disable-infobars` | Masque les bandeaux d'info. |
| `--disable-translate` | Supprime le pop-up « Traduire ». |
| `--no-first-run` | Saute l'assistant premier-lancement. |
| `rmdir Cache/Code Cache/Service Worker` (en-tête .bat) | **Vide le cache à CHAQUE ouverture** → la borne prend toujours la dernière version serveur → plus jamais de ChunkLoadError. L'owner n'a JAMAIS rien à vider. |

> Optionnels présents dans d'anciennes missions mais **non inclus** ici : `--fast --fast-start`. **Ne jamais** ajouter `--remote-debugging-port=9222` au lanceur de PROD (seulement pour le test à distance §4, puis on revient au lanceur normal §9).

### 1.4 — Lancer tout de suite (sans rebooter)

```powershell
Start-Process "C:\LeCayenne\start-kiosk.bat"
```

> Ou double-clic sur l'icône Bureau « BORNE Le Cayenne ». Au boot Windows, le raccourci `shell:startup` la lance seule.

### 1.5 — Vérifier que tout est installé

```powershell
Test-Path "C:\LeCayenne\start-kiosk.bat"
Test-Path ([Environment]::GetFolderPath("Desktop")+"\BORNE Le Cayenne.lnk")
Test-Path ([Environment]::GetFolderPath("Startup")+"\BORNE Le Cayenne.lnk")
Get-ScheduledTask -TaskName "LeCayenneKioskWatchdog" | Select-Object TaskName,State
(Get-ScheduledTask -TaskName "LeCayenneKioskWatchdog" | Get-ScheduledTaskInfo).LastRunTime
(Get-ScheduledTask -TaskName "LeCayenneKioskWatchdog").Triggers.Repetition
```

**Attendu** : les 3 `Test-Path` = `True` ; tâche `State = Ready` ; `Repetition` montre `Interval = PT2M` et `Duration` non vide ; icône Bureau visible. Affichage borne après lancement : plein écran, **fond orange**, logo LE CAYENNE, carrousel produits, pill « Touchez l'écran ».

---

## 2. Pont d'impression local `bridge.js`

> **Fait capital** : le `bridge.js` de la **BORNE** (Node + `node-usb` → SK1-31) **n'est PAS dans le repo** (vérifié : `find . -name bridge.js` → 0 résultat hors `node_modules`). Il vit sur le PC borne, installé par le cowork. Le seul pont versionné est `tools/caisse-bridge/caisse-bridge.js` = pont de la **CAISSE** (transport `winspool` RAW, endpoint `POST /raw`) — **ne PAS le confondre** : ce n'est ni le bon transport ni le bon endpoint pour la borne.
>
> **Dossier de référence du pont borne** : `C:\LeCayenne\bridge\` (fichier `C:\LeCayenne\bridge\bridge.js`). On s'y tient partout dans ce doc.

### 2.1 — Localiser / vérifier le pont existant

```powershell
Get-ChildItem -Path C:\ -Recurse -Filter bridge.js -ErrorAction SilentlyContinue | Select-Object FullName
Invoke-RestMethod -Uri http://127.0.0.1:9100/health
```

> Si `/health` répond `UP`, **le pont existe déjà : NE PAS le recréer**, passer à l'autostart (§2.5).

### 2.2 — Prérequis Node + node-usb

```powershell
node --version
New-Item -ItemType Directory -Force -Path "C:\LeCayenne\bridge" | Out-Null
Set-Location "C:\LeCayenne\bridge"
npm install usb
```

> `node-usb` est un module natif (libusb). Le pont CAISSE est lui zéro-dépendance ; le pont BORNE a besoin de `node-usb`.

### 2.2bis — Lier l'imprimante au pilote WinUSB (Zadig) — **OBLIGATOIRE AVANT de lancer le pont**

> Le SK1-31 est **WinUSB-only** : il n'a **aucun pilote spooler Windows** (`docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md:12` « driver WinUSB only »). `node-usb` ne peut PAS énumérer l'imprimante tant qu'elle n'est pas liée à WinUSB → sans cette étape, §2.4/§2.6 échoueront sur `SK1-31 introuvable VID0x10C5/PID0x0007`. **C'est une étape ordonnée, pas une option.**

1. Télécharger **Zadig** (https://zadig.akeo.ie) et le lancer **en administrateur**.
2. Menu **Options → List All Devices**.
3. Sélectionner l'imprimante **SK1-31** (VID `0x10C5` / PID `0x0007`) dans la liste déroulante.
4. Choisir le pilote **WinUSB** dans la cible, puis **Replace Driver** / **Install Driver**.
5. Vérifier dans le Gestionnaire de périphériques que le device apparaît sous **WinUSB**, ou en PowerShell :

```powershell
Get-PnpDevice | Where-Object { $_.InstanceId -like '*VID_10C5*PID_0007*' } | Select-Object FriendlyName,Status,Class
```

**Attendu** : une ligne avec `Status = OK` (le périphérique est lié à WinUSB). Si rien ne ressort, l'imprimante n'est pas branchée/allumée ou Zadig n'a pas été appliqué.

### 2.3 — (Re)CRÉER `bridge.js` SEULEMENT s'il a disparu

> Reconstruction de secours, à enregistrer sous `C:\LeCayenne\bridge\bridge.js`. Honnêteté : `renderTicket()` est **verbatim** du repo (`docs/runbooks/BORNE_BRIDGE_BIGGER_TEXT.md`), le serveur HTTP+CORS/PNA est calqué sur `caisse-bridge.js`, mais la partie `node-usb` (`writeUsb`) **N'EST PAS dans le repo** — c'est l'API node-usb standard + le VID/PID documenté `0x10C5/0x0007`. **À valider sur la vraie imprimante** (endpoint OUT, `claim` peuvent varier selon le firmware SK1-31). **[à confirmer live]**

```javascript
// === C:\LeCayenne\bridge\bridge.js — RECONSTRUCTION de secours (à valider sur la vraie SK1-31) ===
const http = require('http');
const usb  = require('usb');
const PORT = 9100, VID = 0x10C5, PID = 0x0007;   // SK1-31
const ESC=0x1B, GS=0x1D, LF=0x0A; const B=(...a)=>Buffer.from(a);
const SIZE_NORM=0x00;
function asciiFold(s){return String(s==null?'':s).normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/€/g,'EUR').replace(/[^\x20-\x7E]/g,' ').replace(/[ \t]{2,}/g,' ').trim();}
function renderTicket(p){const out=[];const size=n=>out.push(B(GS,0x21,n));const align=n=>out.push(B(ESC,0x61,n));const bold=o=>out.push(B(ESC,0x45,o?1:0));const text=s=>out.push(Buffer.from(asciiFold(s)+'\n','binary'));const feed=n=>out.push(B(...Array(n).fill(LF)));
  const bodySize=Number.isInteger(p.bodySize)?p.bodySize:0x01;const titleSize=Number.isInteger(p.titleSize)?p.titleSize:0x11;
  out.push(B(ESC,0x40));align(1);bold(1);size(titleSize);text(p.title||'LE CAYENNE');size(SIZE_NORM);bold(0);
  if(p.subtitle)text(p.subtitle);
  if(p.order){bold(1);size(titleSize);text('Commande '+p.order);size(SIZE_NORM);bold(0);}
  text('--------------------------------');align(0);size(bodySize);
  for(const l of (p.lines||[]))text(l);size(SIZE_NORM);text('--------------------------------');
  if(p.total){bold(1);size(bodySize);text('TOTAL: '+p.total);size(SIZE_NORM);bold(0);}
  align(1);if(p.footer)text(p.footer);feed(3);out.push(B(GS,0x56,0));return Buffer.concat(out);}
function writeUsb(buf){return new Promise((resolve,reject)=>{const d=usb.findByIds(VID,PID);if(!d)return reject(new Error('SK1-31 introuvable VID0x10C5/PID0x0007'));try{d.open();const i=d.interfaces[0];if(i.isKernelDriverActive&&i.isKernelDriverActive()){try{i.detachKernelDriver();}catch(_){}}i.claim();const ep=i.endpoints.find(e=>e.direction==='out');ep.transfer(buf,err=>{try{i.release(true,()=>{try{d.close();}catch(_){}err?reject(err):resolve();});}catch(_){try{d.close();}catch(__){}err?reject(err):resolve();}});}catch(e){reject(e);}});}
function cors(res){res.setHeader('Access-Control-Allow-Origin','*');res.setHeader('Access-Control-Allow-Methods','GET,POST,OPTIONS');res.setHeader('Access-Control-Allow-Headers','Content-Type');res.setHeader('Access-Control-Allow-Private-Network','true');}
http.createServer((req,res)=>{cors(res);if(req.method==='OPTIONS'){res.writeHead(204);return res.end();}
  if(req.method==='GET'&&req.url.startsWith('/health')){res.writeHead(200);return res.end('UP');}
  if(req.method==='POST'&&req.url==='/'){const c=[];req.on('data',x=>c.push(x));req.on('end',async()=>{try{const p=JSON.parse(Buffer.concat(c).toString('utf8'));await writeUsb(renderTicket(p));res.writeHead(200);res.end('PRINTED');}catch(e){console.error('[borne]',e.message);res.writeHead(500);res.end(String(e.message));}});return;}
  res.writeHead(404);res.end('not found');}).listen(PORT,'127.0.0.1',()=>console.log('[borne-bridge] http://127.0.0.1:'+PORT+' /health  POST /  (JSON ticket)'));
```

### 2.4 — Lancer le pont

```powershell
node C:\LeCayenne\bridge\bridge.js
```

> Console attendue : `[borne-bridge] http://127.0.0.1:9100 /health  POST /  (JSON ticket)`. Laisser la fenêtre ouverte (autostart = §2.5).

### 2.5 — Autostart du pont (tâche planifiée) + vérifier le flag Chrome

> L'installateur §1 met **seulement Chrome** en autostart, PAS le pont. À ajouter **explicitement**, sinon le matin après reboot la borne tourne avec le pont MORT = aucune impression.

**(A) Autostart du PONT** — tâche planifiée calquée sur le watchdog (copier-coller tel quel) :

```powershell
$ba = New-ScheduledTaskAction -Execute "node.exe" -Argument "C:\LeCayenne\bridge\bridge.js" -WorkingDirectory "C:\LeCayenne\bridge"
$bt = New-ScheduledTaskTrigger -AtLogOn
Register-ScheduledTask -TaskName "LeCayenneBorneBridge" -Action $ba -Trigger $bt -RunLevel Highest -Force | Out-Null
Get-ScheduledTask -TaskName "LeCayenneBorneBridge" | Select-Object TaskName,State
Start-ScheduledTask -TaskName "LeCayenneBorneBridge"
```

> Si `node.exe` n'est PAS dans le `PATH` système, remplacer `-Execute "node.exe"` par le chemin absolu, ex. `-Execute "C:\Program Files\nodejs\node.exe"`. **Attendu** : `State = Ready`, puis `/health = UP` (vérif §2.6).

**(B) Vérifier que le flag PNA est bien dans le lanceur** :

```powershell
Select-String -Path C:\LeCayenne\start-kiosk.bat -Pattern "LocalNetworkAccessChecks"
```

**Attendu (B)** : la ligne `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks` est trouvée.

### 2.6 — `/health` + test d'impression direct

```powershell
Invoke-RestMethod -Uri http://127.0.0.1:9100/health
```

**Attendu** : `UP`. Depuis la console F12 de la page kiosk (origine cloud) :

```javascript
await fetch('http://127.0.0.1:9100/health').then(r=>r.text())   // -> 'UP'
```

> Si `Invoke-RestMethod` répond `UP` mais le `fetch` console échoue « Permission denied to access the loopback address space » → c'est le **flag Chrome manquant** (§2.5B), pas le pont.

Test d'impression direct (endpoint borne = **`POST /` JSON**, jamais `/raw`). En PowerShell, utiliser `Invoke-RestMethod` avec le corps JSON en **guillemets simples** (préserve les `"` du JSON) :

```powershell
Invoke-RestMethod -Uri http://127.0.0.1:9100/ -Method Post -ContentType 'application/json' -Body '{"title":"LE CAYENNE","order":"A0001","lines":["1x Tacos L  7,90 EUR","  > Viande 1: Cordon Bleu. Viande 2: Tenders"],"total":"7,90 EUR","footer":"Merci !","bodySize":1,"titleSize":17}'
```

> Variante `curl.exe` si tu y tiens (échappement double-quote) :
> ```powershell
> curl.exe -X POST http://127.0.0.1:9100/ -H "Content-Type: application/json" --data-raw '{"title":"LE CAYENNE","order":"A0001","lines":["1x Tacos L  7,90 EUR"],"total":"7,90 EUR","bodySize":1,"titleSize":17}'
> ```

**Attendu** : réponse `PRINTED` + un ticket sort du SK1-31.

> **Distinction critique** : BORNE = `POST /` avec JSON `{title,subtitle,order,lines,total,footer,bodySize,titleSize}` (le pont rend JSON→ESC/POS). CAISSE = `POST /raw` avec **octets ESC/POS bruts** (`winspool`). Ne pas réutiliser le pont caisse pour la borne.

---

## 3. Réglage ticket (gros texte) + fix charabia

### 3.1 — Deux chemins distincts (ne pas confondre)

- **Chemin A — TICKET BORNE (le nôtre)** : borne (Chrome cloud) → `POST JSON` → pont local `bridge.js` (`127.0.0.1:9100`, node-usb) → SK1-31. Taille pilotée **serveur** via `bodySize`/`titleSize`, appliquée par `bridge.js` (`GS ! n`).
- **Chemin B — TICKET CAISSE/SAGA (séparé)** : Laravel → `winspool` RAW. **N'est PERTINENT que si Laravel tourne SUR le PC Windows caisse.** Le VPS cloud est Linux → `PRINT_DRIVER=windows_raw` renvoie `windows_raw_transport_requires_windows_host` (inactif). **La borne cloud n'utilise JAMAIS ce chemin.** Ne pas appliquer `PRINT_DRIVER` au VPS.

### 3.2 — Taille texte = DÉJÀ FAIT côté serveur (ne pas retoucher)

Défauts déjà déployés (vérifiés `config/printing.php:64-65`, `master.blade.php:185-186`) :

```text
body_size  = 0x01  (DOUBLE HAUTEUR, garde 32 car/ligne)  -> corps/compo
title_size = 0x11  (2x2, 16 car/ligne)                   -> en-tête + n° commande + TOTAL
```

> Injectés dans `window.foodkingConfig.borneTicket` ; portés par `app.js` (md5 bca5e5c2). **Le cowork ne touche PAS le `.env` serveur.** La taille est déjà « bien grande ».

### 3.3 — RESTE côté BORNE : que `bridge.js` APPLIQUE bien la taille (`GS ! n`)

> Le serveur envoie déjà `payload.bodySize`/`payload.titleSize`. Si `bridge.js` les **ignore** (ancienne version, taille normale `0x00`), le texte reste PETIT malgré le serveur. C'est le **seul** point d'action restant pour « gros texte ». Snippet complet : `docs/runbooks/BORNE_BRIDGE_BIGGER_TEXT.md`.

```javascript
const GS = 0x1D;
const SIZE = (n) => Buffer.from([GS, 0x21, n]);              // GS ! n
const SIZE_NORM = 0x00;
const bodySize  = Number.isInteger(payload.bodySize)  ? payload.bodySize  : 0x01;
const titleSize = Number.isInteger(payload.titleSize) ? payload.titleSize : 0x11;
// En-tête + n° commande : titleSize
write(SIZE(titleSize)); write(Buffer.from(asciiFold('Commande ' + payload.order) + '\n','binary')); write(SIZE(SIZE_NORM));
// CORPS (compo) : bodySize
write(SIZE(bodySize)); for (const l of payload.lines) write(Buffer.from(asciiFold(l)+'\n','binary')); write(SIZE(SIZE_NORM));
// Total : titleSize
write(SIZE(titleSize)); write(Buffer.from('TOTAL: '+payload.total+'\n','binary')); write(SIZE(SIZE_NORM));
```

Vérifier la taille reçue par la page (console F12 borne) :

```javascript
window.foodkingConfig.borneTicket   // attendu : {bodySize: 1, titleSize: 17}   (1=0x01, 17=0x11)
```

> Si l'objet montre bien `{bodySize:1,titleSize:17}` mais le ticket sort petit ⇒ c'est `bridge.js` qui n'applique pas `GS ! n` (relancer le pont après patch).

### 3.4 — Cause + fix du « charabia »

- **Cause** (photo owner : « áç », URL `https://.../admin/pos`, « 1/1 », colonnes collées, pas de coupe) : le ticket est sorti par **`window.print()` du navigateur** (qui ajoute URL + n° de page et casse l'encodage), **PAS** par le renderer ESC/POS (lui déjà correct).
- **Fix côté borne** : tant que le pont tourne (`/health=UP`) + le flag Chrome présent, AUCUN charabia possible. Le flux du pont est **ASCII-foldé à la source** (accents retirés, `€→EUR`) → lisible quel que soit le codepage du SK1-31 (« Mega », pas « M?ga »). En mode AUTO le front ne retombe **jamais** sur `window.print()`.
- `asciiFold` sacrifie les accents **volontairement** (V1 pragmatique) : « Méga » → « Mega » = **comportement attendu, pas un bug**. (Le chemin SAGA garde les accents via CP858, mais ce n'est pas la borne.)

---

## 4. Tests backend déterministes (CDP) + confirmation signal-jaune

> Méthode : taper le vrai backend via l'`axios` authentifié de la borne, sans subir le wizard (lent en remote). Lancer Chrome **avec le port debug** (test uniquement), puis CDP `Runtime.evaluate` (`awaitPromise:true`).

```powershell
# (1) Mettre le WATCHDOG en pause : sinon, s'il tourne pendant le test, il relance la version PROD
#     (sans port debug) dès que le Chrome debug se ferme/plante -> le port 9222 disparaît en plein test.
Disable-ScheduledTask -TaskName "LeCayenneKioskWatchdog" | Out-Null
# (2) Fermer Chrome ET vider le cache : sinon B0-B3 + signal-jaune tournent contre un app.js PÉRIMÉ (faux KO/OK).
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
$P="$env:LOCALAPPDATA\LeCayenneKiosk"
Remove-Item -Recurse -Force "$P\Default\Cache","$P\Default\Code Cache","$P\Default\Service Worker" -ErrorAction SilentlyContinue
# (3) Détecter Chrome (64-bit puis x86) et lancer AVEC le port debug.
$CHROME='C:\Program Files\Google\Chrome\Application\chrome.exe'
if(-not(Test-Path $CHROME)){ $CHROME='C:\Program Files (x86)\Google\Chrome\Application\chrome.exe' }
& $CHROME --kiosk "https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972" --user-data-dir="$P" --remote-debugging-port=9222 --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --kiosk-printing --noerrdialogs --disable-infobars
```

> **Ne pas oublier** : après les tests, §9 « Remettre la prod » **réactive** le watchdog (`Enable-ScheduledTask`) et relance le lanceur SANS port debug.

### 4.0 — B0 : LIRE LES IDs EN LIVE (obligatoire et **bloquant** — IDs auto-increment, non garantis)

> **Note de vérité (vérifié dans le seeder)** : `Tacos M = item_id 26` est le SEUL upsert explicite par id (`OwnerMenuUpdate20260623Seeder.php:96` → `upsertItem(26, 'Tacos M', …)`). **`Tacos L` (l.102) ET `Méga` (l.140) sont TOUS DEUX `upsertItem(null, …)` = upsert PAR NOM** (signature l.315 `upsertItem(?int $id, …)` → `find($id)` si fourni, sinon `where('name')`). Donc leurs item_id (annotés **97** et **104** aux l.249/246) sont **auto-increment, NON garantis** — au même titre l'un que l'autre. **B0 fait foi pour LES DEUX. Ne JAMAIS coder 97/104 en dur.**
>
> Les blocs B1/B2/B3 ci-dessous **résolvent les ids dynamiquement par NOM** depuis le store (via le helper `window.__lc` défini par B0) → robustes à toute dérive d'id. **Lancer B0 D'ABORD** (il pose `window.__lc`), après avoir passé l'accueil pour charger le menu.

```javascript
(async () => {
  const $s = document.querySelector('#app').__vue_app__.config.globalProperties.$store;
  const items = ($s.state.kioskMenu && $s.state.kioskMenu.items) || [];
  if (!items.length) return 'MENU VIDE — passe d\'abord l\'accueil (clic [data-testid=kiosk-order-type-takeaway] ou kiosk-idle-touch-btn) pour charger /api/frontend/menu, puis relance B0.';
  const item = n => items.find(i => (i.name||'') === n) || items.find(i => (i.name||'').toLowerCase().includes(n.toLowerCase()));
  // build(itemName, labels[]) : résout item + variations par NOM d'attribut (regex) -> {item_id, item_variations:[{id,variation_name,name}]}
  const build = (itemName, labels) => {
    const it = item(itemName); if (!it) return null;
    const vars = labels.map(lab => {
      const attr = (it.itemAttributes||[]).find(a => new RegExp(lab,'i').test(a.name||''));
      if (!attr) return null;
      const v = (it.variations||[]).find(x => x.item_attribute_id === attr.id);
      return v ? { id: v.id, variation_name: attr.name, name: v.name } : null;
    }).filter(Boolean);
    return { item_id: it.id, item_variations: vars };
  };
  window.__lc = { items, item, build };
  const dump = it => {
    if (!it) return '(INTROUVABLE)';
    const an = {}; (it.itemAttributes||[]).forEach(a => an[a.id] = a.name+' [min='+a.min_select+',max='+a.max_select+']');
    const g = {}; (it.variations||[]).forEach(v => { const k = an[v.item_attribute_id] || ('attr#'+v.item_attribute_id); (g[k]=g[k]||[]).push(v.id+':'+v.name); });
    return 'item_id='+it.id+'\n      '+Object.entries(g).map(([k,vs])=>k+' => '+vs.join(', ')).join('\n      ');
  };
  return 'OK __lc prêt (B1/B2/B3 résolvent par nom).\n\nTACOS L → '+dump(item('Tacos L'))+'\n\nMEGA → '+dump(item('Méga'));
})();
```

**Attendu** : `OK __lc prêt` + deux blocs `item_id=NN` avec Viande 1 / Viande 2 / Sauce (+ Type de Pain pour Méga) et leurs ids réels.

### 4.1 — B1 : Tacos L (2 viandes) doit PASSER → 200

```javascript
(async () => {
  if (!window.__lc) return 'Lance B0 d\'abord (window.__lc manquant).';
  const b = window.__lc.build('Tacos L', ['Viande 1','Viande 2','Sauce']);
  if (!b || b.item_variations.length < 3) return 'B1 SETUP KO — Tacos L/attributs introuvables, relance B0. '+JSON.stringify(b);
  const p = { branch_id:1, order_type:10, is_advance_order:2, source:1,
    items: JSON.stringify([{ item_id:b.item_id, quantity:1, item_variations:b.item_variations, item_extras:[] }]) };
  try { const r = await window.axios.post('frontend/order/quote', p);
    return 'B1 TACOS L 2-VIANDES → OK '+r.status+' total_ttc='+(r.data && r.data.data && r.data.data.total_ttc)+' | ids='+JSON.stringify(b.item_variations.map(v=>v.id)); }
  catch(e){ return 'B1 TACOS L → ECHEC '+(e.response && e.response.status)+' '+JSON.stringify(e.response && e.response.data); }
})();
```

**Attendu** : `OK 200 total_ttc=7.9`. (`items` est bien une **chaîne JSON** ; les ids sont résolus en live par B0 → pas de codage en dur.)

> **Note `order_type`** : on utilise `order_type:10` = **TAKEAWAY** (`app/Enums/OrderType.php:8`), choisi **volontairement** pour tester la contrainte de composition sans le binding KioskMachine + quote signé. Les vraies commandes borne sont `order_type:25` = **KIOSK** (`OrderType.php:11`), validées par les parcours tactiles C3–C4. B1–B3 prouvent le **pricing/contrainte**, pas le chemin kiosk complet.

### 4.2 — B2 : Méga (2 viandes + pain) doit PASSER → 200

```javascript
(async () => {
  if (!window.__lc) return 'Lance B0 d\'abord (window.__lc manquant).';
  const b = window.__lc.build('Méga', ['Viande 1','Viande 2','Sauce','Pain']);
  if (!b || b.item_variations.length < 4) return 'B2 SETUP KO — Méga/attributs introuvables, relance B0. '+JSON.stringify(b);
  const p = { branch_id:1, order_type:10, is_advance_order:2, source:1,
    items: JSON.stringify([{ item_id:b.item_id, quantity:1, item_variations:b.item_variations, item_extras:[] }]) };
  try { const r = await window.axios.post('frontend/order/quote', p);
    return 'B2 MEGA 2-VIANDES+PAIN → OK '+r.status+' total_ttc='+(r.data && r.data.data && r.data.data.total_ttc)+' | ids='+JSON.stringify(b.item_variations.map(v=>v.id)); }
  catch(e){ return 'B2 MEGA → ECHEC '+(e.response && e.response.status)+' '+JSON.stringify(e.response && e.response.data); }
})();
```

**Attendu** : `OK 200 total_ttc=8` (Méga base 8,00€).

### 4.3 — B3 : CONTRÔLE (le bug d'avant) — Tacos L SANS Viande 2 doit être REJETÉ → 422

```javascript
(async () => {
  if (!window.__lc) return 'Lance B0 d\'abord (window.__lc manquant).';
  const b = window.__lc.build('Tacos L', ['Viande 1','Sauce']);   // <-- Viande 2 volontairement OMISE
  if (!b || b.item_variations.length < 2) return 'B3 SETUP KO — Tacos L introuvable, relance B0. '+JSON.stringify(b);
  const p = { branch_id:1, order_type:10, is_advance_order:2, source:1,
    items: JSON.stringify([{ item_id:b.item_id, quantity:1, item_variations:b.item_variations, item_extras:[] }]) };
  try { const r = await window.axios.post('frontend/order/quote', p);
    return 'B3 INATTENDU: accepté '+r.status+' (le contrôle DEVRAIT renvoyer 422) → ESCALADE DEV'; }
  catch(e){ const d=(e.response&&e.response.data)||{}; return 'B3 CONTROLE OK → rejeté '+(e.response&&e.response.status)+' msg='+JSON.stringify(d.message || d.errors || d); }
})();
```

**Attendu** : `rejeté 422` avec message contenant `Sélectionnez au moins 1 Viande 2 (actuel : 0).`. Si `200` → ESCALADE au dev.

### 4.4 — Confirmer le fix « signal jaune » (aperçu 422 silencieux)

> **AVANT d'armer** : le toast est un **verrou one-shot par instance de wizard** (`_previewErrorToastShown`, `KioskWizardComponent.vue:2189`) — s'il a déjà été déclenché plus tôt dans le **même** wizard ouvert, recliquer n'en réaffiche aucun (faux PASS). **FERMER tout wizard ouvert, puis ouvrir un Tacos L NEUF** juste avant le Bloc 1. **Garder la locale FR** (la borne est FR-lock V1 ; si le sélecteur de langue `kiosk-idle-lang-selector` a été touché, repasser en Français) — le détecteur de texte ci-dessous est FR.

**Bloc 1 — ARMER** (wizard Tacos L NEUF ouvert côté UI, locale FR) :

```javascript
(() => {
  window.__sj = { previews: [], toasts: 0, t0: Date.now() };
  const O = XMLHttpRequest.prototype.open, S = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function(m,u){ this.__sju=u; return O.apply(this,arguments); };
  XMLHttpRequest.prototype.send = function(){ this.addEventListener('loadend',()=>{ if((this.__sju||'').includes('pricing/preview')) window.__sj.previews.push(this.status); }); return S.apply(this,arguments); };
  const RX = /tarif rafra[iî]chi|rafra[iî]chi localement|vérifié au paiement/i;
  window.__sjObs = new MutationObserver(ms=>{ for(const m of ms) for(const n of m.addedNodes){ const t=(n.textContent||''); if(RX.test(t)) window.__sj.toasts++; } });
  window.__sjObs.observe(document.body,{childList:true,subtree:true});
  return 'ARMED — clique UNE viande sur le Tacos L NEUF, attends 2,5 s, puis lance le bloc LECTURE.';
})();
```

**Bloc 2 — LECTURE** (après avoir cliqué 1 viande + ~2,5 s) :

```javascript
(async () => {
  await new Promise(r=>setTimeout(r,2500));
  const r = window.__sj||{}; if(window.__sjObs) window.__sjObs.disconnect();
  const noToast=(r.toasts||0)===0, has422=(r.previews||[]).includes(422);
  return 'SIGNAL-JAUNE — toasts "Tarif rafraîchi"='+(r.toasts||0)+' (attendu 0) | pricing/preview='+JSON.stringify(r.previews||[])+' | VERDICT='+(noToast ? (has422?'OK FIX CONFIRME (422 silencieux, 0 toast)':'OK 0 toast (aucun 422 capturé — recompose 1 viande sur un wizard NEUF)') : 'KO toast jaune apparu → fix absent / bundle périmé');
})();
```

**Bloc 3 — Preuve endpoint directe** (complémentaire — ne remplace PAS le Bloc 2 : il prouve le 422 serveur mais court-circuite le wizard, donc ne prouve pas la suppression du toast) :

```javascript
(async () => {
  const b = (window.__lc && window.__lc.build('Tacos L', ['Viande 1'])) || { item_id:97, item_variations:[{id:361}] };
  try { const r = await window.axios.post('frontend/pricing/preview', { items:[{ item_id:b.item_id, quantity:1, item_variations:b.item_variations, item_extras:[] }] }); return 'INATTENDU preview accepté '+r.status; }
  catch(e){ const d=(e.response&&e.response.data)||{}; return 'pricing/preview compo incomplète → '+(e.response&&e.response.status)+' (attendu 422, GERE EN SILENCE par le helper) '+JSON.stringify(d.message||d); }
})();
```

**Attendu** : VERDICT `OK FIX CONFIRME` (0 toast `Tarif rafraîchi localement`, 422 silencieux). Si KO → `app.js` borne périmé (le `.bat` vide le cache au lancement ; sinon §8).

> Le fix vit dans `kioskPricingPreview.js` (`isIncompleteComposition` : status 422 → n'appelle PAS `onError`), commit `c325a8610`. Deux endpoints distincts : **acceptation** = `frontend/order/quote` (items = chaîne JSON) ; **aperçu wizard** = `frontend/pricing/preview` (items = tableau) — c'est lui qui portait le toast (FR : `kiosk.wizard.pricing_preview_offline`, `KioskWizardComponent.vue:2201`).

---

## 5. Commandes réelles tactiles

> Sur l'écran TACTILE (geste réel). Pilotage à distance si nécessaire : cliquer `[data-testid=kiosk-order-type-takeaway]` (`KioskIdleScreenComponent.vue:213`) via `.click()`, **jamais** `synthesizeTapGesture` ni `body`/`span`. **Faire C3–C6 (multi-viandes) en tactile physique** (le wizard est lent en remote ; la Partie B prouve déjà l'acceptation backend).

Lecture du n° de commande (**Plan B d'abord** : la borne atterrit sur l'écran « à régler en caisse » = cash-instruction, PAS sur `/confirmation`) :

```javascript
document.querySelector('[data-testid=kiosk-cash-order-number]')?.textContent?.trim()
  || document.querySelector('[data-testid=kiosk-confirmation-number]')?.textContent?.trim();
```

> Les vrais testids : `kiosk-cash-order-number` (`KioskCashInstructionComponent.vue:19`, écran Plan B réellement atteint) et `kiosk-confirmation-number` (`KioskConfirmationComponent.vue:34`, en secours). **Ne PAS** utiliser de fallback regex sur `querySelectorAll('*')` : il renvoie le `textContent` de la page entière (`<html>`/`<body>`), pas le numéro.

| # | Scénario | Étapes tactiles | Vérifs ticket / KDS | Photos attendues |
|---|---|---|---|---|
| **C1** | Coca-Cola seule (0 compo) | Accueil → `kiosk-order-type-takeaway` → Boissons → Coca-Cola → Valider → confirmer | Ticket CLIENT 1 ligne nom+prix, **n° GROS/double-hauteur** ; pas de bloc compo ; pas de ticket cuisine. Toast jaune absent. | Ticket client |
| **C2** | Cheese Burger : sauce + crudités (sans formule) | Burgers → Cheese Burger → Sauce=Samouraï → Salade+Tomate+Oignon → SANS formule → Ajouter → Valider | CLIENT : sauce + 3 crudités en toutes lettres. CUISINE (symbolique) : `… | STO | SAM`, pas de L3. KDS = mêmes symboles. | Client + cuisine + KDS |
| **C3** | Tacos L : 2 viandes DIFFÉRENTES + sauce (le P0 corrigé) | (a) preuve quote B1 (200) puis (b) refaire en tactile | CLIENT : 2 viandes **distinctes** (pas dupliquées). CUISINE L1 : 2 symboles viande différents. | Client + cuisine + KDS |
| **C4** | Méga : 2 viandes + sauce + Type de Pain | preuve quote B2 (200) + contrôle B3 (422) puis tactile | CLIENT : 2 viandes + pain (Galette). CUISINE L1 : 2 viandes + support + sauce. | Client + cuisine + KDS |
| **C5** | Multi-produits + quantités (Méga + 2× Tacos M item 26 + Coca) | composer Méga → ajouter ; Tacos M ×2 ; Coca ×1 → Valider | **Tous** les articles, quantités (`2x Tacos M`), total = somme exacte, rien doublé/oublié. KDS : 2 Tacos M **fusionnés** en 1 carte « 2x ». | Ticket + KDS |
| **C6** | 3 commandes rapides → n° séquentiels | passer 3× Coca d'affilée, noter chaque n° | N° monotones sans trou (A0001→A0003), 3 tickets distincts, 3 cartes KDS dans l'ordre. | 3 tickets |

Fusion qty au panier (vérif avant validation C5) :

```javascript
document.querySelector('#app').__vue_app__.config.globalProperties.$store.state.kioskCart?.items?.map(i=>i.name+' x'+i.quantity);
```

> Le fix multi-viandes vit dans le wizard frozen `buildCartItem` (`KioskWizardComponent.vue`, distribue chaque viande sur son attribut Viande N) — LOCK 2-commits, ne pas revert.

---

## 6. Caisse (encaissement + choix d'impression)

> **PRÉREQUIS — D1 à D3 supposent que le PC CAISSE et son propre pont caisse sont déjà installés et lancés** : `tools/caisse-bridge/caisse-bridge.js` (transport `winspool`, **`POST /raw`**, `127.0.0.1:9100` **sur le PC caisse** — machine SÉPARÉE, hors périmètre de cette mission borne). **Si la caisse n'est pas encore en place, SAUTER §6** : la borne s'arrête volontairement sur « à régler en caisse » (Plan B, `config/kiosk.php:54` `payment_route_all_to_counter=true`) — la valider en **borne-seule** suffit pour cette mission. Ne pas croire que la borne est en panne parce qu'elle ne va pas plus loin.

```powershell
# Sur le PC CAISSE : santé du pont caisse
Invoke-RestMethod -Uri http://127.0.0.1:9100/health
```

| Étape | Action | Vérifs |
|---|---|---|
| **D1 — Espèces** | `/admin/pos` (compte caisse) → repérer la commande borne (bon n°, total, compo) → Encaisser → **ESPÈCES** → valider | Reçu fiscal CLIENT imprimé (SAGA, silencieux via `/raw`) + **tiroir s'ouvre** + n° fiscal présent et incrémenté. Photo reçu + n° fiscal. |
| **D2 — Carte** | 2e commande → Encaisser → **CARTE** (ou ticket resto) → valider | Reçu imprimé, **tiroir NE s'ouvre PAS**. Comparer n° fiscaux D1 vs D2 = monotones sans trou (ex. 2571→2572). TPE simulé en V1 (choix assumé). |
| **D3 — Choix d'impression + anti-double** | Sur l'écran reçu, boutons `[data-testid=receipt-print-client]`, `[data-testid=receipt-print-kitchen]`, les deux | CLIENT seul / CUISINE seul / LES DEUX à la demande, **silencieux** (`/raw`). Re-cliquer le même bouton immédiatement → **pas de 2e ticket** (garde `receipt_print_count`). |

> Ticket cuisine cible une station `kitchen_hot/kitchen_cold` ; fallback sur la SAGA receipt si aucune station cuisine active (`app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php`). Si une **fenêtre Chrome d'impression apparaît** → pont `/raw` absent ou flag PNA manquant (fallback `window.print`).

---

## 7. KDS + robustesse (watchdog + boot)

### 7.1 — KDS temps réel + fusion

Ouvrir `/kds` (compte cuisine). Après une commande borne, chronométrer l'apparition de la carte ; comparer côte à côte écran KDS et ticket cuisine papier (mêmes lettres, ordre STO).

> Sur le cloud, le WebSocket renvoie 401 (NORMAL) → synchro par **polling** (`KdsSyncService.js:29-34`) : haute activité 3000ms+1000ms (≤4s), dégradé 5000ms+2000ms, déconnecté-idle 10000ms+3000ms. Donc **≤5s** juste après une commande ; si le KDS sort d'une longue inactivité, la 1ʳᵉ carte peut arriver jusqu'à ~10-13s puis redescend — **le noter, ce n'est pas un bug**. Vérifier la **fusion** qty (2× Tacos C5 = une carte « 2x »).

### 7.2 — E2 : Watchdog (fermer Chrome → relance ≤2 min)

```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Get-ScheduledTask -TaskName 'LeCayenneKioskWatchdog' | Select-Object TaskName,State
```

**Attendu** : Chrome relancé seul en plein écran en **≤2 min** (tâche `LeCayenneKioskWatchdog`, intervalle 2 min). *(Si tu viens de faire §4, le watchdog était en pause — il doit avoir été réactivé par §9 avant ce test.)*

> Limite : le watchdog teste seulement « un process chrome existe-t-il ». Si une **autre** fenêtre Chrome est ouverte sur ce PC (caisse/KDS), il ne relance pas. Sur une borne dédiée, ne pas laisser d'autre Chrome ouvert.

### 7.3 — E3 : Boot Windows (la borne s'ouvre seule + le pont remonte)

```powershell
Restart-Computer
```

**Attendu après retour** :
1. Le raccourci `Startup` lance `start-kiosk.bat` → Chrome `--kiosk` plein écran sur l'accueil orange + carrousel (cache vidé → toujours la dernière version).
2. La tâche `LeCayenneBorneBridge` (déclencheur **At Log On**) relance le pont. **Re-vérifier explicitement** (sinon la borne peut être EN PRODUCTION avec le pont MORT) :

```powershell
Get-ScheduledTask -TaskName "LeCayenneBorneBridge" | Select-Object TaskName,State
Invoke-RestMethod -Uri http://127.0.0.1:9100/health
Invoke-RestMethod -Uri http://127.0.0.1:9100/ -Method Post -ContentType 'application/json' -Body '{"title":"LE CAYENNE","order":"BOOT","lines":["Test post-reboot"],"total":"0,00 EUR","bodySize":1,"titleSize":17}'
```

**Attendu (2)** : `State = Ready`, `/health = UP`, `PRINTED` + un ticket sort. **Ne déclarer OUI que si le ticket sort réellement.** Si écran blanc : vider cache/Service Worker (§8), ne **pas** re-patcher de JS.

---

## 8. Dépannage

| Symptôme | Cause | Fix |
|---|---|---|
| Chrome ne se lance pas / « Windows ne trouve pas 'C:\Program' » | `%CHROME%` non guillemeté dans le `.bat` (ancienne version) | Réexécuter le bloc §1.2 corrigé (`"%CHROME%"` dans les 2 lignes). |
| Configurateur Tacos vide / « ChunkLoadError » | Cache Chrome borne sur un vieux `app.js` (les chunks sont 200/JS md5-identiques sur le VPS) | `start-kiosk.bat` vide le cache à chaque ouverture. Débloquer : §1.1 (Stop-Process + Remove-Item) puis relancer. Si persiste : F12 → Network → nom du `/js/*.js` en rouge + code + Content-Type → dev. |
| Aucun ticket ; console « Local Network Access » / « loopback blocked » | Flag PNA manquant. Chrome bloque public-HTTPS → `127.0.0.1` ; le header PNA seul ne suffit pas | Lanceur DOIT contenir `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`. Vérifier aussi `Invoke-RestMethod http://127.0.0.1:9100/health → UP`. |
| `/health` ne répond pas / « Failed to fetch » sans loopback | Pont `bridge.js` non démarré, planté, ou port 9100 pris | Relancer la tâche (`Start-ScheduledTask LeCayenneBorneBridge`) ou `node C:\LeCayenne\bridge\bridge.js`. `netstat -ano | findstr :9100` → tuer le PID si squatté. |
| `/health=UP` mais rien n'imprime | SK1-31 non énumérée par node-usb (WinUSB non lié) / mauvais VID-PID / device pris | Lier WinUSB via **Zadig** (§2.2bis) ; confirmer VID `0x10C5`/PID `0x0007` (`Get-PnpDevice … VID_10C5*PID_0007`) ; fermer tout autre process tenant le device. |
| Octets bruts / `/raw` au pont borne → 404 / rien | Confusion borne↔caisse | Borne = `POST /` JSON. `/raw` + octets = contrat **caisse** (`caisse-bridge.js`, winspool). |
| Ticket en charabia (`áç`, URL, `1/1`, colonnes collées) | Sorti par `window.print()` navigateur (pont injoignable / flag absent) | Garder le pont up + flag PNA. Via le pont, `asciiFold` → jamais de mojibake. En AUTO le front ne retombe plus sur `window.print()`. |
| Texte ticket reste PETIT malgré le serveur | `bridge.js` n'applique pas `GS ! n` (n'a pas lu `bodySize/titleSize`) | Patcher `bridge.js` (§3.3) + relancer le pont. Vérifier `window.foodkingConfig.borneTicket` = `{bodySize:1,titleSize:17}`. |
| « Méga » imprimé « Mega » (accents perdus) | `asciiFold` retire les diacritiques (codepage SK1-31 imprévisible) | Comportement V1 **attendu**, pas un bug. |
| `PRINT_DRIVER=windows_raw` « ne fait rien » / `requires_windows_host` | Confusion : `windows_raw`/SAGA = chemin CAISSE (Laravel sur Windows). Le VPS est Linux | Ne PAS toucher `PRINT_DRIVER` côté borne/VPS. La borne imprime par le pont local. |
| Toast jaune « Tarif rafraîchi localement » pendant la compo | Avant le fix, un aperçu 422 (compo incomplète = attendu) déclenchait `onError` | Déjà corrigé/déployé (`app.js` bca5e5c2). CONFIRMER absence du toast (§4.4, **wizard NEUF + locale FR**). S'il réapparaît → bundle borne périmé → vider cache. |
| Double ticket (F5 / re-montage / watchdog) | Re-déclenchement de l'auto-print | Garde déjà en place (`markPrintedOnce`, localStorage clé `order#|jour`, anti-F5/anti-rollover). Si persiste → bundle périmé → vider cache. |
| Clic à distance sans effet / wizard figé | `synthesizeTapGesture` échoue en remote ; ou clic sur `body`/`span` | `.click()` (Runtime.evaluate) ou `Input.dispatchMouseEvent` sur l'élément EXACT (`[data-testid=kiosk-order-type-takeaway]`). C3–C6 en tactile physique. |
| `curl` PowerShell → « parameter 'X' not found » / objet au lieu de `UP` | `curl` = alias `Invoke-WebRequest` en PS 5.1 | Utiliser `Invoke-RestMethod` (renvoie `UP`) ou le binaire `curl.exe`. |
| `WebSocket` / `kiosk-event` 401 en boucle | Synchro KDS par polling, WS non authentifié côté borne | **NORMAL, à IGNORER.** La commande arrive quand même au KDS (3-5s). |
| Écran BLANC | Côté borne : cache incohérent (Service Worker/Code Cache) ou perte réseau VPS. (Le cas « app.js neuf + vendor.js vieux » = problème de **déploiement VPS**, pas la borne.) | Vider Cache/Code Cache/Service Worker + relancer (bloc §8 ci-dessous). Vérifier l'accès VPS. Si persiste après cache OK + réseau OK → escalader au dev (bundle VPS). |
| Wizard revient seul à l'accueil en remote | Clics CDP ne réarment pas toujours le timer d'inactivité du wizard (côté front) | En tactile physique le toucher réarme l'activité. En remote : agir vite, privilégier les quotes backend. |
| Borne reste sur « à régler en caisse », pas de `/confirmation` | Plan B : la borne ne prend pas le paiement | Comportement attendu (`config/kiosk.php:54`). Encaissement à `/admin/pos`. Lire le n° via `[data-testid=kiosk-cash-order-number]`. |
| Port 9222 reste ouvert / watchdog relance la PROD pendant le test | Session §4 mal refermée (watchdog non remis en pause, ou prod non remise) | Exécuter §9 (REMETTRE LA PROD) : ferme Chrome, réactive le watchdog, relance `start-kiosk.bat` sans port debug. |

Forcer le rechargement du dernier bundle (signal-jaune persistant / vieux app.js / écran blanc) :

```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
$P="$env:LOCALAPPDATA\LeCayenneKiosk"
Remove-Item -Recurse -Force "$P\Default\Cache","$P\Default\Code Cache","$P\Default\Service Worker" -ErrorAction SilentlyContinue
Start-Process "C:\LeCayenne\start-kiosk.bat"
```

> Pas de `Ctrl+Shift+R` en mode kiosk (pas de clavier) → passer par cette relance.

---

## 9. Remettre la prod

Après TOUTE session de test pilotée en CDP (le port 9222 ne doit JAMAIS rester en prod, le watchdog DOIT être réactivé) :

```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Enable-ScheduledTask -TaskName "LeCayenneKioskWatchdog" | Out-Null
Get-ScheduledTask -TaskName "LeCayenneKioskWatchdog" | Select-Object TaskName,State
Start-Process "C:\LeCayenne\start-kiosk.bat"
```

> `start-kiosk.bat` relance Chrome `--kiosk` **sans** `--remote-debugging-port`, avec le flag PNA, et vide le cache. Vérifier que la tâche watchdog est `Ready` et qu'aucun process n'écoute sur 9222 (`netstat -ano | findstr :9222` → vide).

---

## Rapport final attendu

Cocher OUI/NON et joindre les photos. Tout doit être **OUI**.

| # | Vérification | Attendu | OUI/NON | Preuve / photo |
|---|---|---|---|---|
| 1 | Bloc install (§1.2) | `INSTALL OK : lanceur + icone bureau + demarrage auto + watchdog crees.` | ☐ | capture console |
| 2 | `Test-Path C:\LeCayenne\start-kiosk.bat` | `True` | ☐ | |
| 3 | Icône Bureau « BORNE Le Cayenne.lnk » | `True` + visible | ☐ | photo bureau |
| 4 | Raccourci `shell:startup` | `True` | ☐ | |
| 5 | `Get-ScheduledTask LeCayenneKioskWatchdog` + `Triggers.Repetition` | `State = Ready` + `Interval=PT2M`, `Duration` non vide | ☐ | |
| 6 | `Invoke-RestMethod …:9100/health` | `UP` | ☐ | capture |
| 7 | `fetch('…:9100/health')` console borne | `UP` (pas d'erreur loopback) | ☐ | |
| 8 | Flag PNA dans le `.bat` (`Select-String LocalNetworkAccessChecks`) | ligne trouvée | ☐ | |
| 9 | `window.foodkingConfig.borneTicket` | `{bodySize:1, titleSize:17}` | ☐ | |
| 10 | Test print direct (§2.6) | `PRINTED` + ticket gros, pas d'URL/n° page | ☐ | photo ticket |
| 11 | Imprimante liée WinUSB (§2.2bis) | `Get-PnpDevice …VID_10C5*PID_0007` → `Status OK` | ☐ | capture |
| 12 | Affichage borne | plein écran, fond orange, logo, carrousel, pill « Touchez l'écran » | ☐ | photo écran |
| 13 | B0 IDs live (`__lc` prêt) | `item_id` Tacos L + Méga + variations par attribut | ☐ | capture |
| 14 | B1 quote Tacos L 2 viandes | `OK 200` total_ttc ≈ 7.9 | ☐ | capture |
| 15 | B2 quote Méga 2 viandes + pain | `OK 200` total_ttc ≈ 8.0 | ☐ | capture |
| 16 | B3 contrôle Tacos L sans Viande 2 | `rejeté 422` « Viande 2 (actuel : 0) » | ☐ | capture |
| 17 | Signal-jaune (wizard NEUF, FR, 1 viande) | 0 toast « Tarif rafraîchi » ; preview 422 silencieux ; `OK FIX CONFIRME` | ☐ | capture |
| 18 | C1 Coca seule | ticket 1 ligne, n° GROS, pas de cuisine | ☐ | photo |
| 19 | C2 Cheese Burger | crudités+sauce en toutes lettres ; cuisine `STO|SAM` | ☐ | photos |
| 20 | C3 Tacos L 2 viandes | 2 viandes distinctes (client+cuisine) | ☐ | photos |
| 21 | C4 Méga 2 viandes + pain | 2 viandes + Galette | ☐ | photos |
| 22 | C5 multi-produits | tous articles, `2x Tacos M`, total exact ; KDS fusionné | ☐ | photos |
| 23 | C6 3 commandes rapides | n° séquentiels sans trou ; 3 cartes KDS | ☐ | photos |
| 24 | D1 encaisser ESPÈCES (si caisse en place) | reçu fiscal + tiroir s'ouvre + n° fiscal++ | ☐ | photo reçu + n° |
| 25 | D2 encaisser CARTE (si caisse en place) | reçu, tiroir fermé | ☐ | photo |
| 26 | D3 n° fiscal D1 vs D2 | monotones sans trou (noter les 2) | ☐ | valeurs |
| 27 | D3 choix impression + anti-double | CLIENT/CUISINE/LES DEUX silencieux ; re-clic = pas de 2e | ☐ | photos |
| 28 | KDS temps réel | carte ≤5s (haute activité) ; symboles == ticket cuisine | ☐ | photo |
| 29 | Watchdog (Alt+F4) | relance plein écran ≤2 min | ☐ | photo |
| 30 | Boot Windows | borne s'ouvre seule plein écran orange | ☐ | photo |
| 31 | Pont autostart après reboot (§7.3) | `LeCayenneBorneBridge` Ready + `/health=UP` + test-print BOOT sort | ☐ | photo ticket |
| 32 | Remettre la prod (§9) | watchdog **réactivé** (`State=Ready`) ; aucun process sur 9222 ; Chrome via `start-kiosk.bat` | ☐ | capture |

**Escalades au dev (ne PAS corriger côté borne)** : tout B3 qui renvoie 200 ; écran blanc persistant après cache vidé + réseau OK ; charabia persistant alors que `/health=UP` + flag présent. **[à confirmer live]** : `item_id` réels de Tacos L ET Méga (tous deux upsert par nom → B0 fait foi) ; énumération USB/endpoint OUT du SK1-31 si reconstruction du pont (§2.3) ; valeur `machine_key` (enregistrement DB du VPS, non vérifiable depuis le repo — valider via §0 étape 5).