# MISSION MÉGA — COWORK : configuration + validation TOTALE de la borne

> À coller à Claude cowork (AnyDesk, poste BORNE Le Cayenne). Mission finale, complète :
> installation + tests UI réels + tests backend déterministes (quote) + caisse + robustesse.
> Le dev a corrigé le P0 multi-viandes ; tout est déployé. Objectif : tout valider.

---

## RAPPELS CLÉS (lis d'abord)
- **Méthode de clic** : pilote par **`.click()` JS** (`Runtime.evaluate`) ou `Input.dispatchMouseEvent` (souris). **JAMAIS** `synthesizeTapGesture` (geste tactile — échoue à distance).
- **WebSocket 401 en console = NORMAL**, ignore (synchro KDS par polling).
- **Ne modifie aucun fichier serveur ni `.env`.**

---

## PARTIE A — CONFIGURATION (installer la borne « en fer »)

Si pas déjà fait, dans PowerShell sur la borne, colle ce bloc (lanceur + icône + démarrage auto + watchdog ; vide le cache à chaque ouverture → prend toujours la dernière version) :

```powershell
$dir = "C:\LeCayenne"; New-Item -ItemType Directory -Force -Path $dir | Out-Null
$bat = @'
@echo off
set "CHROME=C:\Program Files\Google\Chrome\Application\chrome.exe"
if not exist %CHROME% set "CHROME=C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
set "URL=https://vps-418872ac.vps.ovh.net/kiosk?machine_key=lcb-227b5373163391c875eeb43f7ee1affe3972"
set "PROFILE=%LOCALAPPDATA%\LeCayenneKiosk"
rmdir /s /q "%PROFILE%\Default\Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Code Cache" 2>nul
rmdir /s /q "%PROFILE%\Default\Service Worker" 2>nul
start "" %CHROME% --kiosk "%URL%" --user-data-dir="%PROFILE%" --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks --kiosk-printing --disable-pinch --overscroll-history-navigation=0 --disable-session-crashed-bubble --noerrdialogs --disable-infobars --disable-translate --no-first-run --hide-crash-restore-bubble
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
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2)
Register-ScheduledTask -TaskName "LeCayenneKioskWatchdog" -Action $action -Trigger $trigger -RunLevel Highest -Force | Out-Null
Write-Host "INSTALL OK"
```

Pré-requis impression : `bridge.js` tourne (`curl http://127.0.0.1:9100/health` → `UP`, en démarrage auto), SK1-31 (borne) + SAGA (caisse) branchées + papier + tiroir. (Gros texte ticket : runbook `BORNE_BRIDGE_BIGGER_TEXT.md`.)

---

## PARTIE B — TESTS BACKEND DÉTERMINISTES (quote — FIABLE, sans subir le wizard)

Le dev a corrigé le P0 « multi-viandes incommandables ». **Confirme-le de façon
déterministe** : lance la borne, et dans la console (CDP `Runtime.evaluate`, `awaitPromise:true`)
exécute ces appels au vrai backend via l'axios authentifié de la borne.

