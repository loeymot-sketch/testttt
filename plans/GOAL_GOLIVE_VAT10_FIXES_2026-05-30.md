# GOAL — Le Cayenne Go-Live Fixes (10% VAT TTC + go-live blockers)

**Date**: 2026-05-30 · **Branch**: `heal/cms-pr1-quickwins-2026-05-18` · **Owner /goal**: corriger tout les problème pour fast-food système **10% VAT inclus dans le prix comme tous les magasins en France** + supervisor go-live blockers. Pipeline: ultraplan → GStack/Superpowers → test-e2e → abuse-e2e.

## §0 — Preamble

- **Owner decision (B1 resolved)**: French fast-food model — **10% VAT INCLUDED in the displayed price (TTC)**. A 5,00 € item stays 5,00 € → 4,55 € HT + 0,45 € TVA. No alcohol (halal-style) → **10% uniform** on all 45 items.
- **Make-or-break preflight (PASSED)**: `.env:83 PRICING_TAX_INCLUSIVE=true`, effective `config('pricing.tax_inclusive_prices')=true` → TTC mode genuinely active. Assigning 10% **extracts** VAT, does NOT add on top. Verified, not assumed.
- **Frozen-zone posture**: `PricingService` + `ZReportService` are §7 frozen. The happy-path TTC math (`ht = ttc/(1+rate/100)`) is ALREADY correct in them — I only VERIFY, never edit. The ONE place a frozen edit could surface is **F1** (TVA/HT split on DISCOUNTED/refunded orders) — if the behavioral Z-test proves it wrong at 10%, it becomes a `lock-plan` gate, NOT an autonomous edit.
- **Working tree**: caisse-unifiée GOAL already committed + converged (HEAD `720e6a98f`). This GOAL stacks on it.

## §1 — Anchors (verified 2026-05-30)

| Fact | Evidence |
|---|---|
| TTC mode active | `.env:83 PRICING_TAX_INCLUSIVE=true`; `config(pricing.tax_inclusive_prices)=true` (tinker) |
| PricingService extracts TTC | `PricingService.php:250-256` `lineTaxAmountFromTTC` when inclusive |
| Target tax | `Tax` id=3 = VAT 10% PERCENTAGE (tinker); id=5 GST 10% is a decoy → resolve by rate+type+name |
| Current menu 0% | 42/45 items `tax_id=NULL`, 3 → id=1 No-VAT 0% (tinker) |
| Seeder assigns tax | `MenuSeeder.php:465,516 'tax_id'=>$this->defaultTaxId()`; resolver :365-389 reads `config(menu).settings.default_tax_id` (=1, the bug) |
| Config wiring | `config/menu.php:72 settings → :74 default_tax_id=1`; `MenuSeeder:89 $this->config=Config::get('menu')` |
| F1 dormant edge | `reports/audit/massive-validation-2026-05-29/ESCALATION_NO_GO.md:6,73,88` — TVA/HT split wrong on discounted orders, frozen, VAT-activation checklist |
| Offers display-vs-charge | `ItemResource.php:111-118` displays `convert_price`; `PricingService.php:134` charges raw price; `Offer::count()=0` (dormant) |
| Receipt path | `PosReceiptPrintController` + `EscPosPrinterService`; `Printer::count()=0` |
| Crons wired | `schedule:list`: fiscal close 23:59 / open 00:01 / verify-z 06:05 / backup 03:00 |

## §2 — Decomposition (all non-frozen unless F1 surfaces)

### W-VAT (B1) — assign 10% VAT TTC [NON-FROZEN]
- **T1** Harden `MenuSeeder::defaultTaxId()` → resolve canonical VAT row by (tax_rate=10, type=PERCENTAGE, name='VAT') FIRST (deterministic; avoids the id=3-vs-id=5 ambiguity), fall back to config/create. → `migrate:fresh --seed` produces a 10%-VAT menu (also closes B2 menu-correctness).
- **T2** `config/menu.php:74` default_tax_id 1→ documented to resolve VAT-10 (resolver is authoritative; update int + comment).
- **T3** Idempotent artisan command `fiscal:assign-menu-vat` (or migration) — set `tax_id = <VAT10 id>` on all active items where tax_id IS NULL or points to a 0% tax. Re-runnable.
- **T4** Sentinel `MenuVat10PercentSentinelTest` — asserts every active item's tax is VAT 10% PERCENTAGE.
- **T5** `.env.example`: `PRICING_TAX_INCLUSIVE=true` explicit.
- *Acceptance*: tinker — all 45 active items tax_id→VAT10; behavioral: a 5,00 € item order charges **5,00 €** (unchanged), line tax 0,45, HT 4,55. `tests/Feature/MenuVat10PercentSentinelTest.php` PASS.

