# Audit SÉCURITÉ adversaire — FoodKing V1 LOCAL Le Cayenne

Date: 2026-07-11 · Backend live `:8766` (APP_ENV=local) · Méthode: lecture code + curl HTTP réel + tinker read-only.
Discipline anti-hallucination CLAUDE.md §3ter: **chaque finding a une repro; les faux-positifs sont documentés et écartés.**

Verdict global: **GREEN**. 1 seul défaut réel (P3 hygiène, clé Stripe *sandbox*). Aucune fuite money / PII / fiscale exploitable. Toutes les gardes IDOR/branche, authz fiscale, injection, mass-assignment tiennent — **prouvé par HTTP réel**.

---

## FINDING F1 — Clé secrète Stripe (test/sandbox) committée — P3 (hygiène)

- **Fichier**: `database/seeders/PaymentGatewayDataTableSeeder.php:52`
- **Preuve**:
  ```
  "option" => 'stripe_secret',
  "value"  => 'sk_test_***REDACTED***',
  ```
  `git ls-files` confirme le fichier suivi. Grep: la clé n'est référencée QUE dans le seeder (aucun usage live dans `app/` ou `config/`).
- **Sévérité P3**: c'est une clé `sk_test_` = **mode sandbox Stripe** → ne peut PAS déplacer d'argent réel. Stripe est OFF côté produit (mandate borne=SdV). MAIS elle viole la règle CLAUDE.md §3quater qui liste `sk_test_` comme motif interdit au commit.
- **Fix**: remplacer par `env('STRIPE_TEST_SECRET', '')` dans le seeder; révoquer/rotater la clé dans le dashboard Stripe (elle est publique dans l'historique git). Aucun impact fonctionnel (sandbox, non câblée).

---

## Axe 1 — IDOR / isolation de branche → SÛR (repro HTTP réelle)

Setup: token Sanctum réel pour `user id=3` (**POS Operator, branch_id=1**, ability `*`), commande **propre #113 (branch 1)** vs **étrangère #5004 (branch 7)**. Header `x-api-key` valide.

| Endpoint (staff branch=1) | Cible | Résultat | Verdict |
|---|---|---|---|
| `GET /api/admin/pos-order/show/5004` | branch 7 | **403** "Cross-branch access denied" | bloqué |
| `GET /api/admin/order-history/show/5004` | branch 7 | **403** | bloqué |
| `GET /api/admin/pos/orders/5004/escpos` | branch 7 | **404** ORDER_NOT_FOUND | bloqué |
| `POST .../pos-order/5004/redeem-loyalty` (code valide) | branch 7 | **403** Cross-branch | bloqué |
| `POST .../pos/orders/5004/print-receipt` | branch 7 | **404** | bloqué |
| `POST .../pos-order/5004/refund-with-counter-entry` | branch 7 | **404** ORDER_NOT_FOUND | bloqué |
| `POST .../pos-order/change-status/5004` (RETURNED) | branch 7 | **404** ORDER_NOT_FOUND | bloqué |
| `GET .../pos-order/show/113` (contrôle positif) | branch 1 | **200** + data | autorisé OK |

Mécanisme: là où `withoutGlobalScope(BranchScope)` est utilisé, il y a **toujours** un contrôle post-fetch explicite
`abort_unless($u->branch_id===0 || $order->branch_id===$u->branch_id, 403)`
(`PosOrderController.php:270-278`, `OrderHistoryController.php:86-96`, `PosLoyaltyController.php:45-56`, `PosTicketBytesController.php:36-39`). Les routes à route-model-binding (`{order}`) restent scoped par BranchScope → 404 hors branche.

**Côté client (frontend order)**: `GET /frontend/order/show/{frontendOrder}` délègue à `FrontendOrderService::show()` qui vérifie la propriété `user_id === Auth::id()` (`FrontendOrderService.php:710`) → abort sinon. `escpos`/`change-status`/`payment-confirm` refont le check propriété (`OrderController.php:98-100,158-165`). L'index est scoped `where('user_id', auth()->id())` (`FrontendOrderService.php:100`).

### FAUX-POSITIF écarté (discipline anti-hallucination)
Un test tinker `Order::find(5004)` en console retournait la commande étrangère ("LEAK" apparent). **Écarté**: `BranchScope::apply` ne s'applique PAS en console (garde `!App::runningInConsole()` ligne 27). En HTTP réel (tableau ci-dessus) → 403. Ne PAS surfacer comme P0.

---

## Axe 2 — Authz FormRequest / permission route → SÛR (repro HTTP)

69 FormRequests avec `return true;` (sentinel baseline). Mais les endpoints sensibles sont gardés **au niveau route OU controller** — vérifié live avec le token POS Operator (qui n'a PAS `pos-manage-fiscal` ni `cash-sessions-report`):

| Endpoint | POS Operator | Verdict |
|---|---|---|
| `POST /api/admin/fiscal/z-report/open` | **403** "pos-manage-fiscal permission required." | gardé (`ZReportController::authorizeFiscal` 97-101) |
| `GET /api/admin/cash-overview` | **403** "cash-sessions-report permission required." | gardé (`CashOverviewController:84`) |
| `GET /api/admin/cash-sessions-report` | gardé `abort_unless can('cash-sessions-report')` (`:64`) | gardé |
| online-order mutations | `permission:online-orders` + refund → `pos-refund` (`OnlineOrderController:34,123`) | gardé |
| pos counter-collect / cash | `abort_unless can('pos')` dans chaque closure (routes 862-935) | gardé |
| print-receipt / print-kitchen | `permission:pos-orders|pos` (route 940-942, healé 2026-07-05) | gardé |

Les commentaires "reuses X permission" sur les routes sans middleware route-level sont **réellement** appliqués dans le controller (vérifié). Non-auth global: tous les `/api/admin/*` renvoient **401** sans token.

Backlog P2 (multi-tenant V2, hors V1 mono-branche): `ZReportController::show/pdf` fait du route-model-binding sur `ZReport`, modèle **exempté de BranchScope** (doc §9) et `authorizeFiscal()` ne vérifie que la permission, pas la branche. En V2 SaaS un Branch Manager pourrait lire le Z d'une autre branche. V1 = 1 branche → non exploitable aujourd'hui.

---

## Axe 3 — Injection SQL → SÛR

Tous les `whereRaw`/`DB::raw`/`selectRaw` (35 sites) utilisent des bindings `?` ou des valeurs non-user:
- `AvailabilityService.php:349-351` — `"...+ {$qty} ..."` : **`$qty=(int)$line->quantity`** casté ligne 333 → non injectable.
- `ZReportCashEnrichmentService.php:170-172` — concatène `PosPaymentMethod::CASH` (constante enum int).
- `SloMetricCollector.php:70-77` — `$bucketExpr` choisi par `$driver` (sqlite/mysql), pas d'input user.
- `UberOrderMapper` / `ItemImport` — `whereRaw('LOWER(name) ... ?', [$binding])` bindé.
- `ZReportService` `whereRaw($fiscalDate.' <= ?', [...])` — `$fiscalDate` = expression colonne interne, valeur bindée.

Aucune concaténation d'entrée utilisateur non-bindée trouvée.

---

## Axe 4 — Mass-assignment → SÛR

- `User::$fillable` (`User.php:41-52`) = name/email/password/username/phone/branch_id/country_code/is_guest/status/email_verified_at. **`role` absent** (RBAC via pivot Spatie) → pas d'escalade de rôle par payload.
- Self-service `ProfileService::update` (`:21-31`) écrit **champs explicites** (name/phone/email/country_code) — jamais `branch_id`/`status` depuis la requête.
- `SignupController:102` + `GuestSignupController:116` : `branch_id => 0` **hardcodé** + `assignRole(CUSTOMER)` — pas de `$request->all()`. Aucun `->fill($request)` / `::create($request->all())` trouvé sur modèle sensible.

---

## Axe 5 — Endpoints non-auth → SÛR

`GET /api/admin/pos-order|fiscal/z-report|cash-overview|transaction|cash-sessions-report` sans token → **401** `{"message":"Unauthenticated."}` (30 bytes, pas de data).
`GET /api/frontend/oss-order` sans x-api-key → **400** "Clé API invalide". Avec clé: renvoie `CDSOrderDetailsResource` (id/serial/queue/type/status — **pas de PII**, documenté throttle `oss-public`). Endpoints publics frontend (item/offer/branch/setting/slider) = data catalogue non sensible. `loyalty/register` public **by design** (throttle 5/1). Aucune vraie fuite JSON de data sensible en non-auth.

---

## Axe 6 — Secrets → 1 seul (F1 ci-dessus)

- Scan `sk_live_/AKIA/aws_secret/ghp_/xoxb`: **0** clé live. Seul résultat = F1 (sk_test sandbox).
- `config/kiosk.php:213` `$password='kiosk123'` : défaut **`APP_ENV==='local'` uniquement** (garde ligne 208), aligné seeder dev. Jamais en prod/staging/testing.
- Exposition `kioskAutoLogin:{...,"password":"kiosk123"}` dans `GET /kiosk/idle` (ligne 77 du HTML) = **dev local seulement**. Prod: `KioskAutoLoginGate::resolvePayload` exige `APP_ENV=local` OU IP/CIDR de confiance (IpUtils) OU secret timing-safe (`hash_equals`), décision basée sur **REMOTE_ADDR** (non spoofable via X-Forwarded-For). Testé `KioskAutoLoginGateTest`. **Pas une fuite prod.**
- `.env` non committé (seuls `*.env.example`/templates suivis).

---

## Note cosmétique (pas un finding sécurité)
`FrontendOrderService::show` et `ProfileService` attrapent leur propre `abort(403)` dans un `catch(Exception)` générique → le client reçoit **422** au lieu de 403. La propriété reste **imposée** (data non renvoyée); seul le code HTTP est dégradé. Amélioration lisibilité, pas une vuln.

---

## Résumé exécutable
- **P0/P1/P2 exploitables réels: 0.**
- **P3: 1** (F1 — clé Stripe sandbox committée, à retirer/rotater, aucun impact money).
- **Backlog V2 multi-tenant: 1** (Z-report PDF cross-branche, non exploitable V1 mono-branche).
- IDOR, authz fiscale, injection, mass-assignment, non-auth, secrets: **tous prouvés SÛRS par HTTP réel / code.**
