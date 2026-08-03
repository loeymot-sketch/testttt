# Wave 2 — Shell + Dashboard — CONSOLIDATED (static + visual + verified)
**Verdict: YELLOW** (functionally green; polish gaps on in-nav report pages). No live-fire, no clone mutation committed → no reseed needed. Operating NF525 invariant unchanged.

## Coverage (DEPTH CONTRACT applied)
- `/admin/dashboard` ✅ — 18 endpoints 200, 0 raw labels, NF525 audit-trail + Z#19-closed widget + Vue Caisse Unifiée = real fiscal-trust signals; 12 quick-access chips all render. Data: CA cumulé 32 516,90€ / 2224 cmd / 45 articles.
- Sidebar nav (24 links) ✅ — all render real content, 0 dead-link/blank; 9 V1-hidden modules ABSENT from menu (✓).
- Profile (edit + change-password) ✅ — renders prefilled; no external side-effect (pure `$user->save()`, `Frontend\ProfileController`).
- Header ✅ — branch switch / FR locale / avatar menu (profile/password/logout) / loyalty balance. No notifications bell.
- Launch-smoke ✅ — POS / KDS / OSS all render (reference-only per D-2).

## FINDINGS (verified file:line)
- **[P2] Transactions: raw enum + non-FR currency** — `TransactionListComponent.vue:103` `{{ transaction.payment_method }}` (raw `COUNTER_CASH`/`COUNTER_MOBILE_BANKING`, no FR humanization) + `:110/:113` `{{ sign }} {{ amount }}` (no `formatPrice`, dot-decimal, no €) vs app-standard `formatPrice` (e.g. PosOrderShowComponent.vue:230). In-nav gérant report → reads unprofessional/ambiguous. **VERIFIED.**
- **[P2→P3] Time-format inconsistency** — 12h AM/PM on pos-orders/historique/transactions vs FR 24h on dashboard/cash-overview. (QA-observed; cross-page.)
- **[P3] Menu-vs-route drift** — 9 V1-hidden modules hidden from menu but 8/9 reachable by direct URL (full CRUD renders); credit-balance 404s. "Hidden" = menu-only, not route-gated. Low (single-admin local).
- **[P3] Z-report widget RBAC mismatch** — `LastZReportWidget.vue:85` client-gates `transactions`, server requires `pos-manage-fiscal` → dangling control for that role combo (admin holds all → low).
- **[P3] Coupons form 7 `LABEL.*` untranslated** (hidden module, latent — clear before any coupon enablement).
- **[P3] bare `/admin/profile` 404** (no link → nil impact); **[P3] stock-low widget `items` vs `items_show` gate** (not mounted on dashboard → unreachable); **[P3] sidebar gate fail-open** (server-enforced → UX-only).

## IMPROVEMENT LIST (gérant lens, priority)
1. (P2) Transactions: humanize payment-mode → FR (Espèces/Mobile/Carte) + `x,xx €` via `formatPrice`.
2. (P2/P3) Standardize all timestamps to FR 24h.
3. (P3) Route-guard the 9 V1-hidden modules (404/redirect by URL, not menu-only).
4. (P3) Translate coupons `LABEL.*` keys.
5. (P3) `/admin/profile` → redirect `/edit-profile` (kill dead URL).
6. (Polish) Cap/auto-expire stale SLA alerts so the badge = actionable kitchen delays, not historical noise.
7. (Optional) Header "needs attention" bell → SLA/encaissement queue.

Counts (W2 total, deduped): P0=0 · P1=0 · P2=2 · P3=7.