### W-ZVERIFY (B1-verify) — behavioral Z + receipt [READ-ONLY / FROZEN-VERIFY]
- **T6** Behavioral test `Vat10ZReconciliationTest`: seed N orders at 10% TTC, run a real Z-close, assert `total_ht + total_tva == total_ttc` to the penny AND `sum(total_by_tax_rate) == total_ttc`. (test TO BE CREATED at `tests/Feature/Fiscal/Vat10ZReconciliationTest.php`)
- **T7** Plain-order path = correct (expected). **Discounted-order path = the F1 gate**: if HT+TVA≠TTC under discount → F1 confirmed → **STOP, write LOCK, escalate** (frozen ZReportService/PricingService). If correct → F1 moot.
- **T8** Receipt: a live order's ticket shows the 10% TVA line (PosReceiptFiscalExposureTest / PosReceiptTaxLinesTest rebaseline off 0%).
- *Acceptance*: `fiscal:verify-chain --all` CHAIN OK; behavioral reconciliation green on plain orders.

### W-OFFERS (S1) — neutralize mischarge [NON-FROZEN]
- **T9** Disable the Offers admin module for V1 behind a config flag (`features.offers_enabled=false`): hide nav entry + guard the offer create/store routes (403). PricingService stays frozen/untouched. Keeps F1's discount path + the display-vs-charge gap dormant.
- *Acceptance*: offer create route 403 when flag off; nav entry hidden; `Offer::count()=0` stays 0; sentinel.

### W-RUNBOOK (B2/B3/S2) — prod cutover doc [DOC + .env.example]
- **T10** `.env.example` prod-correct (PRICING_TAX_INCLUSIVE, POS_SIMULATION_HARDWARE guidance comment).
- **T11** `PROD_CUTOVER_RUNBOOK.md`: migrate:fresh --seed (clean fiscal start, now VAT-correct) → 3 env flips → OS cron (`schedule:run`) → supervised `queue:work` → drawer discipline → receipt printer config (S2). Box actions = owner's; I verify the seed produces VAT-correct menu.

### W-CONVERGE — test-e2e then abuse-e2e
- **T12** test-e2e: visual+technical per affected surface — POS wizard (price unchanged), kiosk (price unchanged), receipt (TVA line), Z/EOD PDF (decomposition), dashboard, offers-disabled.
- **T13** abuse-e2e: adversarial loop (NF525/escape-z, pricing-correctness incl. "shows X charges Y", frozen-integrity, regression) until P0+P1=0 across 2 rounds. Full vitest+PHP+chain+frozen gates. Convergence book.

## §3 — Waves

1. **Wave A** (W-VAT) — seeder/config/command/sentinel + behavioral verify. Sequential (fiscal-critical). **Gate**: behavioral Z reconciliation decides F1.
2. **Wave B** (W-OFFERS + W-RUNBOOK) — parallel-safe (disjoint from VAT data).
3. **Wave C** (W-CONVERGE) — test-e2e then abuse-e2e, loop to convergence.

## §4 — Owner gates
| Gate | Item | Why | Status |
|---|---|---|---|
| G1 | F1 frozen fix (IF behavioral test proves discounted-order split wrong at 10%) | §7 frozen ZReportService/PricingService — lock-plan + countersign | CONDITIONAL (decided by T7 evidence) |
| G2 | migrate:fresh --seed on box (B2) | destructive prod data op | owner on-box |
| G3 | 3 .env flips (B3) | prod cutover | owner on-box |
| G4 | receipt printer (S2) | hardware | owner |

## §5 — Convergence criteria (DONE)
- All 45 active items VAT 10%; **displayed prices unchanged** (proven on a real order).
- Behavioral Z reconciliation green (HT+TVA==TTC, total_by_tax_rate sums) on plain orders.
- Offers neutralized (no displayed-but-not-charged promo possible).
- vitest + full PHP suite green (tax-test rebaselines documented, not silent); NF525 CHAIN OK; frozen 0 (or F1 under signed LOCK).
- abuse-e2e: 2 consecutive rounds P0+P1=0.
- Runbook written. F1 either fixed-under-LOCK or explicitly gated "no discount until fixed."
