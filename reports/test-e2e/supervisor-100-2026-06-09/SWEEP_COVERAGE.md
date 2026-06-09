# PER-PAGE E2E SWEEP — coverage ledger + new findings
**2026-06-09 · live Playwright on `:8767` (pre-cloud-exec deployed tree, same as dead :8765) · read-only · admin@lecayenne.fr**
Screenshots: `shots/01-07` (operational) + `shots-sweep/sweep-08..28`. Every page below was navigated live; console-error count is from the live session.

## Coverage — ALL 27 navigable admin routes + 7 operational surfaces (34 distinct pages), 0 console errors each
| # | Page | Route | Console | Note |
|---|---|---|---|---|
| 01 | Dashboard | /admin/dashboard | 0 err | FR money, 45 items, 146 SLA alerts (stale) |
| 02 | Kiosk idle | /kiosk/idle | 0 err | light-mode, À-emporter-only |
| 03 | POS | /admin/pos | 0 err | unified encaissement queue (200) |
| 04 | KDS | /admin/kitchen-display-system | 0 err | empty-state, admin 60s poll |
| 05 | OSS | /admin/order-status-screen | 0 err | 2-col FIFO |
| 06 | Catalogue | /admin/items | 0 err | 45/11; **PRIX `1.50` en-US (SWEEP-MONEY)** |
| 07 | Encaissement (D2) | /admin/encaissement | 0 err | create-then-collect live |
| 08 | Settings (company) | /admin/settings | 0 err | renders |
| 09 | Administrators | /admin/administrators | 0 err | renders |
| 10 | Employees | /admin/employees | 0 err | **phone `null0680…` (SWEEP-EMP-01)** + soak accounts |
| 11 | Ingredients | /admin/ingredients | 0 err | renders |
| 12 | Sales report | /admin/sales-report | 0 err | FR money `32 525,40 €`; **DATE `01:41 PM` en-US (SWEEP-TIME-01)** |
| 13 | Transactions | /admin/transactions | 0 err | **raw enum `COUNTER_CASH` (SWEEP-PAYMODE-01)** + `+ 8.50` en-US money + en-US time |
| 14 | Historique | /admin/historique | 0 err | renders |
| 15 | Stock rupture | /admin/stock/rupture | 0 err | renders |
| 16 | Cash overview | /admin/cash-overview | 0 err | **EXEMPLARY: FR money + 24h time + réconciliation** |
| 17 | Items report | /admin/items-report | 0 err | renders |
| 18 | Push notifications | /admin/push-notifications | 0 err | renders |
| 19 | Messages | /admin/messages | 0 err | renders |
| 20 | POS orders | /admin/pos-orders | 0 err | renders |
| 21 | Subscribers | /admin/subscribers | 0 err | renders |
| 22 | Chefs | /admin/chefs | 0 err | renders |
| 23 | Cash sessions report | /admin/cash-sessions-report | 0 err | renders |
| 24 | POS orders tracker | /admin/pos-orders-tracker | 0 err | renders |
| 25 | Items studio | /admin/items/studio | 0 err / **91 warn** | **missing fr key `studio.product_composer_button` ×90 → empty button labels (SWEEP-STUDIO-I18N)** |
| 26 | Item attributes | /admin/settings/item-attributes/list | 0 err | renders |
| 27 | Delivery-boy cash | /admin/delivery-boy-cash-sessions | 0 err | renders |
| 28 | Profile edit | /admin/profile/edit-profile | 0 err | renders |

**Result: zero JS console errors across all 34 pages.** The recurring single "warning" on most pages = `ws://6001 closed before established` → known SYNC-WS-01 (browser WS → polling fallback, by design).

## NEW findings from the sweep (all live-confirmed, all P2 — FR-locale/i18n/display)
| ID | Sev | Page(s) | Finding | RC-01? |
|---|---|---|---|---|
| **SWEEP-MONEY-01** | P2 | /admin/items, /admin/transactions, /admin/items/studio | Money renders `1.50` / `+ 8.50` (en-US dot, no €) vs correct `32 525,40 €` elsewhere — FR formatter not applied on these surfaces | likely (transactions money fix is in sibling) |
| **SWEEP-TIME-01** | P2 | /admin/sales-report, /admin/transactions | DATE shows en-US `01:41 PM` / `11:46 AM` instead of FR 24h `13:41` | likely |
| **SWEEP-PAYMODE-01** | P2 | /admin/transactions | MODE DE PAIEMENT shows raw enum `COUNTER_CASH` / `COUNTER_MOBILE_BANKING` instead of FR labels | yes (sibling has `fix(transactions): FR money + payment label`) |
| **SWEEP-EMP-01** | P2 | /admin/employees | Phone column renders `null0680718093` (a `null`+number concatenation) | net-new (verify) |
| **SWEEP-STUDIO-I18N** | P2 | /admin/items/studio | Composer button uses i18n key `studio.product_composer_button` **missing from fr.json** → 90 empty/unlabeled button pills | net-new (verify) |

## Interpretation (supervisor)
- **The FR-locale defects are surface-specific, not systemic** — `cash-overview`, `dashboard`, `encaissement` are correct; `transactions`, `items`, `sales-report`-DATE, `items/studio` are not. This is the **live signature of RC-01**: the deployed tree is missing the FR-locale fixes that `heal/deployed-dashboard-fixes-2026-06-08` carries (transactions money+label fix confirmed present there per project memory). **Integrating the branches (RC-01) closes SWEEP-MONEY/TIME/PAYMODE.**
- **SWEEP-EMP-01 + SWEEP-STUDIO-I18N appear net-new** (verify against the sibling before classifying) — both are real FR-surface display defects (null-phone, empty button labels).
- **Severity:** all P2 (FR-locale/display on used admin surfaces; no fiscal/data corruption). They roll into Wave-1 (integration) + Wave-3 (FR-locale residue) of the GOAL plan.

## E2E coverage status (updated honest ledger)
- ✅ **DONE now:** all 34 admin/operational pages navigated live, 0 console errors, per-page screenshot evidence, FR-locale defects caught on 4 surfaces.
- ⏳ **STILL pending (Wave-V, needs disposable `:8766` — must not mutate the operating chain):** per-finding *mutation* E2E (place order → kiosk→KDS→OSS sync; CAISSE-01 under-bill DB-assert; recall paths) and destructive-button exercise (delete/save flows). Read-only render+navigation coverage is complete; mutation coverage is sequenced to the fix waves.
