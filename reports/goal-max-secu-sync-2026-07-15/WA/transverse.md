# Audit TRANSVERSE (sécu + synchro + données) — 2026-07-15

Cible : concurrence multi-onglets caisse, couverture idempotency, scheduler blast-radius,
sessions/auth. Ancres : `app/Console/Kernel.php`, `app/Http/Middleware/*`, `config/queue.php`.

Contexte : V1 LOCAL mono-poste Le Cayenne. Multi-tenant/scale/concurrence-à-l'échelle exclus.

---

## Ce qui a été VÉRIFIÉ SAIN (pas de finding)

- **Multi-onglets — double encaissement comptoir** : `PaymentService::confirmCounterPayment`
  (`app/Services/PaymentService.php:227-314`) verrouille l'ordre `lockForUpdate()` puis lève
  `PaymentAlreadyCollectedException` (409) sur le 2e onglet. Non exploitable. Route porte aussi
  `idempotency` (`routes/api.php:927`).
- **Multi-onglets — double ouverture tiroir** : `CashDrawerService::openSession`
  (`app/Services/Cash/CashDrawerService.php:75-109`) = triple défense (Cache::lock + lockForUpdate
  + UNIQUE partiel). 409 sur le 2e. Sain.
- **Multi-onglets — double Z-close / Z-open** : `ZReportService` (frozen) utilise
  `Cache::lock('z_report_b{n}')` + `lockForUpdate()` + pré-check `STATUS_OPEN`
  (`app/Services/Fiscal/ZReportService.php:200-219`). Le 2e close ne trouve aucun Z ouvert →
  RuntimeException, **aucun sceau/séquence dupliqué**. (Note mineure P3 : renvoie 500 au lieu d'un
  409 propre — cosmétique, non remonté.)
- **Couverture idempotency money-path** : store POS, counter-collect confirm/cancel,
  collect-kiosk-cash, cash-drawer open/close/reconcile, refund-with-counter-entry, redeem-loyalty,
  add-points, print-receipt/kitchen, frontend/order — TOUS portent le middleware `idempotency` +
  sont dans `config/idempotency.php required_routes`. `fail_open=false` par défaut (503 = fail-closed).
  Sentinel `IdempotencyRequiredRoutesCoverageTest` garde le drift. **Solide.**
- **Refresh token** : `RefreshTokenController` préserve abilities + name + refuse token expiré
  (pas de ré-escalade kiosk→admin). Sain.

---

## FINDING 1 — P1 — Aucun dead-man runtime pour la LIVENESS du scheduler : mort silencieuse du backup NF525 + filets fiscaux, invisible des sondes santé

**Fichiers** :
- `app/Console/Kernel.php:22-466` (34 lanes critiques : backup 03:00, Z-close 23:59, Z-open 00:01,
  fiscal:retry-alloc chaque minute, outbox:rescue, etc.)
- `app/Http/Controllers/HealthController.php:53-58` (ready sonde db/redis/queue_worker/broadcast —
  PAS le scheduler ; `grep schedule|heartbeat|backup` = 0)
- `app/Http/Controllers/HealthzController.php:48-54` (healthz sonde db/redis/ws/fiscal_chain/queue —
  PAS le scheduler)
- `app/Console/Commands/PreflightProductionCommand.php:196-213` (seul contrôle existant = WARNING
  au DÉPLOIEMENT, `crontab -l | grep schedule:run`, jamais ré-évalué au runtime)

**Défaut** : la totalité de la résilience fiscale/données repose sur `php artisan schedule:run`
(cron minute). Si ce cron s'arrête (reboot sans cron persistant, MAJ OS qui vide la crontab,
chemin `php` modifié, box éteinte la nuit), TOUTES les lanes s'arrêtent en silence :
backup quotidien NF525 (rétention 6 ans), Z-close/Z-open safety-nets, `fiscal:retry-alloc`
(commandes borne payées restant `fiscal_alloc_error_at` sans n° fiscal), outbox prune.
Aucune sonde runtime ne le détecte : `/healthz` et `/health/ready` restent VERTS car ils ne
sondent jamais la fraîcheur du scheduler ni l'âge du dernier backup. Le seul garde-fou
(`PreflightProductionCommand`) est un WARNING one-shot au déploiement, explicitement qualifié
« platform-fragile », qui n'alerte plus jamais après.

**Preuve concrète (sur CE box opérationnel)** :
```
$ crontab -l                                  → "no crontab for 1millnonstop"
$ launchctl list | grep schedule:run          → (rien)
$ ls storage/logs/ | grep -E 'schedule|heartbeat|fiscal-close|fiscal-open|sanctum-prune|stripe-drain'
                                              → (aucun fichier — ces lanes n'ont JAMAIS tourné ici)
$ ls -lt storage/backups/db-daily/            → daily-2026-06-24.sql.gz  (le plus récent)
$ date                                        → 2026-07-15
```
→ Le backup quotidien NF525 est MORT depuis le 2026-06-24 (3 semaines), alors que l'app est
active (logs fiscal/observability/security datés 2026-07-15). Zéro alerte. Une panne disque
aujourd'hui = 3 semaines de données fiscales perdues, sans point de restauration, et personne
n'a été prévenu. C'est exactement le mode de panne « silent-failure » que la lane
`backup:verify-restore` prétend couvrir — mais elle est elle-même une lane du scheduler mort.