**B1 — Tacos L (2 viandes) doit PASSER (HTTP 200) :**
```js
(async () => {
  const b = (document.querySelector('#app').__vue_app__.config.globalProperties.$store.state.kioskCart?.branchId) || 1;
  const p = { branch_id:b, order_type:10, is_advance_order:2, source:1, items: JSON.stringify([{ item_id:97, quantity:1,
    item_variations:[{id:361,variation_name:'Viande 1',name:'Mexicanos'},{id:370,variation_name:'Viande 2',name:'Viande Hachée'},{id:379,variation_name:'Sauce (1ère Gratuite)',name:'Samouraï'}], item_extras:[] }]) };
  try { const r=await window.axios.post('frontend/order/quote', p); return 'TACOS L 2-VIANDES → OK '+r.status+' total='+(r.data?.data?.total_ttc); }
  catch(e){ return 'TACOS L → ÉCHEC '+e.response?.status+' '+JSON.stringify(e.response?.data?.errors); }
})();
```
**B2 — Méga (2 viandes + pain) doit PASSER (HTTP 200) :**
```js
(async () => {
  const b = (document.querySelector('#app').__vue_app__.config.globalProperties.$store.state.kioskCart?.branchId) || 1;
  const p = { branch_id:b, order_type:10, is_advance_order:2, source:1, items: JSON.stringify([{ item_id:104, quantity:1,
    item_variations:[{id:469,variation_name:'Viande 1',name:'Mexicanos'},{id:478,variation_name:'Viande 2',name:'Viande Hachée'},{id:485,variation_name:'Sauce (1ère Gratuite)',name:'Mayonnaise'},{id:484,variation_name:'Type de Pain',name:'Galette'}], item_extras:[] }]) };
  try { const r=await window.axios.post('frontend/order/quote', p); return 'MÉGA 2-VIANDES → OK '+r.status+' total='+(r.data?.data?.total_ttc); }
  catch(e){ return 'MÉGA → ÉCHEC '+e.response?.status+' '+JSON.stringify(e.response?.data?.errors); }
})();
```
**B3 — Contrôle (le bug d'avant) : Tacos L SANS Viande 2 doit être REJETÉ (422) :**
```js
(async () => {
  const b = (document.querySelector('#app').__vue_app__.config.globalProperties.$store.state.kioskCart?.branchId) || 1;
  const p = { branch_id:b, order_type:10, is_advance_order:2, source:1, items: JSON.stringify([{ item_id:97, quantity:1,
    item_variations:[{id:361,variation_name:'Viande 1',name:'Mexicanos'},{id:379,variation_name:'Sauce (1ère Gratuite)',name:'Samouraï'}], item_extras:[] }]) };
  try { const r=await window.axios.post('frontend/order/quote', p); return 'INATTENDU: accepté '+r.status; }
  catch(e){ return 'CONTRÔLE OK → rejeté '+e.response?.status+' '+JSON.stringify(e.response?.data?.errors); }
})();
```
**Attendu :** B1 = OK 200, B2 = OK 200, B3 = rejeté 422 « Viande 2 (actuel: 0) ». → le fix est confirmé côté backend. (Dev l'a déjà prouvé en local — toi tu reconfirmes sur la borne.)

---

## PARTIE C — COMMANDES RÉELLES (UI, écran TACTILE = le mieux ; sinon `.click()`)

Sur l'écran **tactile** (le wizard est fluide en physique, pas en remote) :
| # | Produit | À vérifier |
|---|---|---|
| C1 | **Coca-Cola** (simple) | commande passe, n° gros sur le ticket |
| C2 | **Cheese Burger** : 1 sauce + crudités | ticket : sauce + crudités écrites |
| C3 | **Tacos L : 2 viandes DIFFÉRENTES + 1 sauce** | commande PASSE (plus d'erreur Viande 2) ; ticket : **les 2 viandes** |
| C4 | **Méga : 2 viandes + sauce + pain** | commande PASSE ; ticket : 2 viandes + pain |
| C5 | **Multi-produits** : Méga + 2× Tacos M + Coca | tout présent, quantités (2x), rien doublé |
| C6 | **3 commandes rapides** | n° séquentiels sans trou, toutes au KDS |

Pour chaque : **photo du ticket CLIENT** (détaillé) + **du ticket CUISINE** (symbolique 3 lignes) + **de l'écran KDS** (mêmes symboles que le ticket cuisine, 1 item fusionné).

> Si à distance le wizard est trop lent (cartes qui tardent, renderer figé) : c'est un effet
> du remote, pas un bug — la PARTIE B prouve déjà l'acceptation backend. Fais alors C1/C2 via
> `.click()` et laisse C3-C6 (multi-viandes) au test tactile sur place.

---

## PARTIE D — CAISSE (encaissement + choix d'impression)
Sur `https://vps-418872ac.vps.ovh.net/admin/pos` (compte caisse) :
- La commande borne **apparaît** (bon n°, bon total, bonne compo).
- **Encaisser** (espèces) → **reçu imprimé** (SAGA) + **tiroir s'ouvre** + n° fiscal qui s'incrémente.
- Encaisser (carte) → reçu, tiroir fermé.
- **Choix d'impression** : imprimer le ticket **CLIENT** seul, le **CUISINE** seul, puis **LES DEUX**. 📸

---

## PARTIE E — SYNCHRO + ROBUSTESSE
- **KDS** : ouvre `https://vps-418872ac.vps.ovh.net/kds` (compte cuisine) OU l'owner regarde l'écran cuisine → les commandes apparaissent en ≤ 5 s, en ordre, 1 item fusionné. 📸
- **Watchdog** : ferme Chrome (Alt+F4) → relancé seul ≤ 2 min. 📸
- **Boot** : redémarre Windows → la borne s'ouvre seule en plein écran. 📸

---

## PARTIE F — REMETTRE LA PROD (si tu as utilisé un port debug)
```powershell
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Process "C:\LeCayenne\start-kiosk.bat"
```

---

## RAPPORT FINAL (tableau + photos)
| Point | Résultat |
|---|---|
| INSTALL OK (icône + démarrage auto + watchdog) | OUI/NON |
| B1 Tacos L 2-viandes quote 200 | OUI/NON |
| B2 Méga 2-viandes quote 200 | OUI/NON |
| B3 contrôle (sans Viande 2) rejeté 422 | OUI/NON |
| C1-C6 commandes passées (n°) | … |
| Ticket client détaillé + n° gros | OUI/NON 📸 |
| Ticket cuisine symbolique 3 lignes | OUI/NON 📸 |
| KDS ≤5s, 1 item fusionné, mêmes symboles | OUI/NON 📸 |
| Caisse : encaisser → reçu + tiroir + fiscal++ | OUI/NON 📸 |
| Choix impression client / cuisine / les deux | OUI/NON 📸 |
| Watchdog + boot auto | OUI/NON 📸 |
| Mode prod remis | OUI/NON |

Tout problème = photo + n° de commande + erreur console exacte.
