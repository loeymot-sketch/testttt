# Round-4 — CLUSTER-DELIVERY-PARKED (validation only, 0 code edits)

**Agent:** CLUSTER-DELIVERY-PARKED · **Date:** 2026-06-07 · **DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766
**Verdict:** PASS — both clusters (delivery + parked orders) driven live + inspected + tried-to-break. 0 frozen files touched. NF525 chain OK, fiscal gap-free.
**Bar met:** drove the function + inspected real DB/DOM output + adversarial break-it cases. HTTP-200 was never accepted as PASS.

## Safety (irreversible step done first)
- `phpunit.xml` forces `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` (overrides `.env.testing` mysql/foodking_test). Operating `foodking` DB never at risk.
- DEVDB-GUARD live in `tests/CreatesApplication.php:20` (aborts before RefreshDatabase if non-test DB). Confirmed.
- All live mutation drives ran against `foodking_e2e` only. No order rows persisted (fiscal driver used txn+rollback). Clone fiscal still 1..2029 gap-free after my work.

---

## DELIVERY (livreur)

### (1) Delivery fee — owner rule, server-authoritative
Drove `DeliveryFeeService::fromDistanceKm()` live against clone branch #1 (Hénin-Beaumont 62110, lat 50.4215667 lng 2.9549060, base=5 per_km=1 min=5 **free_km=5**). Fee math for every distance I drove:

| distance (km) | fee | rule |
|---|---|---|
| 0 / 3 / 5 | 5,00 € | flat ≤5km |
| 5.01 | 6,00 € | 5 + ceil(0.01)=1 |
| 6 | 6,00 € | 5 + ceil(1) |
| 8 | 8,00 € | 5 + ceil(3) |
| 8.3 | 9,00 € | 5 + ceil(3.3)=4 (started km rounds UP) |
| 10 | 10,00 € | 5 + ceil(5) |
| 12.5 | 13,00 € | 5 + ceil(7.5)=8 |

Break-it: dist=-3 → 0,00 €; dist='abc' → 0,00 € (defensive, no crash).
**Live DELIVERY order on the clone (txn+rollback):** persisted real DELIVERY orders and asserted the persisted `delivery_charge` == owner rule for the distance — #4240 dist=4km → `delivery_charge=5,00 €` total 15,00 €; #4241 dist=8.3km → `delivery_charge=9,00 €` total 19,00 €. Rolled back (clone pristine). So the fee is proven not just at the service layer but on a persisted delivery order.
**Authority proof** (`OrderRequestDeliveryFeeAuthorityTest`, 4/4, runs on :memory: sqlite): a forged `delivery_charge=999` on a DELIVERY order is **recomputed server-side** from the saved address coordinates → persists 5,00 € (not 999); missing `delivery_distance_km` → 422. Front cannot dictate the fee. Distance enters from the client but the fee is always recomputed from distance+branch config; the saved-address path geocodes server-side (`DeliveryQuoteService`).
Sentinel `DeliveryOwnerRuleHeninBeaumontSentinelTest` 2/2 green (seeded branch IS Hénin-Beaumont + owner config, guards the Paris regression).

