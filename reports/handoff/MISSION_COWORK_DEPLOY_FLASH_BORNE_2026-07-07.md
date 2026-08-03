# MISSION COWORK — Déployer tout + tuer le « flash terminal » + borne qui relance l'ancienne version

> **Pour** : Claude cowork (accès VPS + machines caisse/borne/cuisine, que l'orchestrateur n'a PAS).
> **Date** : 2026-07-07 · **HEAD à déployer** : `0da7cd580` (branche `pos/category-first-caisse-2026-06-23`, poussée).
> **3 objectifs owner** : (A) déployer TOUT, (B) supprimer le flash du terminal à chaque commande (caisse ET borne), (C) la borne relance l'ANCIENNE version au redémarrage.

Les 3 problèmes ont **une cause racine commune côté machines** : l'auto-démarrage
du pont n'est pas le bon (ancienne tâche planifiée / .bat / `powershell -WindowStyle Hidden`
qui **flashe**), et il lance un **ancien** pont + une **page Chrome en cache**. Le fix
(lanceurs sans fenêtre + purge cache) existe DÉJÀ dans le repo — il faut l'**appliquer sur les machines**.

---

## PARTIE A — DÉPLOYER TOUT (VPS)

Tout le code est poussé jusqu'à `0da7cd580`. Sur le VPS (connexion : cf.
`reports/handoff/DEPLOY_RUNBOOK_2026-07-02.md`), dans le dossier du projet :

```bash
git fetch origin && git reset --hard origin/pos/category-first-caisse-2026-06-23   # → 0da7cd580
bash tools/deploy-final-2026-07-07.sh
```

Le script fait (idempotent) : `npm ci` + **`npm run production`** (rebuild TOUS les bundles
— indispensable pour le bouton Modifier caisse, les crudités tacos, l'upsell, etc.),
`migrate --force`, triggers NF525 (install+verify 8/8), vignettes WebP, **seeders**
(TacosCruditesRestore, **MenuEnfantChickenBurger**, OnionCuit, DrinksUpdate),
publication des ponts + lanceurs dans `public/dl`, `POS_PRINT_SILENT_ONLY=true`,
`fiscal:verify-chain --all` (= CHAIN OK ×4), `queue:restart`.

**Vérif A** : le script finit par `CHAIN OK` sur chaque branche + affiche les hashes
bundles. Ouvrir la caisse → catégorie **Menu enfant** doit montrer **« Menu Enfant
Chicken Burger »** ; un **Tacos** doit proposer une étape **crudités** ; le **n° de
commande** du jour démarre à **A0032**. Si oui → déploiement OK.

> ⚠️ Rappel : la caisse/borne servent leur **API depuis `APP_URL`** (port 8766 en dev,
> le domaine cloud en prod). Après deploy, **vider le cache navigateur** des machines
> (voir Partie C) sinon elles gardent l'ancien bundle (= page blanche paiement / boutons
> illisibles déjà vus).

---

## PARTIE B — FLASH DU TERMINAL (caisse ET borne), à chaque commande

### Diagnostic (déjà établi — à CONFIRMER sur la machine)
Les **ponts eux-mêmes ne flashent pas** :
- Borne `tools/borne/bridge.js` : **aucun** `child_process` (écrit direct en USB node-usb).
- Caisse `tools/caisse-bridge/caisse-bridge.js` : **1 seul** `spawn` PowerShell, déjà
  `windowsHide:true`, worker **persistant** (compile une fois, boucle stdin — PAS de re-spawn par ticket).

Le flash vient du **LANCEUR / auto-démarrage** encore en place sur les machines :
- ❌ `schtasks … /TR "node …" /SC MINUTE` (relance node chaque minute → fenêtre console récurrente), OU
- ❌ `powershell -WindowStyle Hidden -File watchdog.ps1` (flashe **quand même** : `conhost.exe`
  dessine la fenêtre AVANT que `-WindowStyle Hidden` s'applique), OU
- ❌ un **ancien pont** (pré-2026-07-06) qui **re-spawnait** PowerShell **par ticket**.

**Confirmer sur CHAQUE machine (caisse + borne)** :
```powershell
schtasks /Query /FO LIST /V | findstr /I "Cayenne Pont Borne Caisse node bridge"   # tâche coupable ?
Get-CimInstance Win32_Process -Filter "Name='powershell.exe' OR Name='node.exe' OR Name='conhost.exe'" | Select ProcessId,CommandLine
```
Passer **une commande de test** et regarder le **titre** de la fenêtre qui flashe
(PowerShell fond bleu ? cmd ? conhost ?) → ça pointe la source.

### Fix (une option par machine — DÉJÀ dans le repo)

**1) Copier les ponts À JOUR** (téléchargés depuis `https://<domaine>/dl/…` OU copiés du repo) :
- Borne → `C:\borne-print\bridge.js`
- Caisse → `C:\caisse-bridge\caisse-bridge.js`

**2) Installer un lanceur SANS fenêtre** (choisir A idéal, B repli) :