**Repro** :
1. `rm` la ligne cron `schedule:run` (ou simplement ne pas l'installer, cas actuel).
2. Laisser tourner l'app (nginx/php-fpm + queue:work restent up).
3. `curl -s http://127.0.0.1:8000/api/healthz` → `status: ok`. `curl .../api/health/ready` → 200 ok.
4. Aucun backup ne se crée, aucun Z-close nocturne, aucun retry-alloc — tout est vert côté santé.

**Fix (scope-minimal, hors frozen)** : ajouter une sonde de fraîcheur au runtime. Option A :
la lane `healthz:check` (déjà planifiée toutes les 5 min) écrit `Cache::forever('scheduler:last_tick', now())` ;
`HealthController::ready()` ajoute un check `scheduler` qui passe `degraded/fail` si
`now()->diffInMinutes(last_tick) > 10`. Option B (complémentaire) : check `backup_age` qui lit
`filemtime` du plus récent `storage/backups/db-daily/*.sql.gz` et alerte si > 26h. Ainsi la mort
du scheduler devient visible pour UptimeRobot au lieu de rester verte. Aucun fichier frozen touché.

---

## FINDING 2 — P2 — La « déconnexion » / désactivation d'une borne côté Admin ne révoque PAS son token Sanctum : révocation inefficace jusqu'à 8h (TTL)

**Fichiers** :
- `app/Services/KioskMachineService.php:176-197` (`logout()` — ne fait QUE `update(['is_login'=>NO])`
  + notif FCM ; aucun `$user->tokens()->where('name','kiosk-token')->delete()`)
- `app/Services/KioskMachineService.php:147` (`changeStatus()` — désactivation borne, ne révoque
  pas non plus les tokens)
- `app/Http/Requests/OrderRequest.php:83` (l'autorisation de création de commande ne vérifie QUE
  `tokenCan('kiosk:order')` — jamais `is_login` ni `KioskMachine.status`)
- Contraste : `KioskMachineLoginController::login` (`:90-110`) révoque bien les anciens tokens au
  RE-login → la logique de révocation existe, mais n'est pas appelée sur logout/désactivation admin.

**Défaut** : quand l'exploitant clique « Déconnecter » (ou désactive) une borne depuis Admin →
Bornes (`POST /api/admin/kiosk-machine/logout/{kioskMachine}`), le serveur passe seulement
`is_login=NO`. Le token `kiosk-token` (ability `kiosk:order`, TTL `sanctum.expiration`=480 min)
déjà détenu par la borne physique reste PLEINEMENT valide, et les endpoints de commande
(`POST /api/frontend/order`, quote, payment-confirm) ne revérifient jamais `is_login` ni le statut
de la borne. La borne « déconnectée » / « désactivée » continue donc de créer des commandes
pendant jusqu'à 8h. Le contrôle de révocation admin est cosmétique.

**Repro** :
1. `POST /api/kiosk-login` (username/password borne) → récupère `token` kiosk.
2. Admin : `POST /api/admin/kiosk-machine/logout/{id}` → 200, `is_login=NO` en base.
3. Borne : `POST /api/frontend/order` avec l'ancien Bearer token → **201 Created** (commande passée).
   Idem après `changeStatus` vers statut inactif. Aucun 401/403.

**Impact V1 LOCAL** : borne compromise/volée/décommissionnée non révocable en pratique ; l'action
« déconnecter » ment à l'exploitant. Pas d'escalade de privilège (token légitimement émis, ability
limitée à `kiosk:order`), d'où P2 et non P1.

**Fix (scope-minimal, hors frozen)** : dans `KioskMachineService::logout()` (et le chemin de
désactivation `changeStatus`), ajouter la révocation déjà utilisée au login :
`User::find($kioskMachine->user_id)?->tokens()->where('name','kiosk-token')->delete();`
dans la même transaction que le flip `is_login`. Aucun fichier frozen touché.

---

### Périmètre couvert / non couvert
- Couvert : concurrence 2-onglets (encaissement/tiroir/Z), couverture idempotency money+fiscal,
  scheduler liveness + blast-radius, cycle de vie tokens (staff logout, kiosk login/logout, refresh).
- Non-findings notés : double Z-close renvoie 500 au lieu de 409 (P3 cosmétique) ; DELETE pos-order
  destroy sans idempotency (mais gardé sceau-Z + soft-delete idempotent → non money, non remonté).
