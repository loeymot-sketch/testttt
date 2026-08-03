# 🎯 MÉGA-PLAN COWORK — Mise en service A→Z des 3 machines Le Cayenne
### CAISSE · BORNE · ÉCRAN CUISINE — + durabilité long-terme (auto-update, zéro crash)

> **Date** : 2026-07-03 · **HEAD certifié** : `2957ee1ff` (+ correctifs tickets de cette session, à committer)
> **Pour** : Claude Cowork (exécution AnyDesk, contrôle des 3 PC Windows)
> **Écrit par** : Claude Code (session owner) — j'ai fait/testé les correctifs **code** ; Cowork fait le
> **physique** (AnyDesk + imprimantes + reboot). Ce plan est l'**unique point d'entrée** ; il référence les
> missions détaillées existantes pour la mécanique AnyDesk et **les remplace** là où mes correctifs changent
> quelque chose (tickets).

---

## 0. RÈGLES ABSOLUES + ORDRE D'EXÉCUTION (ne pas dévier)

**Ordre obligatoire** (chaque étape est un prérequis de la suivante) :

```
A. SERVEUR VPS (déployer le code corrigé + worker temps-réel)   ← SANS ça, tout le reste tourne l'ancien code
B. MACHINE CAISSE                                               (imprime : ticket == écran + téléphone)
C. MACHINE BORNE                                                (ticket LONG, gras, ne tombe pas)
D. MACHINE ÉCRAN CUISINE                                        (affiche /kds temps-réel — NOUVEAU)
E. DURABILITÉ (services auto-restart, watchdog, auto-update, logs)  ← le « assurer que ça tourne pour toujours »
F. VALIDATION FINALE (preuve e2e 100% : imprimé == écran)
```

**Règles AnyDesk / Windows (vécu — ne pas réapprendre à ses dépens)** :
- **AZERTY** : taper au clavier = charabia. **NE JAMAIS TAPER. TOUJOURS COLLER** (`write_clipboard` côté Mac →
  AnyDesk → Actions → « Insérer à partir du presse-papiers », ou **clic-DROIT** dans une console PowerShell).
- Ouvrir **PowerShell ADMIN** : Ctrl+Alt+Suppr (menu AnyDesk) → Gestionnaire des tâches → Fichier → Exécuter
  une nouvelle tâche → coller `powershell` → cocher **privilèges admin** → OK. *(détail complet : `MISSION_1_COWORK_CAISSE_HARDWARE_ULTRA_2026-07-02.md` §0)*
- **Session AnyDesk ~4-14 min** (licence gratuite) → travailler par blocs, « Répéter » si coupé.
- **NE PAS re-patcher le JS applicatif** sur les machines (app.js vient du cloud). Un défaut d'écran = cache /
  service-worker → **purger**, pas patcher.
- **`.env` serveur** : n'ajouter QUE les variables listées §A. Ne rien désinstaller d'autre.

---

## 1. ÉTAT RÉEL & CE QUE J'AI CORRIGÉ CETTE SESSION

| Machine | Avant | Après ce plan |
|---|---|---|
| **CAISSE** | imprimé ≠ écran (retours à la ligne parasites), pas de téléphone | ticket **== écran** (largeur 32), **téléphone `03 65 67 82 91`** |
| **BORNE** | ticket court, « paragraphe » tout pareil, coupe → tombe par terre | **produit en gras** + compo compacte, **long** + **coupe partielle** (ne tombe pas), **téléphone** |
| **ÉCRAN CUISINE** | pas installé | Chrome kiosk `/kds` temps-réel, symboles cuisine déjà validés |

**Correctifs code faits + TESTÉS cette session (à committer + déployer)** :
- `tools/borne/bridge.js` **(NOUVEAU — source unique du pont borne corrigé)** : hiérarchie (produit gras /
  compo normale), **feed 30 lignes** (`ESC d 30` ≈ 12 cm) + **coupe partielle** (`GS V 1`), **téléphone** en
  en-tête. **Défauts sûrs internes** → le ticket sort correct **même avant** redéploiement du bundle cloud.
  → *12 assertions octets vertes* (`node tools/borne/bridge.test.js`).