- **A — Service Windows NSSM** (session 0 = 0 fenêtre, structurel ; garde le pont vivant) :
  ```powershell
  # EN ADMINISTRATEUR, nssm.exe dans le PATH :
  powershell -ExecutionPolicy Bypass -File install-borne-service.ps1  -BridgePath "C:\borne-print\bridge.js"
  powershell -ExecutionPolicy Bypass -File install-caisse-service.ps1 -BridgePath "C:\caisse-bridge\caisse-bridge.js" -Printer "SAGA"
  ```
- **B — Lanceur VBS fenêtre-0** (repli sans admin ; `Run …,0,False` = SW_HIDE dès la création) :
  placer `start-borne-bridge-hidden.vbs` / `start-caisse-bridge-hidden.vbs` **à côté** du pont,
  puis déposer un **raccourci** dans `shell:startup` (Win+R → `shell:startup`).

**3) SUPPRIMER l'ancien auto-démarrage** (c'est LUI le flash) :
```powershell
schtasks /Delete /TN "LeCayenne-BornePont"  /F   2>$null
schtasks /Delete /TN "LeCayenne-CaissePont" /F   2>$null
# + retirer tout raccourci .bat / watchdog powershell du dossier shell:startup
```
Puis **redémarrer le pont proprement** (via le service NSSM ou le raccourci VBS) et
**tuer** les `node.exe`/`powershell.exe` orphelins de l'ancien lanceur.

### Vérif B
Passer **3-4 commandes** de test (caisse ET borne) : **0 fenêtre** ne doit apparaître,
et le ticket doit **quand même** sortir. `GET http://127.0.0.1:9100/health` → `UP`.

---

## PARTIE C — BORNE : « au redémarrage, ça relance l'ANCIENNE version »

Deux couches, à corriger toutes les deux :

### C1 — Le PONT relancé au reboot est l'ancien
Même cause que Partie B : l'ancienne tâche/raccourci au démarrage lance l'ancien
`bridge.js`. Après avoir appliqué Partie B (nouveau pont + lanceur sans fenêtre +
**suppression** de l'ancien auto-démarrage), le reboot relancera la BONNE version.
Vérifier qu'il ne reste **qu'un seul** auto-démarrage du pont (pas deux).

### C2 — La PAGE Chrome (kiosque) charge un bundle EN CACHE (ancienne version d'UI)
C'est la même cause que la page blanche paiement / boutons illisibles : Chrome
kiosque rouvre l'URL cloud mais sert un **bundle JS/CSS en cache**. Fix au démarrage
de la borne — le raccourci/So qui lance Chrome kiosque doit **repartir d'un cache
propre**. Exemple de lancement kiosque cache-propre :
```bat
:: purge le profil de cache à CHAQUE démarrage puis ouvre l'URL courante
rmdir /S /Q "%LOCALAPPDATA%\BorneKiosk\Default\Cache" 2>nul
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" ^
  --kiosk --user-data-dir="%LOCALAPPDATA%\BorneKiosk" ^
  --disk-cache-dir="%TEMP%\borne-cache" --disk-cache-size=1 ^
  --noerrdialogs --disable-session-crashed-bubble --disable-pinch --overscroll-history-navigation=0 ^
  "https://<DOMAINE-CLOUD>/kiosk"
```
Points clés : `--disk-cache-size=1` (cache quasi nul) **ou** purge du dossier Cache au
boot → la borne charge **toujours** le dernier bundle déployé. Vérifier aussi que
l'URL kiosque pointe bien sur le **domaine courant** (pas une ancienne IP/URL).

> Après le `deploy-final` (qui rebuild les bundles avec de NOUVEAUX hashes dans
> `mix-manifest.json`), un cache propre suffit : les fichiers `*.[hash].js` changent,
> l'ancien HTML n'est plus référencé.

### Vérif C
Redémarrer la borne **physiquement**. Elle doit ouvrir la **dernière** version :
- une étape **crudités** apparaît sur un Tacos (fix de cette semaine),
- **0 flash** en passant une commande,
- **pas** de page blanche au clic « Payer ».

---

## RÉCAP — Definition of Done
- [ ] VPS : `deploy-final` OK, `CHAIN OK ×4`, triggers 8/8, Menu Enfant Chicken Burger + crudités tacos + A0032 visibles.
- [ ] Caisse : nouveau pont + lanceur NSSM/VBS, ancienne tâche supprimée, **0 flash** sur 4 commandes.
- [ ] Borne : idem pont + lanceur ; Chrome kiosque cache-propre ; reboot → **dernière** version, **0 flash**, pas de page blanche.
- [ ] (Écran cuisine si concerné : même traitement pont/lanceur.)

**Réfs repo** : `tools/bridge-service/README.md` (diagnostic flash détaillé + tableau service-vs-VBS),
`tools/bridge-service/*.vbs` / `*.ps1` (lanceurs), `tools/borne/bridge.js`,
`tools/caisse-bridge/caisse-bridge.js`, `tools/deploy-final-2026-07-07.sh`,
`reports/handoff/DEPLOY_RUNBOOK_2026-07-02.md` (connexion VPS).
