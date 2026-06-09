# MASSIVE E2E — CONVERGENCE REPORT (test-e2e dual-team)
**2026-06-09 · Le Cayenne V1-LOCAL · GStack capture + adversarial supervisor**

## Result: 6 P1 FR-locale defects found → fixed → VERIFIED LIVE. Technical-clean across all 6 waves.

### Method
- Round 1: 6 waves (A catalogue/cockpit · B users/comms · C reports/money · D borne/kds/oss/pos · E sync-mutation · F fix-verify+CAISSE-01) × GStack standalone-Playwright capture (PNG+console+network+DOM quartet, 67 pages) + adversarial supervisor.
- Mutations isolated to :8766 (foodking_e2e clone); read-only nav on the deployed tree. Operating NF525 chain append-only (only the auditor's own logins; no order/payment/fiscal mutation).

### Blocking findings (P1) — ALL FIXED + LIVE-VERIFIED
| ID | Defect | Fix | Live proof |
|---|---|---|---|
| WB-01 | English Spatie roles ("POS Operator"/"Branch Manager") on FR Employés | `AppLibrary::roleLabel()` FR map + `EmployeeResource.role_label` + `RoleResource.display_name` + table/filter render | ✅ "Opérateur caisse"/"Responsable de filiale" (`r2-employees-roles-FR-verified.jpeg`) |
| C-P1-TXN-MONEY | transactions MONTANT "+ 8.50" en-US no € | cherry-pick sibling `421f1b030` (additive `amount_display`) | ✅ "+ 36,00 €" (`r2-transactions-FR-verified.jpeg`) |
| C-P1-TXN-PAYENUM | transactions raw enum "COUNTER_CASH" | `TransactionResource.payment_method_label` FR map | ✅ "Carte bancaire"/"Espèces" (same shot) |
| C-P1-TIME-AMPM | en-US 12h "03:41 PM" on transactions/sales-report/historique/cash-sessions | `.env TIME_FORMAT "h:i A"→"H:i"` (ADR-007 24h); :8765/:8767 restarted | ✅ "23:27"/"18:27" (transactions + cash-sessions shots) |
| C-P1-CASHSESS-MONEY | cash-sessions money bare "50.00" no € | `CashSessionReport.formatMoney` → FR Intl | ✅ "50,00 €"/"450,00 €" (`r2-cashsessions-FR-verified.jpeg`) |
| SWEEP-MONEY-01 | items catalogue + studio PRIX "1.50" en-US | render `item.currency_price` (FR field already on ItemResource) | ✅ "1,50 €"/"3,80 €" (`r2-items-prix-FR-verified.jpeg`) |

### Technical scan (adversarial "technical second", all 6 waves / 67 pages)
- **0 real console errors** (excluding dead-:8765 noise) · **0 real 4xx/5xx** (excluding 401-preauth) · **0 raw i18n-key leaks** in DOM. All waves clean.

### Non-blocking (P3, disclosed — do not loop per severity rule)
- WB-02 edit-action aria (resolves via tooltip text — consistency only)
- C-P3-A11Y-CLOSEBTN off-canvas close button name
- C-P3-SETTINGS-ETAT "ÉTAT" anglicism (should be "Statut"/"État" semantics)
- C-P3-ITEMS-OUTLIER items-report qty 10168 (DATA outlier on clone, not a code defect)

### Commits (fixes, this campaign — 0 frozen, no push)
`cf3f5a580` (5 P1: roles/txn-money/txn-payenum/time/cashsess) · `985c407f6` (items PRIX). Bundles rebuilt. `.env` corrected on this box (NOT committed — secrets); **OVH .env needs the same one-liner `TIME_FORMAT=H:i`**.

### Honest convergence status
- **6/6 surfaced FR-locale P1 fixed + live-verified.** Technical-clean across all waves.
- Round-2 adversarial VISUAL pass on waves A/D/E/F was not agent-run (transient API rate-limit) — substituted by (a) the cross-wave technical scan above and (b) the supervisor-100 deep visual audit of those exact surfaces (34-page sweep, all clean). Their captures are on disk for any future formal pass.
- Remaining KNOWN items are owner-gated, NOT new: CAISSE-01 POS under-bill (frozen pos-wizard.js → GATE-FROZEN-1), RC-01 branch integration (GATE-INT-1; this campaign's transactions fix was cherry-picked from the sibling = zero-divergence forward progress), mobile loyalty/legal (publish-gated).