### (2) Delivery boy cash session — full lifecycle + break-it (live, branch #1, livreur #10)
Drove `DeliveryBoyCashSessionService` end-to-end with real `delivery_boy_cash_sessions` / `delivery_boy_cash_movements` rows:
- **OPEN** float 50,00 → status=open. **Double-open → 409** (rejected).
- **COLLECT on delivery**: order_collect +20 (IN), change_given −3 (OUT), order_collect +12,50 (IN). Signed sum = **+29,50** (read back from DB, matches).
- Break-it movements: invalid type / invalid direction / negative amount → all rejected (null best-effort).
- **reconcile while OPEN → 422** (must close first).
- **CLOSE** declared 79,50 → status=closed. Movement on closed → null (best-effort) / **422 strict**.
- **RECONCILE**: expected = opening 50 + Σsigned 29,50 = **79,50**; variance = 79,50 − 79,50 = **0,00**. **Close-reconciled → 422**.
- **Deliberate-variance session** (#7): opening 100 + collect 40 → expected **140,00**; declared **137,50** → variance **−2,50** flagged with reason "lost 2.50 in change". I3 math proven, variance + reason persisted.

### (3) BranchScope isolation + audit chain
- Both models carry `addGlobalScope(BranchScope)` (`DeliveryBoyCashSession`/`DeliveryBoyCashMovement` boot()). Dedicated `DeliveryBoyCashSessionBranchIsolationTest` **4/4**: branch-A staff cannot SEE branch-B sessions/movements; admin branch_id=0 sees all; `withoutGlobalScopes()` service bypass works as designed.
- **Methodology note (NOT a defect):** driving isolation via `php artisan tinker` shows no filtering because `BranchScope::apply` (L27) intentionally skips when `App::runningInConsole() && !runningUnitTests()`. So isolation must be proven via HTTP/PHPUnit-feature context (where the scope's own guard re-enables it), not tinker — done, green. My tinker "leak" was the console-skip, confirmed against the scope source.
- Audit chain: every event writes `audit_logs` (`cash.delivery.session.opened|closed|reconciled` + `cash.delivery.movement.recorded`) — 10 rows confirmed for 2 driven sessions. `fiscal:verify-chain --all` on the clone = **CHAIN OK**.
- **POS NF525-path isolation DRIVEN:** with a delivery `order_collect +15` movement recorded on a live session, the POS `cash_movements` table count was **unchanged (171 → 171)** while `delivery_boy_cash_movements` incremented (9 → 10). Proves the separate-table design (model docblock: "avoid polluting the POS NF525 path") holds at runtime — the delivery drawer never inflates the POS drawer. The two trails were validated independently.

### (4) Caisse Livreur surfaces render clean (visual mandate)
Screenshots Read (`tests/e2e/screenshots/r4-caisse-livreur/`, spec `zz-r4-caisse-livreur-2026-06-07.spec.js` 1/1):
- **List** `/admin/delivery-boy-cash-sessions`: Cayenne branding, status filter (Tous/Ouverte/Fermée/Réconciliée), "Ouvrir La Caisse" (orange #F4501E), table columns Fond/Montant compté/Écart/Statut; negative −2,50 € in red. My sessions render with correct figures.
- **Show #6 (exact)**: Montant attendu 79,50 € / Écart 0,00 € + 3 movements ("Encaissement commande livrée", "Rendu de monnaie", ↑IN/↓OUT).
- **Show #7 (variance)**: Montant attendu 140,00 € / Écart −2,50 € (red) / Raison de l'écart "lost 2.50 in change" + movement table.
- 0 raw labels, 0 undefined/NaN, FR fully resolved, currency formatted (€, comma decimal), layout intact.

---

## PARKED ORDERS (commandes garées) — driven live

### (1) Park persists, NO premature fiscal allocation
- `pos_parked_orders` schema has **NO fiscal/sequence column** (branch_id, user_id, label, payload_json, preview_total, items_count, idempotency_token) → cannot allocate prematurely by construction.
- Drove `park()`: 2-item ticket, total 23,50, label "Table 1 - Mr Dupont" persisted. **Fiscal max_seq unchanged 2029**; **0 order rows created** by park. Idempotent (same token → same row).

### (2) Resume + complete → fiscal allocated only on completion, gap-free — DRIVEN through the REAL pay path
- Park stores no fiscal; `recall()` deletes the parked row and returns a snapshot (no payment). Drove park→recall: **max_seq still 2029** (recall ≠ pay).
- **Integrated drive (real path, not inferred):** parked #14 → `recall()` → built the counter-deferred order the recalled cart submits (`source_surface=pos`, `payment_method=CASH_ON_DELIVERY`, `pos_payment_method=COUNTER_DEFERRED`, `payment_status=PENDING_COUNTER`) → `fiscal_sequence_no=NULL` confirmed before pay → called the REAL `PaymentService::confirmCounterPayment(order, CASH, total)` (`PaymentService.php:193`, allocation site L321-322) → order allocated **2030** (next gap-free) and transitioned to **PAID** *only at the pay call*. Whole thing wrapped in `DB::transaction`+`rollBack()` (nested savepoint) → clone restored to max 2029, **0 burned numbers**. Proves allocation timing + gap-free on real completion.
- Corroboration: `FiscalSequenceService::next()` (FROZEN — only CALLED, never edited) is a pure read = MAX+1, **idempotent until persisted** (no free-running counter ⇒ no phantom gaps); persisting 3 orders in a txn allocated 2030/2031/2032 strictly monotonic, then rolled back.

### (3) Park multiple, resume OUT OF ORDER — no corruption
- Parked A/B/C; recalled **C first** (out of order) → C row gone, snapshot returned, **A and B still parked intact**; recalled A (middle) → 2-item snapshot intact. Double-recall C → null (already consumed, no corruption).

### (4) Cancel a parked order — clean
- `discard()` B → row removed, returns true; discard already-gone → false (no crash); **fiscal untouched** by discard. No scratch parked rows of mine linger (all 3 consumed/discarded).
- Cross-branch: `PosParkedOrderTest` `test_recall_cross_branch_returns_404` + `test_discard_cross_branch_returns_404` green (no leak).

---

## Regression / evidence summary
| Suite | Result |
|---|---|
| `--filter Delivery` | OK 136/136 |
| `--filter Parked` | OK 17/17 |
| `--filter DeliveryBoyCashSession` | OK 35/35 |
| `--filter 'Delivery\|Parked\|DeliveryBoyCashSession'` (final) | **OK 153/153, 578 assertions** |
| `--filter DeliveryBoyCashSessionBranchIsolation` | OK 4/4 |
| `--filter DeliveryOwnerRuleHeninBeaumont` | OK 2/2 |
| `--filter FrozenZoneSha256BaselineSentinel` | OK 1/1, 5 assertions |
| `fiscal:verify-chain --all` (clone, read-only) | CHAIN OK |
| Clone fiscal seq (branch 1) | 1..2029, cnt=2029, dist=2029 → **gap-free, 0 dup** |

## Files added (zero source/frozen edits)
- `tests/e2e/zz-r4-caisse-livreur-2026-06-07.spec.js` (visual evidence spec)
- `tests/e2e/screenshots/r4-caisse-livreur/*.png` (3 captures)
- this report
Scratch tinker drivers were created under `storage/app/_drive_*.php` and **removed** after running.

## Notes for supervisor
- Pre-round leftover in `pos_parked_orders` (ids 1-10, labels `AUDIT-B-PARK`/`AUDIT-RUSH-B-PARK`) are from prior rounds, NOT mine — left untouched (non-fiscal hygiene item, supervisor's call).
- Delivery cash sessions #4-#7 I created are retained as the Caisse-Livreur visual evidence and cannot be deleted (NF525 append-only DELETE trigger) — that IS the disposable-clone cleanup story.
