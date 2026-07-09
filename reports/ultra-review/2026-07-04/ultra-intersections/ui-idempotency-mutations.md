# Ultra-Intersections — Idempotency × toutes mutations (cohérence de la protection)

HEAD `48050af80` · Serveur LIVE 127.0.0.1:8766 (foodking_e2e) · Read-only audit · Slug `ui-idempotency-mutations`

## Fonction partagée auditée
`App\Http\Middleware\IdempotencyKeyMiddleware` (frozen §7) — garde at-most-once scopée
`(branch_id, user_id, sha256(key))`, replay 2xx cache + 409 sur payload différent + 425 in-flight.
Consommateurs = toutes les routes mutantes `routes/api.php`. Question : chaque mutation
critique est-elle protégée de façon COHÉRENTE, ou une route oublie-t-elle la protection ?

## Énumération (route:list --json, 268 routes mutantes api/)
- **30 routes** portent `IdempotencyKeyMiddleware` ; **238** ne le portent pas.
- Les 30 = exactement les chemins financiers/état-order/impression/loyalty-redeem/cash.
- Sentinel `IdempotencyRequiredRoutesCoverageTest` → **VERT** : chaque route qui porte le
  middleware figure dans `config/idempotency.required_routes` (aucun silent-pass par omission d'en-tête).

## Preuves LIVE de cohérence comportementale (kernel réel)
`php artisan test tests/Feature/Idempotency/ + IdempotencyMiddlewareSentinelTest` → **21/21 VERT** :
- `two identical posts create only once and replay second`
- `same key different payload returns 409`
- `cross branch same key results in distinct executions`
- `replay after ttl expired executes anew`
- `redis unavailable fail closed 503 / fail open passes`
- change-status ×6 (pos/online/table/kds/frontend/delivery-boy) tous wrappés
- counter-collect confirm/cancel + collect-kiosk-cash + print-receipt/print-kitchen wrappés
- `print receipt is idempotent on replay no double count`

→ Sur ses 30 consommateurs déclarés, le middleware est **cohérent** (même sémantique partout).

## Routes critiques SANS middleware mais protégées par invariant DOMAINE (donc SAFE)
Vérifié que « oublier le middleware » n'implique pas « double-exécution » :
- `POST fiscal/z-report/open|close` → `ZReportService` (frozen) : `Cache::lock('z_report_b{n}')`
  + garde `existingOpen` (STATUS_OPEN) + `lockForUpdate` → double-POST = 2e RuntimeException
  (« already has an OPEN Z report » / « no open Z report to close »), **pas de doublon**. SAFE.
- `POST frontend/payment/reconcile-pending` → idempotent par design : `UNIQUE(transaction_id)`
  + `payment_status=PAID` no-op renvoyant `already_paid`. SAFE.
- `POST table/dining-order` (création order, 3e chemin à côté de pos+frontend) → **dormant** :
  `abortIfDineInDisabled()` renvoie 404 tant que `pos.pos_dine_in_enabled=false` (défaut V1).
  Non live. À protéger si le dine-in est activé un jour.

## INCOHÉRENCE confirmée (repro code + schéma)
### /loyalty/add-points : crédit fidélité NON idempotent (asymétrie vs /loyalty/redeem)
`LoyaltyController@addPoints` (staff Admin/BM/POS Operator/Stuff) :
- **PAS** de `IdempotencyKeyMiddleware` (absent de la route L1431+ et de required_routes).
- `DB::increment('loyalty_points', N)` aveugle + insert `loyalty_transactions` sans clé d'idempotence.
- La table PORTE bien `UNIQUE(user_id, order_id, type)` — MAIS `addPoints` insère
  `order_id = NULL, type = 'manual_add'`. En MySQL, **NULL est distinct dans un index UNIQUE**
  → la contrainte ne se déclenche JAMAIS pour `manual_add` → 2 lignes + double `increment`.
- Chemin frère `/loyalty/redeem` : LUI est middleware-protégé (required route, anti double-débit).

→ **Réfute** « toutes les mutations de solde fidélité sont idempotentes » : le crédit manuel
ne l'est pas alors que le débit l'est. Double-POST (double-clic / retry réseau) = double-crédit.

**Sévérité P3** (mitigée) : endpoint **dormant en V1** — `grep resources/js public/js` = aucun
client n'appelle `/loyalty/add-points` (seuls opt-in/register/scan sont câblés) ; gate rôle staff.
Non exploitable par un client. Reste une incohérence latente réelle du ledger fidélité partagé.

**Fix proposé** : soit ajouter `->middleware('idempotency')` + entrée required_routes, soit —
plus robuste car indépendant du header — remplacer `order_id=NULL` par un discriminant non-null
(ex. `pos_session_id` ou un `reference` unique par geste) pour que `UNIQUE(...,type)` morde.

## Edge latent (non-repro V1, improvement)
Route requise `/loyalty/redeem` : `resolveBranchId` renvoie `-1` pour un appelant sans
`branch_id` résoluble (Customer `branch_id` null + pas de `branch_id` au body) → le middleware
lève `MissingIdempotencyKeyException` (422) MÊME avec une clé valide. Kiosk résout via pivot
`KioskMachine` ; mobile = STANDALONE no-API en V1 → non atteint. À surveiller au wireup mobile.

## Verdict
Protection idempotence **COHÉRENTE** sur les 30 mutations à haut risque (create/état/paiement/
caisse/impression/redeem) — sentinel + 21 tests verts. Routes critiques sans middleware = toutes
couvertes par un invariant domaine. **Une** incohérence réelle mais dormante/P3 (`add-points`
crédit non-idempotent, UNIQUE contourné par order_id NULL). Aucun P0/P1.
