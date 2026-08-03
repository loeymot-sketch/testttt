# Journey E — GESTION admin/users/rapports (RBAC + EOD) — Round 1 (2026-07-15)

Serveur LIVE http://127.0.0.1:8000. Tokens forgés via artisan (admin@lecayenne.fr `[*]`, pos@lecayenne.fr = rôle POS Operator).

## journey_status : OK (aucun P0/P1/P2 — RBAC solide)

## Étapes exécutées (preuves)

### Dashboard (admin)
- `GET /api/admin/dashboard/total-sales` → 200 `{"total_sales":"38 531,72 €"}` puis stable 38 538,72 €
- `GET /api/admin/dashboard/total-orders` → 200 `2871` → 2873 (croissance concurrente d'autres ondes)
- `GET /api/admin/dashboard/order-statistics` → 200 (aujourd'hui)
- `GET /api/admin/dashboard/channel-statistics` → 200 `POS:100 / Web:0 / Kiosk:0` — today-scoped (`DashboardService::channelStatistics` bornes Paris `Carbon::today→tomorrow`), cohérent : les commandes du jour sont POS. Pas de faux rapport.
- Note discordance transitoire dashboard total-orders (2871) vs sales-report/overview (2872) : re-vérifiée back-to-back → les deux convergent à **2873**. Cause = création de commandes concurrente entre 2 appels (multi-agent), PAS un bug de scope. Réfuté.

### Users CRUD + RBAC
- POS Operator `GET /api/admin/users` → 200 ; `POST /api/admin/users` (payload complet) → **201** (crée client id 210/212). BY-DESIGN : `SimpleUserController::__construct` applique `permission:pos` sur store et `permission:pos|pos-orders|...` sur index (heal C09 2026-07-06). POS Operator détient `pos` → accès intentionnel (inscription client/fidélité en caisse). Pas un défaut.
- Mass-assignment testé : POST users avec `branch_id:1, user_type:"admin"` → user créé avec `branch_id=0, user_type=null, roles=Customer`. Escalade **bloquée**. Réfuté.

### RBAC — POS Operator refusé sur endpoints gestion sensibles (403 attendus, tous OK)
- `GET /api/admin/employee` → 403
- `GET /api/admin/administrator` → 403 ; `GET .../show/1` → 403
- `POST /api/admin/employee` (role_id:1) → 403 "User does not have the right permissions."
- `POST /api/admin/administrator` → 403
- `POST /api/admin/administrator/change-password/1` (account takeover) → **403**
- `POST /api/admin/employee/change-password/1` → 403
- `POST /api/admin/administrator/1` (update) → 403
- `GET /api/admin/sales-report` → 403 ; `GET /api/admin/transaction` → 403
- `PUT /api/admin/item/1` (price 0.01) → 403 ; `POST /api/admin/item` → 403
- `POST /api/admin/dashboard/eod-pdf` → 403 (gate `permission:pos-manage-fiscal`)
- Token kiosk → `GET /api/admin/dashboard/total-sales` → **401** (middleware `block_kiosk_token_admin` OK)

### Rapports + PDF (admin)
- `POST /api/admin/dashboard/eod-pdf` → 200, `application/pdf`, 1 281 677 o, `%PDF-1.7`, filename `cloture_jour_2026-07-15.pdf`. OK.
- `GET /api/admin/sales-report` → 200 ; `/overview` → 200 (2873 cmd, 38 538,72 €)
- `GET /api/admin/sales-report/pdf` sans filtre → 422 message clair "Trop de lignes (2880)… Affinez la période" (garde anti-OOM ULTRA-LOOP R2). Avec `from_date`/`to_date` (params RÉELS du SPA, cf `SalesReportListComponent.vue:389-390`) → **200 PDF 5032 o**. Fonctionne. (Mon 1er test avec `start_date/end_date` était de mauvais noms de params → faux positif écarté.)
- `sales-report/export`, `transaction`, `transaction/export`, `audit-trail`, `realtime-report`, `items-report`, `cash-overview`, `administrator/export`, `employee/export` → tous 200, pas de crash 500.

## Findings

### P3 — POS Operator (rôle faible) voit le CA cumulé all-time via le dashboard
- `GET /api/admin/dashboard/total-sales` renvoie 200 `38 538,72 €` à un token POS Operator.
- Cause : `DashboardController.php:38-44` gate `totalSales` par `permission:dashboard`, et le rôle POS Operator détient `dashboard` (perms vérifiées : `dashboard,pos,pos-orders,pos-discount-up-to-10,pos.redeem-loyalty,kitchen-display-system,order-status-screen`).
- Ce n'est PAS un trou de code (le gate fonctionne) mais un choix de config de rôle : un caissier voit le chiffre d'affaires cumulé de l'établissement (total-sales, total-orders, channel-statistics, top-customers, audit-trail). En V1 LOCAL mono-poste où le caissier est souvent le propriétaire, c'est acceptable ; à documenter si un jour un vrai employé-caissier distinct est créé. Aucune mutation exposée.

Aucun autre défaut : RBAC gestion airtight, mass-assignment bloqué, EOD PDF OK, exports OK, kiosk-token bloqué.