- `resources/js/helpers/kioskPrinter.js` : le payload borne envoie désormais `phone` + `feedLines` +
  `cutPartial` (pilotés serveur). → *20 tests Vitest verts* (`kioskLocalBridge.spec.js`).
- `config/printing.php` : `receipt.phone` (défaut `03 65 67 82 91`, env `RECEIPT_PHONE`).
- `app/Services/Hardware/OrderReceiptEscPosRenderer.php` (caisse) : téléphone = branche **sinon** fallback
  config → le n° apparaît TOUJOURS. → *18 tests PHP verts*.
- `resources/views/master.blade.php` : injecte `borneTicket.phone/feedLines/cutPartial` dans la config borne.

> Aucun fichier **frozen** touché. Aucune migration. **Caisse « imprimé ≠ écran »** : le renderer serveur est
> déjà width-safe — la cause = **largeur imprimante mal réglée** (48 sur une 58 mm) + ancien code. Le fix =
> `Printer.width_chars=32` (§A) + déploiement. Prouvé par test.

---

## 2. ÉTAPE A — SERVEUR VPS (prérequis de TOUT)

> Depuis le terminal **Mac** (SSH owner/cowork). Le code corrigé DOIT être en ligne avant de toucher les machines.

**A.1 — Déployer le code** (0 migration dans ce lot) :
```bash
ssh lecayenne 'cd /var/www/lecayenne && \
  git fetch origin pos/category-first-caisse-2026-06-23 && \
  git reset --hard origin/pos/category-first-caisse-2026-06-23 && \
  npm ci && npm run production && \
  php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan route:clear && \
  php artisan config:cache && git rev-parse --short HEAD'
```
*(ou `bash tools/deploy-vps.sh` sur le VPS — il auto-vérifie et rollback si le bundle n'est pas à jour.)*

**A.2 — `.env` (poser si absents, puis `php artisan config:cache`)** :
```env
POS_SIMULATION_HARDWARE=false
APP_DEBUG=false
IDEMPOTENCY_MIDDLEWARE_ENABLED=true
BROADCAST_DRIVER=pusher            # PAS 'log' → sinon aucun temps-réel KDS/cuisine
APP_URL=https://vps-418872ac.vps.ovh.net
CACHE_DRIVER=file                  # (ou redis) — jamais array/null
PRINT_DRIVER=windows_raw           # caisse = USB RAW (ticket fidèle aux octets)
POS_PRINT_SILENT_ONLY=true         # caisse : JAMAIS window.print → fin du popup gris/paragraphe (ticket == écran)
RECEIPT_PHONE=03 65 67 82 91       # (optionnel : c'est déjà le défaut ; pose-le pour être explicite)
# Borne : ticket LONG + coupe partielle (déjà les défauts ; explicites = plus sûr)
BORNE_CLIENT_FEED_LINES=30
BORNE_CLIENT_CUT_MODE=partial
SESSION_LIFETIME=100000            # écrans caisse/cuisine ALLWAYS-ON : évite la déconnexion (voir §E.5)
```
> ⚠️ Au **build** des bundles, `MIX_PUSHER_APP_KEY` / `MIX_PUSHER_*` doivent exister sinon Echo=undefined
> côté KDS/borne (repli polling dégradé). Vérifier avant `npm run production`.

**A.3 — Largeur imprimante caisse = 32 (LA correction « imprimé ≠ écran ») + TPE simulé** :
```bash
ssh lecayenne 'cd /var/www/lecayenne && php artisan tinker --execute='\''\App\Models\Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->update(["width_chars"=>32]); echo "width=32 OK";'\'''
```
> Si l'imprimante caisse est réellement une **80 mm**, mettre `48` à la place. **58 mm ⇒ 32.** C'est CE réglage
> qui fait que l'imprimé == l'écran (sans lui, tout se ré-enroule = « retours à la ligne parasites »).

**A.4 — Worker temps-réel (impératif pour l'écran cuisine)** — service supervisor qui redémarre seul :
```ini
[program:foodking-worker]
command=php /var/www/lecayenne/artisan queue:work --queue=high,default --tries=1 --timeout=120 --sleep=1
autostart=true
autorestart=true          ; ← redémarre TOUT SEUL si crash (durabilité)
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/lecayenne/storage/logs/worker.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start foodking-worker
```

**A.5 — Smoke serveur** :
```bash
ssh lecayenne 'cd /var/www/lecayenne && php artisan fiscal:verify-chain --all && sudo supervisorctl status foodking-worker'
# Attendu : CHAIN OK + worker RUNNING. Si l'app refuse de booter → un flag §A.2 est faux.
```

---

## 3. ÉTAPE B — MACHINE CAISSE (`/admin/pos-v4`)

> Détail AnyDesk pas-à-pas : **`MISSION_1_COWORK_CAISSE_HARDWARE_ULTRA_2026-07-02.md`**. Résumé + points clés :

**B.1 — Pont d'impression RAW** (le ticket == octets serveur, sans fenêtre Chrome, sans charabia) :
- Copier `tools/caisse-bridge/caisse-bridge.js` (repo) → sur le PC caisse (ex. `C:\caisse-print\caisse-bridge.js`).
- Lancer : `node C:\caisse-print\caisse-bridge.js "SAGA"` (remplacer `SAGA` par le **nom exact** de
  l'imprimante dans *Périphériques et imprimantes*). `GET 127.0.0.1:9100/health` → `UP`. Zéro dépendance npm.

**B.2 — Chrome kiosque** sur la caisse (flag loopback obligatoire) :
```
chrome.exe --kiosk --kiosk-printing ^
  --disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks ^
  "https://vps-418872ac.vps.ovh.net/admin/pos-v4"
```
Se **connecter une fois** avec le compte caissier (la session persiste, cf. §E.5).

**B.3 — Purge ancienne version** (cause n°1 des tickets « ancien format » / 2ᵉ ticket) — bloc PowerShell à coller :
tuer chrome/node, purger `Service Worker`/`Cache`/`Code Cache`/`GPUCache`, supprimer les anciens fichiers
Startup. *(bloc prêt dans `MISSION_1…ULTRA §2`.)*

**B.4 — TEST caisse (obligatoire)** : passer une vraie commande payée sur place → **2 tickets** sortent :
- **CLIENT** : entête `LE CAYENNE` + **`Tél : 03 65 67 82 91`** + articles/compo lisibles, prix alignés à
  droite (atomiques), **exactement comme à l'écran** (aucun retour à la ligne parasite), TVA + total, pied NF525.
- **CUISINE** : format symbolique `G | TACOS | L | … | SAM`, `MENU`/`F`, **sans prix**, même n° d'appel.

---

## 4. ÉTAPE C — MACHINE BORNE (`/kiosk?machine_key=…`)

> Détail AnyDesk : **`MISSION_2_COWORK_BORNE_HARDWARE_ULTRA_2026-07-02.md`** + `docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md`.
> AnyDesk borne : `108683978` · compte `SPLASH1` · imprimante **Sanei SK1-31** (VID `0x10C5`/PID `0x0007`, WinUSB/Zadig).

**C.1 — REMPLACER le pont par la version corrigée (LE fix ticket borne)** :
- Copier `tools/borne/bridge.js` (repo, corrigé) → **`C:\borne-print\bridge.js`** (écrase l'ancien).
- Relancer le pont :
```powershell
Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Process node -ArgumentList 'C:\borne-print\bridge.js' -WindowStyle Hidden
(Invoke-WebRequest http://127.0.0.1:9100/health -UseBasicParsing).Content   # → UP
(Invoke-WebRequest http://127.0.0.1:9100/test  -UseBasicParsing).Content    # → imprime un ticket de démo
```

**C.2 — Startup + flag Chrome** (déjà en place, à vérifier) :
- `borne-bridge.vbs` (lance le pont) + `borne-kiosk.vbs` (Chrome kiosk 8 s après) dans le dossier Startup.
- Le raccourci Chrome DOIT garder `--disable-features=BlockInsecurePrivateNetworkRequests,LocalNetworkAccessChecks`
  (sinon Chrome bloque **silencieusement** l'appel au pont → aucun ticket).

**C.3 — TEST borne (obligatoire)** : passer une commande → le ticket client doit être :
- **LONG** (~12-15 cm, il ressort et se laisse attraper), **coupe partielle** → **reste accroché, NE TOMBE PAS**
  (le client le détache d'un petit coup).
- **Produit en GRAS** + grande taille ; **composition en petit** juste en dessous (plus « un paragraphe tout pareil »).
- **`Tel : 03 65 67 82 91`** en en-tête.

> Le nouveau `bridge.js` applique feed 30 + coupe partielle + téléphone **même si** le bundle cloud n'est pas
> encore à jour (défauts internes). Après déploiement §A, ces valeurs viennent en plus du serveur (config).

---

## 5. ÉTAPE D — MACHINE ÉCRAN CUISINE (NOUVEAU)

> L'écran cuisine **existe déjà dans l'app** (route `/kds`, symboles validés). Ce n'est PAS un développement —
> c'est le **même schéma que la borne**, avec **1 différence clé : login staff (pas de machine_key)**.

**D.1 — Compte « Cuisine »** : créer/utiliser un utilisateur **staff** (rôle Chef/KDS) dédié à l'écran. Il sert
uniquement à ouvrir `/kds`. *(Admin → utilisateurs, ou seeder.)*

**D.2 — Chrome kiosque cuisine** (pas d'imprimante — affichage seul, donc **pas** de pont ; pas besoin du flag loopback) :
```
chrome.exe --kiosk --disable-pinch --overscroll-history-navigation=0 ^
  "https://vps-418872ac.vps.ovh.net/kds"
```
Se connecter **une fois** avec le compte « Cuisine » → `/kds` reste affiché (session persiste, §E.5).

**D.3 — Temps réel** : dépend du worker `--queue=high,default` (§A.4) + `BROADCAST_DRIVER=pusher`. Sans eux,
le KDS marche quand même en **polling** (rafraîchi ~5 s) — dégradé mais pas cassé.

**D.4 — Startup cuisine** : un `.vbs` unique dans le dossier Startup du compte cuisine (comme la borne, mais
sans le pont) :
```vbscript
WScript.Sleep 8000
CreateObject("WScript.Shell").Run "C:\PROGRA~1\Google\Chrome\Application\chrome.exe --kiosk ""https://vps-418872ac.vps.ovh.net/kds""", 1, False
```

**D.5 — TEST cuisine** : passer une commande (borne ou caisse) → elle **apparaît sur `/kds`** en quelques
secondes, format symbolique lisible ; le bouton **« prêt »/bump** change son état sans recharger la page.

---

## 6. ÉTAPE E — DURABILITÉ LONG-TERME (« assurer que ça tourne pour toujours, sans crash, mises à jour auto »)

> C'est la partie qui garantit qu'après ton install, **personne n'a plus à intervenir**. À faire sur **chaque** machine.

**E.1 — Auto-login Windows** (la machine revient seule après coupure de courant) :
`netplwiz` → décocher « les utilisateurs doivent entrer un nom… » → saisir le mot de passe du compte.
*(Déjà fait sur la borne `SPLASH1` — à faire aussi caisse + cuisine.)*

**E.2 — Ponts d'impression en SERVICE qui redémarre seul** (borne + caisse). Deux options :
- **Task Scheduler** (sans install) : tâche « Au démarrage », action `node C:\...\bridge.js`, onglet
  *Paramètres* → **« Redémarrer la tâche toutes les 1 min » si elle échoue, jusqu'à 999 fois** + « Si la tâche
  est déjà en cours : ne pas démarrer une nouvelle instance ».
  ```
  schtasks /Create /TN "LeCayenne-BornePont" /TR "node C:\borne-print\bridge.js" /SC ONSTART /RL HIGHEST /RU SPLASH1 /F
  ```
- **NSSM** (plus robuste, recommandé) : `nssm install LeCayenneBornePont "C:\Program Files\nodejs\node.exe" "C:\borne-print\bridge.js"` → NSSM relance le process s'il meurt. Idem `LeCayenneCaissePont` sur la caisse.

**E.3 — Watchdog Chrome kiosque** (relance Chrome s'il plante/est fermé) — tâche planifiée qui tourne toutes
les 2 min :
```powershell
if (-not (Get-Process chrome -ErrorAction SilentlyContinue)) {
  Start-Process "C:\Program Files\Google\Chrome\Application\chrome.exe" `
    "--kiosk","https://vps-418872ac.vps.ovh.net/kds"   # (adapter l'URL par machine)
}
```
`schtasks /Create /TN "LeCayenne-KioskWatchdog" /TR "powershell -WindowStyle Hidden -File C:\lecayenne\watchdog.ps1" /SC MINUTE /MO 2 /RU <compte> /F`

**E.4 — MISES À JOUR AUTO** (le point que tu tiens à garantir) :
- **Code applicatif (les 3 machines)** : elles n'affichent QUE le cloud via Chrome → **elles se mettent à jour
  automatiquement au rechargement**. Rien à réinstaller sur les machines quand le code change. Pour forcer la
  dernière version + purger le cache, une **tâche planifiée nocturne** (4 h, hors service) par machine :
  ```powershell
  Get-Process chrome,node -ErrorAction SilentlyContinue | Stop-Process -Force
  $d="$env:LOCALAPPDATA\Google\Chrome\User Data\Default"
  'Service Worker','Cache','Code Cache','GPUCache' | % { Remove-Item -Recurse -Force "$d\$_" -EA SilentlyContinue }
  # (le Startup/watchdog relance Chrome + le pont juste après)
  ```
  `schtasks /Create /TN "LeCayenne-NightlyRefresh" /TR "powershell -WindowStyle Hidden -File C:\lecayenne\nightly.ps1" /SC DAILY /ST 04:00 /RU <compte> /F`
- **Code serveur (VPS)** : mise à jour = **contrôlée** (NF525 : on ne push pas du fiscal en aveugle). Garder
  `tools/deploy-vps.sh` (rollback auto). *Optionnel* : cron qui `git pull` une branche `prod` stable la nuit —
  **à n'activer que si l'owner le veut** ; par défaut, déploiement manuel gaté.
- **Ponts `bridge.js` (machines)** : changent rarement. Procédure de MAJ = recopier le fichier depuis le repo
  (`tools/borne/bridge.js`, `tools/caisse-bridge/caisse-bridge.js`) + relancer le service (E.2). Documenter la
  version déployée.

**E.5 — Sessions always-on** (écran cuisine + caisse ne doivent JAMAIS se déconnecter tout seuls) :
`SESSION_LIFETIME` grand (§A.2, ex. 100000 min) + `session.expire_on_close=false` (déjà le cas). Ainsi la
cuisine/caisse restent connectées après des jours d'inactivité. *(À re-login uniquement si l'owner change le mot de passe.)*

**E.6 — « Beaucoup d'historique » sans saturer** (rotation / purge — sinon crash disque à terme) :
- Laravel logs en **daily** + rétention : `LOG_CHANNEL=daily`, `LOG_DAILY_DAYS=14`.
- Jobs échoués purgés : cron `php artisan queue:prune-failed --hours=168` + `php artisan queue:prune-batches`.
- Backups DB : garder la rotation existante `storage/backups/db-daily/` (ne PAS committer, purge > N jours).
- **NF525** : `audit_logs` / `z_reports` = **6 ans obligatoires, NE JAMAIS purger** (append-only, protégés).
  La rotation ci-dessus ne concerne QUE logs applicatifs / jobs / caches — **jamais** les tables fiscales.
- L'écran cuisine `/kds` affiche déjà **toutes** les commandes du board en hauteur dynamique (pas de troncature),
  et se vide au fur et à mesure des bumps → pas d'accumulation mémoire côté navigateur.

**E.7 — Reboot de validation par machine** : couper/rallumer chaque PC → il DOIT revenir seul en état
opérationnel (auto-login → pont en service → Chrome kiosque → page connectée). Si un maillon manque, corriger
avant de passer à la machine suivante.

---

## 7. ÉTAPE F — VALIDATION FINALE (preuve 100%, imprimé == écran)

**Par machine** :
- [ ] **Caisse** : ticket CLIENT imprimé == écran (0 retour à la ligne parasite) + `Tél : 03 65 67 82 91` ; ticket CUISINE symbolique sans prix. Reboot → revient seule.
- [ ] **Borne** : ticket LONG + coupe partielle (ne tombe pas) + produit gras + compo compacte + téléphone. Reboot → revient seule.
- [ ] **Cuisine** : `/kds` affiche les commandes en temps réel, bump « prêt » OK. Reboot → revient seule.

**End-to-end cross-surface** (le vrai test) :
1. Commande sur la **BORNE** → ticket borne correct sort.
2. La commande **apparaît sur l'écran CUISINE** (`/kds`) en quelques secondes.
3. Le client va à la **CAISSE** → le caissier voit la commande « à encaisser », encaisse → **ticket client +
   ticket cuisine** sortent, corrects, avec téléphone.
4. Après bump cuisine → la commande disparaît du board.
5. `php artisan fiscal:verify-chain --all` → **CHAIN OK** (aucune rupture NF525).

Tant que **F** n'est pas 100% vert **sans réserve**, l'install n'est pas terminée.

---

## 8. FICHIERS FOURNIS · IDENTIFIANTS · RÉFÉRENCES

**Fichiers repo à déployer sur les machines** :
| Fichier repo | Destination | Rôle |
|---|---|---|
| `tools/borne/bridge.js` | `C:\borne-print\bridge.js` (borne) | pont corrigé (gras + long + coupe partielle + tél) |
| `tools/caisse-bridge/caisse-bridge.js` | `C:\caisse-print\` (caisse) | pont RAW (ticket == écran) |
| *(VBS Startup)* | dossier Startup de chaque compte | auto-lancement pont + Chrome |

**Identifiants / matériel** :
| Élément | Valeur |
|---|---|
| VPS | `vps-418872ac.vps.ovh.net` (app `/var/www/lecayenne`) |
| Branche git | `pos/category-first-caisse-2026-06-23` |
| Machine key BORNE | `lcb-227b5373163391c875eeb43f7ee2affe3972` |
| URL caisse | `/admin/pos-v4` · URL cuisine | `/kds` · URL borne | `/kiosk?machine_key=…` |
| Imprimante borne | Sanei SK1-31 (VID `0x10C5`/PID `0x0007`, WinUSB) · **58 mm = 32 col** |
| AnyDesk borne | `108683978` · compte `SPLASH1` |
| Téléphone ticket | **`03 65 67 82 91`** |

**Missions détaillées (mécanique AnyDesk pas-à-pas)** :
`MISSION_1_COWORK_CAISSE_HARDWARE_ULTRA_2026-07-02.md` · `MISSION_2_COWORK_BORNE_HARDWARE_ULTRA_2026-07-02.md` ·
`DEPLOY_RUNBOOK_2026-07-02.md` · `docs/runbooks/BORNE_LOCAL_BRIDGE_SETUP.md` ·
`docs/runbooks/CAISSE_LOCAL_BRIDGE_RAW_SETUP.md`.

**Contraintes NF525 (ne jamais violer)** : `POS_SIMULATION_HARDWARE=false` en prod, chaîne fiscale intacte,
`audit_logs`/`z_reports` jamais purgés (6 ans), frozen-zones intouchées.
