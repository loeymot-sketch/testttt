# G5 — HEAL-PREP (print-saga discounted-ticket ESC/POS TVA netting)

**Date:** 2026-06-07 · G5 HEAL-PREP agent · LOCAL-ONLY (no push, no merge)
**Branch healed:** `feat/pos-printer-saga-autoprint`
**Worktree:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/printer-saga-pos`
**Local feat commit SHA:** `b27365295a600a66b48c0aebee5c9900912dd19b` (parent `e446a2084`)
**Patch for owner review:** `reports/test-e2e/goal-100pct-2026-06-07/G5-DISCOUNT-HEAL.patch`
**Sim DB:** sqlite `:memory:` (phpunit.xml) — `foodking` operating DB never touched. `vendor/bin/phpunit` only (never `php artisan test`, DEVDB-GUARD respected).

---

## VERDICT: HEAL APPLIED + PROVEN. G5 is owner-merge-ready.

| Question | Answer |
|----------|--------|
| Heal applied? | **YES** — `PosReceiptEscPosRenderer::taxLines()` now nets per-rate tax + HT by `orderDiscountRatio`; added private `orderDiscountRatio()` copied EXACTLY from frozen `ZReportService`. |
| Tests green (counts)? | **YES** — Receipt suite **22/22 (86 assertions)** = 18 existing + 4 new. Broader receipt/escpos/print filter **47/47 (189 assertions)**. |
| Discounted ticket now nets? | **YES** — per-rate TVA **1,82 → 1,37** (before → after); HT **18,18 → 13,64**. Rendered bytes decode to `10%  HT 13,64 €   TVA 1,37 €`; the gross `1,82` / `18,18` are absent from the byte stream. |
| Netted value correct vs Z? | **YES (EXACT)** — ticket TVA **1,37 == signed Z `total_by_tax_rate["10"]` 1,37 == Z `total_tva` 1,37** (probed live). Ticket == Z by construction (same gross×ratio scaling). |
| Non-discount unchanged? | **YES** — all 18 pre-existing tests (all `discount=0` → ratio=1.0 → `tax×1.0`) stay green = byte-identical regression proof. New control test asserts 1,55/15,45 unchanged. |
| Frozen-clean? | **YES** — `FrozenZoneSha256BaselineSentinel` **1/1 (5 assertions)**. `git diff --stat HEAD` = 2 files (the new additive renderer + new test), **0 of CLAUDE.md §7 frozen files**. ZReportService **READ only, never edited**. |
| Pushed / merged? | **NO** — local commit on `feat/pos-printer-saga-autoprint` only. No remote tracking branch (never pushed). `git branch --contains b27365295` = feat branch ONLY (not heal/pre-cloud-exec, not main). |

---

## 1. The defect (confirmed) and the fix

**Defect** (from `G5-PRINT-SAGA-VALIDATION.md`): `PosReceiptEscPosRenderer::taxLines()` summed GROSS pre-discount per-item `tax_amount` with NO discount netting. On a discounted order the printed thermal ticket showed per-rate TVA pre-discount (e.g. 1,82 €) instead of the collected / Z-signed value — the H7 fiscal defect reincarnated on physical paper. The 18 existing print tests all use `discount=0` (ratio=1.0 → gross==netted) and could not catch it.

**Key audit finding (documented, not missed):** unlike `OrderDetailsResource` (the H7 model), this renderer prints **NO per-line tax and NO separate header total_tax line**. `taxLines()` is the **sole** gross-tax surface on the ticket. `$totalTax` (renderer line 125) is read only for a guard, never printed; `TOTAL A PAYER` already prints `order->total` (the net paid amount, correct). So **netting `taxLines()` is the complete fix** — there is no header total_tax/HT line to also net here. Bonus: because there is no per-line tax on the ESC/POS ticket, the H7 "line-vs-summary mismatch" open decision (two contradictory TVA figures) **cannot** recur here — with the standard ≤1-centime HT/TTC rounding noted in §2 (printed `HT 13,64 + TVA 1,37 = 15,01` vs total 15,00, the unavoidable artifact of independent per-bucket rounding in proportional VAT allocation, identical to what H7 produces).

**Fix** (mirrors HEAL-H7 exactly):
- Added `private orderDiscountRatio(BroadcastableOrder $order): float` — verbatim copy of the frozen `ZReportService::orderDiscountRatio`: `(subtotal − discount)/subtotal`, clamped `[0,1]`, returns `1.0` when `discount<=0 || subtotal<=0`.
- In `taxLines()`: compute `$ratio` once before the loop; multiply BOTH accumulations by it (`tax += taxAmount * ratio`, `base_ht += max(0, totalTtc − taxAmount) * ratio`). Round per bucket at the end (NOT per item) — exactly the Z's "net raw, round per bucket, then sum" order, so Σ bucket == Z `total_tva`.

Files changed (feat branch): `app/Services/Receipt/PosReceiptEscPosRenderer.php` (+46/−2) + new `tests/Feature/Receipt/EscPosDiscountTvaNettingTest.php` (4 tests).

---

## 2. Before → after on a discounted ticket

Order: subtotal **20,00** · discount **5,00** · total **15,00** · single rate **10%** · gross per-line `tax_amount` **1,82**.

| field | BEFORE (gross, defect) | AFTER (netted) | Signed Z |
|-------|------------------------|----------------|----------|
| per-rate `tax` (printed TVA) | **1,82** | **1,37** | `total_by_tax_rate["10"]` = **1,37** |
| per-rate `base_ht` (printed HT) | **18,18** | **13,64** | `total_ht` = 13,63 (derived total−tva; renderer prints its own rounded bucket 13,64) |
| `total_tva` (Z) | — | — | **1,37** |
| TOTAL A PAYER (already correct) | 15,00 | 15,00 | total_ttc 15,00 |

Decoded rendered ESC/POS bytes (after fix): `TVA\n  10%  HT 13,64 €   TVA 1,37 €`. The gross `1,82` and `18,18` do not appear anywhere in the byte stream.

**Rounding note (transparent):** the fiscally-binding value is the **TVA = 1,37**, which equals the signed Z **exactly** (both scale gross `tax_amount` × ratio = 1,82 × 0,75 = 1,365 → 1,37). The naive recompute `15 − 15/1.1 = 1,36` is a *different* rounding path the Z does not use, so the correct oracle is the Z (1,37), not 1,36. The HT bucket (13,64) is the renderer's own per-bucket rounding vs the Z's derived `total_ht = 15,00 − 1,37 = 13,63` — a 1-centime presentation artifact on HT only; the binding TVA matches to the centime.

---

## 3. Test evidence

New test `tests/Feature/Receipt/EscPosDiscountTvaNettingTest.php` (4 tests):
1. `test_discounted_ticket_per_rate_tva_is_netted_and_equals_z` — netted TVA 1,37 + HT 13,64; Σ(HT+TVA)≈paid total 15,00 (not gross 20,00); **`assertSame` exact equality** ticket TVA == Z `total_by_tax_rate["10"]`.
2. `test_discounted_rendered_bytes_show_netted_not_gross_tva` — rendered bytes contain `1,37`/`13,64`, do NOT contain `1,82`/`18,18`; `15,00` + `Remise` present.
3. `test_multi_rate_discount_ticket_allocates_proportionally` — subtotal 30 / discount 3 → ratio 0,9: 10% 1,64 + 5,5% 0,47, both == Z buckets.
4. `test_non_discounted_ticket_is_unchanged` — 17,00 @ 10% → 1,55 / 15,45 (ratio=1.0), rendered bytes unchanged.

| Run | Result |
|-----|--------|
| `vendor/bin/phpunit tests/Feature/Receipt` | **OK 22/22, 86 assertions** (18 existing + 4 new) |
| `vendor/bin/phpunit --filter 'EscPos\|Receipt\|PosReceipt\|PrintPosReceipt'` | **OK 47/47, 189 assertions** |
| `vendor/bin/phpunit --filter FrozenZoneSha256BaselineSentinel` | **OK 1/1, 5 assertions** |
| live probe (throwaway, removed): renderer vs Z | renderer `{"10": tax 1.37, base_ht 13.64}` == Z `total_by_tax_rate {"10":1.37}` / `total_tva 1.37` |

The 18 pre-existing print tests staying green IS the byte-identical non-discount regression proof (advisor-confirmed: at ratio=1.0, `taxAmount * 1.0 == taxAmount` exactly in IEEE754, same end rounding).

---

## 4. Frozen-zone + safety

- `git diff --stat HEAD` = **2 files**: `PosReceiptEscPosRenderer.php` (new additive feat file, not in CLAUDE.md §7) + the new test. **0 frozen files.**
- `ZReportService` was **READ only** (to copy the ratio formula verbatim) — never edited; confirmed not in the diff.
- `FrozenZoneSha256BaselineSentinel` passes (1/1).
- Commit: explicit file list (no `git add .`), secret-scanned (clean), no `--no-verify`.

---

## 5. Owner action (G5 merge)

The required pre-merge heal is applied + proven on the LOCAL feat branch. The owner's G5 merge of `feat/pos-printer-saga-autoprint` into `heal/pre-cloud-exec-2026-06-05` is now a clean click — no semantic H7 regression on the printed ticket. Patch for review: `G5-DISCOUNT-HEAL.patch`. Local commit `b27365295` (feat branch only). **Not pushed, not merged** — the merge remains the owner's gate.

**Hardware caveat (unchanged, owner-only):** physical print on the real SAGA SGPR-200II was not exercised here (no device). The sim ESC/POS byte path is proven; paper coming out the thermal head is the one thing only the real LAN printer confirms.
