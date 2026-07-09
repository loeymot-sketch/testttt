# Wave 5 — Rapports/Analytics + Users/RBAC — CONSOLIDATED (static + visual + verified)
**Verdict: YELLOW** (no P0/P1; presentation/i18n polish on gérant-facing reports + staff mgmt). Clone UNMUTATED (test-employee 422'd on role-binding → nothing persisted; deletes cancelled; observability retry/drain NEVER clicked). Operating tripwire intact.

## Coverage (9 pages, DEPTH CONTRACT)
sales-report ✅ (FR money correct here) · items-report ✅ (45 items) · transactions ✅ (P2 confirmed w/ screenshot) · observability ✅ (render-only) · settings/analytics ✅ (empty config) · administrators ✅ (show + 4 tabs) · employees ✅ · chefs ✅ · roles → see reconciliation below.

## FINDINGS (NEW, verified)
- **[P2] Transactions raw enum + dot-decimal money** (CONFIRMED w/ screenshot) — `COUNTER_CASH`/`COUNTER_MOBILE_BANKING` + `+ 8.50` (no €) vs sales-report's correct `Espèces`/`8,50 €`. Same data, formatter not applied. `TransactionResource.php:24-25` + `TransactionListComponent.vue:103/110`. **Highest gérant-impact polish.**
- **[P2] Employees `null`-prefixed phones** — 9/10 rows render `null0680718093` (dial_code/phone concat → literal "null"). All affected = script-seeded soak/stress fixtures; UI create forces +33 country_code → won't recur on real data. Low-severity display bug + null-guard the render.
- **[P2] English server validation messages on staff create** — "The password must be at least 12 characters." / "The role field is required." not localized FR. (Confirms password policy min:12 = good security.)
- **[P3] Employee role dropdown: "Stuff" typo (→Staff) + English role names** (Branch Manager/POS Operator) + duplicated option.
- **[P3] sales-report Type-de-paiement filter** mixes English raw labels (Cash On Delivery/Credit/MFS) with FR.

## RECONCILED — NOT a defect
- **Roles/Permissions UI exists but V1-hidden** — `RoleListComponent` imported `settingRoutes.js:34`, nav link `MenuComponent.vue:97` gated `isSettingHidden('role')`→true in V1. The agent's `/admin/settings/roles/list` 404 + absent nav = **intentional V1-hidden** (single-operator; granular RBAC deferred; roles still assignable via employee form). Accepted choice, matches V1-hidden-module pattern.

## CONFIRM-KNOWN
- English SweetAlert delete dialog (global — confirmed on employees + chefs). 12h/24h time-format (sales-report/transactions 12h vs observability 24h). N°/Non radio bug not encountered on these pages.

## ENVIRONMENTAL (not a defect — :8766 harness)
- SPA hard-reload of a deep admin route → bounce to /login or a PHP docroot error (`.claude/worktrees/pre-cloud-exec/vendor/...`). The e2e server serves the SPA shell on in-app router nav only, not deep hard-loads. In-app nav works fine → harness artifact, not an app defect.

## IMPROVEMENT LIST
1. Transactions: FR money formatter + payment-method translation (reuse sales-report's).
2. Localize global SweetAlert delete dialog to FR.
3. Employees: "Stuff"→"Staff", FR role names, de-dup role option, null-guard phone, localize server validation.
4. Unify time → 24h FR (sales-report/transactions/observability).
5. sales-report: translate payment-type filter English labels.

Counts (W5 NEW): P0=0 · P1=0 · P2=2 (null-phone, EN validation) · P3=2 (role typo/EN, filter EN). Confirmed-known: transactions P2, delete-dialog P2, time P3.
