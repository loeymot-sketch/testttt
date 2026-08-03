# Ultra-review système B — CAISSE (POS)

HEAD `61e9ea7b7` — verdict **GREEN_WITH_NOTES** — 0 nouveau P0/P1/P2, 1 note P3 data-hygiène (déjà couverte garde-fou).

## Invariants confirmés (verify-before-report, lecture seule)

1. **branch_id required** — `app/Http/Requests/PosOrderRequest.php:81` `'branch_id' => ['required','numeric']`.
2. **idempotency_key required sur store** — `config/idempotency.php:35` liste `api/admin/pos` ; middleware `IdempotencyKeyMiddleware.php:52-59` jette `MissingIdempotencyKeyException` si header absent sur route requise ; `enabled=true` runtime + `.env IDEMPOTENCY_MIDDLEWARE_ENABLED=true`. Le matching `$request->is('api/admin/pos')` n'over-match pas `/quote` (Str::is exige chemin complet).
3. **quote → re-price SSOT** — `PosController::quote` (`Admin/PosController.php:196`) délègue à `OrderQuoteService::quote`. Store recalcule via `PricingService::calculateOrder` (`OrderService.php:819`, chemin POS) ; `composition_snapshot` scellé (`OrderService.php:990`) ; client subtotal/total ignorés (nullable, PosOrderRequest:82-103).
4. **walkin_route_to_counter=true → encaissement différé** — `config/pos.php:202` + `.env POS_WALKIN_ROUTE_TO_COUNTER=true` (runtime `true`). `OrderService.php:721-725` bascule `pos_payment_method=COUNTER_DEFERRED` + `payment_status=PENDING_COUNTER` (ligne 783).
5. **Fiscal alloué à l'encaissement SEUL** — `OrderService.php:1114-1119` : si deferToCounter, `fiscal_sequence_no` NON alloué à la création. Allocation à la collecte : `PaymentService::confirmCounterPayment` (`PaymentService.php:335-336`) `if fiscal_sequence_no === null → FiscalSequenceService::next()`.
6. **Gap-free** — `php artisan fiscal:verify-chain --all` = CHAIN OK sur 4 branches. Preuve data live :
   - PENDING_COUNTER pos → pm=6, fiscal=NULL (#5397/#5044/#4824).
   - PAID pos → fiscal set croissant (#5398=2589, #5396=2588, pm=1/2).
7. **Frozen §7 intacts** — `git status` propre sur `pos-wizard.js`, `pos-wizard.css`, `admin-pos-v4.blade.php`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`.
8. **Robustesse file encaissement** (heal 2026-07-01, committé) — `routes/api.php:820-838` exclut CANCELED + filet anti-NULL source_surface. Concurrence encaissement gérée (lockForUpdate + PaymentAlreadyCollectedException 409, `PaymentService.php:278-310`). Garde terminal-status (`PaymentService.php:323-327`).

## Note P3 (déjà couverte garde-fou « Résidus e2e = data-hygiène »)

- Table `orders` branche 1 : 3 numéros fiscaux manquants **2506-2508** (dates 2026-06-19/20). Aucune ligne soft-deleted correspondante → commandes **hard-deleted e2e**. La chaîne d'audit NF525 (`audit_logs`/`z_reports`) reste OK (verify-chain vert). Non-reportable : la loi NF525 exige la traçabilité de la séquence (préservée dans l'audit append-only), pas l'existence de la ligne order. Gap purement cosmétique en table transactionnelle, pas dans le journal fiscal.

## Défauts NOUVEAUX trouvés : AUCUN
Aucun nouveau P0/P1/P2. Système B validé production-perfect V1 LOCAL.
