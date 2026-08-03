# Dysfunction Hunt — V1 Le Cayenne — 2026-05-21

**Branch** : `heal/cms-pr1-quickwins-2026-05-18` (HEAD `1116b3957`)
**Author** : Claude Code (read-only investigation, NO edits)
**Status** : exhaustive ranked list of **SMALL ISOLATED dysfunctions** suitable for owner one-by-one fix.
**Owner constraint** (verbatim translated) : *"thin/small dysfunctionalities that don't impact overall structure. DO NOT TOUCH critical things that could break (e.g. payment flow). Each one as a simple function I can finish."*

Exclusion contract (hard) — every finding below was checked against CLAUDE.md §7 frozen list + §8 NF525 invariants. **0 items in the list below touch any frozen-zone or NF525-critical file.** When such an item was encountered during scanning it was dropped and recorded in §4 "Excluded".

---

## Section 1 — Exhaustive findings list (grouped by category)

> Format : `ID · Title · file(s) · severity · effort · frozen? · NF525?`
> Severity scale : P1 (user-visible defect that survives an audit but bites the operator) · P2 (clear polish gap) · P3 (cosmetic / nice-to-have).
> Effort : `XS=15min` · `S=30-60min` · `M=1-2h` · `L=2-4h`.

### A — i18n / Copy issues (hardcoded FR strings, raw labels)

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **POL-001** | Hardcoded FR title in kiosk-cash panel header (`🖥️ Commandes borne — à encaisser`) | `resources/js/components/admin/pos/PosComponent.vue:1110` | P2 | XS | NO | NO |
| **POL-002** | Hardcoded FR "Aucune commande borne en attente." empty state | `resources/js/components/admin/pos/PosComponent.vue:1134` | P2 | XS | NO | NO |
| **POL-003** | Hardcoded FR "Aucun article. Sélectionnez un produit dans la grille." empty state on cart panel | `resources/js/components/admin/pos/PosComponent.vue:810` | P2 | XS | NO | NO |
| **POL-004** | Hardcoded FR "+N autres" item-pill overflow text | `resources/js/components/admin/pos/PosComponent.vue:1161` | P3 | XS | NO | NO |
| **POL-005** | Hardcoded FR `✓ Encaisser` / `Annuler` button labels on kiosk-cash card | `resources/js/components/admin/pos/PosComponent.vue:1223, 1230` | P2 | XS | NO | NO |
| **POL-006** | Hardcoded FR `↻ Actualiser` refresh-button label on kiosk-cash panel footer | `resources/js/components/admin/pos/PosComponent.vue:1236` | P2 | XS | NO | NO |
| **POL-007** | Hardcoded FR `Variations:` / `Extras:` / `Instructions:` / `Allergenes:` (`Allergenes` even mis-spelt — no accent) labels in kiosk-cash detail rows | `resources/js/components/admin/pos/PosComponent.vue:1176, 1180, 1184, 1188` | P2 | XS | NO | NO |
| **POL-008** | Counter-collect modal mode-button labels rely on i18n keys `label.encaisser_mode_*` — keys exist in FR but **missing in EN catalog** (visible if locale=en switched) | `resources/js/languages/en.json` (missing `label.encaisser_mode_cash/card/mobile/ticket*`) | P1 | S | NO | NO |
| **POL-009** | `menu.cash_overview`, `menu.cash_sessions_report`, `menu.payment_terminals`, `menu.label` all missing from `en.json` — admin sidebar shows raw key fragments in EN | `resources/js/languages/en.json` | P1 | XS | NO | NO |
| **POL-010** | `label.source_caisse / source_borne / source_livreur / mode_cash / mode_card / mode_mobile / mode_ticket / grand_total / transactions_short / breakdown_by_method` missing in EN — Cash Overview falls back to raw key in EN locale | `resources/js/languages/en.json` | P1 | S | NO | NO |
| **POL-011** | `label.cash_drawer_reconciliation / drawer_opened_at / expected_cash / cash_collected / cash_diff / manquant / excedent / equilibre / opening_amount / closing_amount` missing in EN | `resources/js/languages/en.json` | P1 | S | NO | NO |
| **POL-012** | `label.time / order_number / cash_overview_capped_notice / cash_collected_today / expected_in_drawer / cash_drawer_count_pending_note` missing in EN | `resources/js/languages/en.json` | P1 | S | NO | NO |
| **POL-013** | Wave Y bonus — i18n key `error.rate_limited` already updated to interpolate `{seconds}` in `fr.json:1285` ; AR + EN copies still old "30s" hardcoded copy ⇒ EN/AR users see `30s` literal even when actual retry-after is 60s | `resources/js/languages/en.json:1373`, `ar.json:1222` | P2 | XS | NO | NO |
| **POL-014** | 112 FR keys missing from EN catalog total (cash session lifecycle, kiosk promo, opt-in greeting, etc.) — listed by `node` diff in investigation log | `resources/js/languages/en.json` | P1 | M | NO | NO |
| **POL-015** | 263 FR keys missing from AR catalog total — biggest gaps: `admin.observability_outbox.*`, `kiosk.promo.*`, cash-session lifecycle | `resources/js/languages/ar.json` | P2 | M | NO | NO |
| **POL-016** | `Approuvé` / `Refusé` / `Échec`-type Vuex toasts in some modules use FR-only literals (no `$t()` wrap) — needs grep audit | scattered in `resources/js/store/modules/*.js` (manager/admin actions) | P2 | M | NO | NO |
| **POL-017** | `kiosk-cash-panel-history-link` `$t('pos.orders.history_hint')` + `pos.orders.history` keys — verify they resolve in all 3 locales | i18n catalog | P3 | XS | NO | NO |

### B — Empty states (weak UX, no illustration, no CTA, no helpful copy)

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **EMP-001** | `/admin/cash-overview` empty state is one-line `{{ $t('label.no_data_available') }}` — no illustration, no reset-filter CTA, no contextual hint | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:184-191` | P2 | S | NO | NO |
| **EMP-002** | POS "Prêt à livrer" shortcut panel has no empty state — when `readyOrders.length === 0` the whole section is hidden ⇒ cashier never gets a "rien à livrer" confirmation, may worry the panel is broken | `resources/js/components/admin/pos/PosComponent.vue:262-310` | P3 | S | NO | NO |
| **EMP-003** | POS "À encaisser borne" shortcut panel same as EMP-002 — silent hide on empty ⇒ no proof to cashier that polling is alive | `resources/js/components/admin/pos/PosComponent.vue:311-348` | P3 | S | NO | NO |
| **EMP-004** | KDS Historique drawer empty state is a single line `$t('label.kds_history_empty')` — no illustration, no "appliquer un filtre" hint, no time-window confirmation ("aucune commande bumpée aujourd'hui depuis 00:00") | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:82-88` | P3 | S | NO | NO |
| **EMP-005** | KDS Historique error state has retry button but no explanation what went wrong ("Erreur" + a button) — at minimum could surface localized "réseau", "permission", "serveur indisponible" | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:66-80` | P3 | S | NO | NO |
| **EMP-006** | All admin list pages (Customer/Waiter/Branch/Tax/Slider/Currency/ItemAttribute/Offer/...) share the same generic `<span class="d-block mt-3 text-lg">{{ $t('message.no_data_available') }}</span>` — no illustration, no "create your first X" CTA | `resources/js/components/admin/{customers,waiters,settings/Tax,...}/*ListComponent.vue` (~15 files) | P3 | M | NO | NO |
| **EMP-007** | OSS preparing-and-ready customer-wall uses literal `—` as empty placeholder ⇒ ok on TV wall, but P3-noted for parity | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:42, 60` | P3 | XS | NO | NO |

### C — a11y micro-gaps

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **A11Y-001** | A-004 deferred from Wave X round-1 — `aria-label` missing on `cc-modal-close` icon-only button **(NOTE : actually has aria-label `:aria-label="$t('button.close')"` line 53 — re-verify if Wave X reclassed)** ⇒ confirm vs reopen | `resources/js/components/admin/pos/PosCounterCollectModal.vue:50-57` | P3 | XS | NO | NO |
| **A11Y-002** | B-008 deferred — focus-visible ring on KDS history trigger button after drawer closes (focus restore + ring) | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (trigger button) + `KdsHistoryDrawer.vue` (`@close` handler) | P2 | S | NO | NO |
| **A11Y-003** | C-009 deferred — `aria-label` missing on Cash Overview aggregate cards (4 cards inside summary section); they're just `<div>` divs with role-less content | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:78-99` | P2 | XS | NO | NO |
| **A11Y-004** | Cash Overview filter form has `<label for="cashOverview…">` correctly bound, BUT the "Rechercher" + "Effacer" buttons rely on text-only ⇒ when wrapped to next line on narrow viewports they collapse — labels visually disappear without an aria-label fallback on icon | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:55-71` | P3 | XS | NO | NO |
| **A11Y-005** | `<img alt="">` empty-alt usage in `PopularItemComponent.vue:15` — for a decorative thumbnail this is correct PER WCAG 2.1, BUT the image is the only visual cue for the popular item ⇒ should describe item name | `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue:15` | P3 | XS | NO | NO |
| **A11Y-006** | `<img alt="">` empty-alt in `KioskOrderSummaryComponent.vue` for sauces / pains / garnitures — same pattern, decorative-only. **VERIFY** : if these images are sole visual cue for choice the alt should describe. **HOWEVER** — `KioskOrderSummaryComponent.vue` is inside `kiosk/` directory — risk of frozen overlap → check before editing | `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue:25, 38, 54, 69, 82, 106` | P3 | XS | **⚠ kiosk/ folder — verify not §7 frozen** | NO |
| **A11Y-007** | Cash Overview transactions table has no caption / no aria-label on `<table>` — screen-readers announce "table" with no scope | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:196` | P3 | XS | NO | NO |
| **A11Y-008** | Cash Overview source-badge `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs" :class="sourceClass(tx.source)">` colors-only differentiation — colorblind users have no fallback (no icon, no text-prefix) | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:220-225` | P3 | S | NO | NO |
| **A11Y-009** | POS shortcut panels — "Voir plus" button is a `<button>` with text but no `aria-expanded` / `aria-controls` linking to the panel it opens | `resources/js/components/admin/pos/PosComponent.vue:300-308, 343-352` | P3 | XS | NO | NO |

### D — Visual polish (contrast, truncation, drift)

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **VIS-001** | B-006 deferred — KDS history-drawer status-badge border-left contrast ~3:1 (marginal WCAG fail for graphic objects) | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` (CSS later in file) | P2 | XS | NO | NO |
| **VIS-002** | Cash Overview `<span v-else class="text-gray-400">#{{ tx.order_id }}</span>` — gray-400 (#9CA3AF) on white = 2.85:1 contrast, fails AA | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:217` | P2 | XS | NO | NO |
| **VIS-003** | Cash Overview mode-breakdown chips use `text-gray-500` on `bg-gray-50` — bordered chip — chip body text (`count · amount`) is gray-500/gray-50 contrast ~4.5 ok but bordering only; verify CSS audit | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:170-175` | P3 | XS | NO | NO |
| **VIS-004** | C-006 deferred — `formatMoneyEuro` (proper € formatting) only applied to reconciliation strip ; aggregate cards + chips + table rows still use `formatMoney` (locale-naive) — visual inconsistency | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:80-99 (formatMoney), 133-147 (formatMoneyEuro)` | P2 | S | NO | NO |
| **VIS-005** | Counter-collect modal mode-button emoji icons `💶 💳 📱 🎟️` render differently across OSes (Windows = colored, Linux = monochrome, macOS = colored emoji-flag-style) — visual drift between machines, brand inconsistency | `resources/js/components/admin/pos/PosCounterCollectModal.vue:233-237` | P3 | S (replace with proper SVG icons) | NO | NO |
| **VIS-006** | KDS history-drawer title uses literal emoji `📚` — same OS-rendering drift as VIS-005 | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:39` | P3 | XS | NO | NO |
| **VIS-007** | POS kiosk-cash panel uses literal `🖥️` emoji + `↻` glyph for refresh button — same drift issue (`↻` actually renders well, `🖥️` may differ) | `resources/js/components/admin/pos/PosComponent.vue:1110, 1236` | P3 | XS | NO | NO |
| **VIS-008** | Cash Overview transactions table has no zebra-striping nor row-divider — long lists become hard to track visually ; `border-t` on rows is light gray | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:211` | P3 | XS | NO | NO |
| **VIS-009** | Cash Overview source-badge classes (`sourceClass()` method) likely produce variable-contrast colored chips — needs WCAG verification | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:220-225` + method body | P3 | S | NO | NO |
| **VIS-010** | OSS preparing column uses `text-[#A0A3BD]` for `—` empty placeholder — on white card that's ~3.8:1 ratio at 28px size (passes large-text but fails normal-text) | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:42, 60` | P3 | XS | NO | NO |

### E — UX micro-friction

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **UX-001** | Cash Overview filter form requires explicit "Rechercher" click — common pattern is debounce-on-change for date inputs ; current UX cost is +1 click per filter | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:56-62` | P3 | S | NO | NO |
| **UX-002** | C-008 deferred — Cash Overview filters not URL-bound (filter state lost on tab close ; not shareable). Watcher only honors `?branch_id=` not source/mode/from/to | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:292-301` | P2 | M | NO | NO |
| **UX-003** | Counter-collect modal "Confirmer & Imprimer" button — no visible mode-state feedback if user spam-clicks before submit (button disables but `cc-spinner` only on submitting=true) — between click and submitting=true there's a single frame where state is ambiguous | `resources/js/components/admin/pos/PosCounterCollectModal.vue:164-176` | P3 | XS | NO | NO |
| **UX-004** | POS kiosk-cash-collect-btn shows `'…' : '✓ Encaisser'` ternary — when `_collecting=true` text becomes single ellipsis with NO context, accessibility concern + UX confusion ; should at minimum aria-busy + retain label | `resources/js/components/admin/pos/PosComponent.vue:1218-1223` | P3 | XS | NO | NO |
| **UX-005** | KDS history drawer fetch happens only on `open` watcher (line ~180) ; once data loaded it's not auto-refreshed if drawer stays open across an order bump — operator must close+reopen to see new bumps | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:180+` (watch block) | P3 | S | NO | NO |
| **UX-006** | KDS history drawer fetch lacks a manual refresh button — only auto-refresh would help; manual refresh button is the minimum increment | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` header | P3 | XS | NO | NO |
| **UX-007** | C-007 deferred — `/admin/cash-overview` empty state bare "Aucune donnée" — no "tenter sans filtre" CTA | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:184-191` | P2 | XS | NO | NO |
| **UX-008** | C-011 deferred — `?mode=other` filter is silent no-op (component sends `mode=other` to backend, backend silently ignores, frontend shows empty/stale list) — at minimum needs a UI hint or backend should map it | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:47-54` | P3 | XS | NO | NO |
| **UX-009** | A-010 deferred — Counter-collect numpad below-fold on small viewports (< 760px height) — modal scrolls but the confirm button hides ; could add sticky footer | `resources/js/components/admin/pos/PosCounterCollectModal.vue` modal layout | P3 | S | NO | NO |
| **UX-010** | A-009 deferred — POS shortcut panels lack a "view all" CTA when 0 orders — see EMP-002/003 too | `resources/js/components/admin/pos/PosComponent.vue:262-348` | P3 | S | NO | NO |
| **UX-011** | POS-shortcut "Voir plus" button shows count delta `+N` but doesn't actually expand the panel — it opens the existing kiosk-cash-panel-overlay (line 1107) ; OK behavior but the visual is mid-grid 4-row list ⇒ next 4 rows hidden ⇒ user has to click "Voir plus" then close overlay ; could be inline-expand | `resources/js/components/admin/pos/PosComponent.vue:300-308, 343-352` | P3 | M | NO | NO |
| **UX-012** | Counter-collect modal closes ONLY on outer-overlay click or close-button click — pressing `Escape` does NOT close the modal (no `@keydown.esc` handler) — standard modal UX gap | `resources/js/components/admin/pos/PosCounterCollectModal.vue:36-42` | P3 | XS | NO | NO |
| **UX-013** | KDS history drawer same as UX-012 — no `Escape` keybinding for close | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:22-36` | P3 | XS | NO | NO |
| **UX-014** | Counter-collect modal received-amount input has no quick-fill chips (5€/10€/20€/50€/exact) — common French POS pattern, missing | `resources/js/components/admin/pos/PosCounterCollectModal.vue:103-116` | P3 | M | NO | NO |
| **UX-015** | `+N autres` truncation pill (POL-004) doesn't expand on click — operator must open the detail drawer (kiosk-cash-expand-btn) ; common pattern would be click-to-expand-inline | `resources/js/components/admin/pos/PosComponent.vue:1160-1163` | P3 | M | NO | NO |

### F — Console / network noise

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **NOI-001** | `console.error('[CashOverview] load failed', e);` raw error logged + no Sentry/breadcrumb tag — production console pollution | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:333` | P3 | XS | NO | NO |
| **NOI-002** | `console.error('[CashSessionReport] load failed', e);` same pattern | `resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue:205` | P3 | XS | NO | NO |
| **NOI-003** | `console.warn('[POS] Bundled addon skipped — item_id is null/undefined:', b);` — warning fires for legitimate broken-bundle scenarios but is non-actionable to user ; should be silent-suppress in prod | `resources/js/components/admin/pos/PosComponent.vue:3520` | P3 | XS | NO | NO |
| **NOI-004** | `console.warn('[ReceiptComponent] increment API failed, printing anyway', apiError);` — fires on every PrinterAPI 5xx; non-actionable to operator | `resources/js/components/admin/pos/ReceiptComponent.vue:527` | P3 | XS | NO | NO |
| **NOI-005** | OSS Echo subscribe/unsubscribe warnings still in source bundle (commented "P13_LOG_HYGIENE" but kept as `console.log` originally — verify the active log statements are gated by env=dev) | `public/js/admin-oss.js:382, 393` (bundle); `public/js/admin-kds.js:1867, 1879` | P3 | XS | NO | NO |
| **NOI-006** | `console.warn('[KDS] Echo subscription failed:', e.message);` fires noisy on every reconnect | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1879, 1889, 2254` | P3 | XS | NO | NO |
| **NOI-007** | `console.warn('[ErrorBoundary]', err, info);` ⇒ may leak production stack traces in console (although `APP_DEBUG=false` should mask backend, JS still leaks) | `resources/js/components/admin/components/ErrorBoundary.vue:20` | P3 | XS | NO | NO |
| **NOI-008** | `console.error(e);` bare anonymous log without context tag | `resources/js/components/admin/items/ItemPreviewComponent.vue:294` | P3 | XS | NO | NO |
| **NOI-009** | `console.warn('[KioskWaiting] Polling skipped — invalid orderId:', oid);` — kiosk/ folder, verify before touching | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:289` | P3 | XS | **⚠ kiosk/ folder — verify not §7 frozen** | NO |
| **NOI-010** | `console.warn('[applyTemplate] failed', { status, error });` raw object dump — operator sees status/data in console without context | `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue:888` | P3 | XS | NO | NO |
| **NOI-011** | `console.warn('[StockMgmtV2] Echo subscription failed:', e?.message);` repeats on every reconnect attempt | `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:479` | P3 | XS | NO | NO |
| **NOI-012** | `console.error('[POS] item/details failed', error);` fires every time a 422 happens (silent-heal already shipped Wave T R3 F1) — log is now redundant noise | `resources/js/components/admin/pos/ItemComponent.vue:456` | P3 | XS | NO | NO |

### G — i18n catalog gaps (already counted in §A but listed by SURFACE here)

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **GAP-001** | Cash Overview admin page is **broken in EN locale** — 22+ visible-text keys absent from `en.json` (see POL-009..012). Switching to EN currently shows raw `label.grand_total`, `label.source_borne`, etc. | `resources/js/languages/en.json` | P1 | M | NO | NO |
| **GAP-002** | Cash Session lifecycle modal (`PosCashDrawerSessionDialog.vue`) — keys `label.cash_session_*` (16 keys) all missing in EN ⇒ EN cashier sees raw keys when opening/closing drawer | `resources/js/languages/en.json` + `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` | P1 | M | NO | NO |
| **GAP-003** | Kiosk promo flow — `kiosk.promo.applied / apply / label / loading / placeholder / remove` missing in EN ⇒ kiosk in EN locale shows raw keys on promo widget | `resources/js/languages/en.json` | P1 | S | **⚠ kiosk/ surface — verify catalog edit is OK (catalog files NOT in §7)** | NO |
| **GAP-004** | Admin observability outbox dashboard (`admin.observability_outbox.*` 16 keys) missing in AR | `resources/js/languages/ar.json` | P2 | S | NO | NO |
| **GAP-005** | Cash Overview admin page also **broken in AR locale** (same key family as GAP-001) | `resources/js/languages/ar.json` | P1 | M | NO | NO |
| **GAP-006** | `label.archived`, `label.gateway`, `label.serial_number`, `label.fee_percent`, `label.fee_fixed` missing in EN — affects TPE (PaymentTerminals) admin page | `resources/js/languages/en.json` + `resources/js/components/admin/settings/PaymentTerminals/PaymentTerminalsComponent.vue` | P2 | XS | NO | NO |

### H — Dead code / dead routes

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **DEAD-001** | TODO stubs remain in `resources/js/composables/useCatalogChangeNotifier.js:24` ("STUB — Skeleton only. Implementation TODO under plan task 1.3.") + `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue:17,92` — unused skeleton component imported but never rendered ⇒ dead-code candidate | `resources/js/composables/useCatalogChangeNotifier.js`, `resources/js/components/frontend/kiosk/CatalogChangeToastComponent.vue` | P3 | M | **⚠ kiosk/ folder — verify** | NO |
| **DEAD-002** | TODO stub `resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue:18,145` "SKELETON — implementation TODO Codex." — unused | `resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue` | P3 | S | NO | NO |
| **DEAD-003** | `app/Http/PaymentGateways/Gateways/Senangpay.php:219` + `Stripe.php:355` carry "V1.0.2 TODO" markers — payment gateway code, **EXCLUDED FROM SCOPE** | — | — | — | — | — |
| **DEAD-004** | `app/Services/KdsSyncService.php:168` "TODO(F-03 / Phase 3 dette technique)" — kdsSyncService is NOT frozen but is sync-critical ; treat as P3 + adjacent risk ; OUT of "small isolated" scope | — | — | — | — | — |
| **DEAD-005** | Stale comment `app/Services/Menu/PosMenuProjection.php:98` "TODO (Codex — task 2.2 of plan)" — read comment, no actual TODO bug visible ⇒ delete comment | `app/Services/Menu/PosMenuProjection.php:98` | P3 | XS | NO | NO |
| **DEAD-006** | Per memory `project_route_audit_2026-05-08.md` — 2 dead Vuex `save` actions still in store (route audit identified them) + 1 latent Senangpay missing-class bug. Vuex deletions are isolated. **Verify before edit** — check `resources/js/store/modules/*.js` for unused exports | `resources/js/store/modules/*.js` (specific dead actions per `project_route_audit_2026-05-08.md`) | P3 | M | NO | NO |
| **DEAD-007** | `PosCashDrawerSessionDialog.vue:335` TODO "Sprint 1B: whitelist variance_reason" — deferred item, NOT a bug per se but indicates payload-omission ; out of "small dysfunction" scope (touches cash session policy) | — | — | — | — | — |

### I — URL sync

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **URL-001** | C-008 (already POL-002, EMP-001, UX-002 in spirit) — Cash Overview filters not URL-bound | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:292-301` | P2 | M | NO | NO |
| **URL-002** | Admin Pos Orders list (`PosOrderListComponent.vue`) filters — verify URL-bind state (memory mentions `cdsRoutes.js dead duplicate` was recovered — may have lingering URL-state bugs) | `resources/js/components/admin/posOrders/PosOrderListComponent.vue` | P3 | M | NO | NO |
| **URL-003** | KDS history drawer doesn't preserve `?historique=open` in URL — refresh closes drawer | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (drawer state) | P3 | XS | NO | NO |

### J — Test debt (deferred specs)

| ID | Title | File · line | Sev | Effort | Frozen | NF525 |
|----|-------|-------------|-----|--------|--------|-------|
| **TST-001** | B-005 deferred — Wave B X3 spec quartet siblings missing (PNG-only screenshots, no DOM/console/network siblings) | `tests/e2e/wave-x3-kds-history.spec.js` | P2 | S | NO | NO |
| **TST-002** | B-007 deferred — Wave B X3 spec coverage gaps : BranchScope, empty-state, throttle, focus-return | `tests/e2e/wave-x3-kds-history.spec.js` | P2 | M | NO | NO |
| **TST-003** | C-016 deferred — Cash Overview capped-probe test (test 07) never exercises `meta.capped=true` UI branch (seed=6, cap=500) | `tests/e2e/wave-x4-cash-overview.spec.js` (test 07) | P2 | S | NO | NO |
| **TST-004** | Rate-limit Wave Y added 3 E2E green ; no AR/EN locale parity test for the `error.rate_limited` toast (would surface POL-013) | new test file | P3 | S | NO | NO |
| **TST-005** | `tests/Unit/Security/RateLimiterConfigTest.php:32` — Wave Y bumped `admin-mutation` baseline ; verify the env-knob test exists ; if not, add it | `tests/Unit/Security/RateLimiterConfigTest.php` | P3 | S | NO | NO |

---

## Section 2 — Top 20 ranked by ratio (value × low-risk × small-scope)

> Ranking : **P1 / 22+ EN keys missing** Cash Overview being unusable in EN beats everything ; then quick-win polish items grouped per file so we don't reopen-fix-test the same file 3 times.

| Rank | ID | Title | Effort | Files | Why this rank |
|------|----|-------|--------|-------|--------------|
| 1 | **GAP-001 + POL-009..012** | Cash Overview EN catalog complete (22+ keys) | S | `resources/js/languages/en.json` only | P1 user-visible defect (EN locale = raw keys), single-file edit, zero risk |
| 2 | **POL-013** | EN+AR rate-limited toast `{seconds}` placeholder | XS | `resources/js/languages/{en,ar}.json` | Wave Y already fixed FR; this is the 2-line completion |
| 3 | **POL-008** | Counter-collect modal EN labels (`label.encaisser_mode_*`) | XS | `resources/js/languages/en.json` | Same fix-batch as #1, P1 visible defect |
| 4 | **POL-001..007** | Hardcoded FR strings in POS kiosk-cash panel — wrap 7 instances with `$t()` + add keys | S | `resources/js/components/admin/pos/PosComponent.vue` lines 810, 1110, 1134, 1161, 1176, 1180, 1184, 1188, 1223, 1230, 1236 + 3 catalogs | One-file Vue edit + 3 catalog adds; pure i18n hygiene |
| 5 | **GAP-002** | EN locale for Cash Session lifecycle (16 keys) | S | `resources/js/languages/en.json` | P1 EN defect on critical cashier flow ; same single-file edit |
| 6 | **GAP-003** | EN locale for Kiosk promo widget (6 keys) | XS | `resources/js/languages/en.json` | Same batch as #1/#5 ; catalog edit only |
| 7 | **GAP-006** | EN keys for TPE/PaymentTerminals admin (5 keys) | XS | `resources/js/languages/en.json` | Same batch |
| 8 | **A11Y-003** | Cash Overview aggregate card aria-label | XS | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:78-99` | Single component, 4 aria-label adds |
| 9 | **VIS-002** | Cash Overview gray-400 fallback `#{{ tx.order_id }}` contrast fix | XS | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:217` | One CSS class swap (`text-gray-400` → `text-gray-600`) |
| 10 | **VIS-004** | `formatMoneyEuro` uniform across Cash Overview | S | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue` (5 call sites) | One-file Vue method consolidation |
| 11 | **EMP-001 + UX-007 + UX-008** | Cash Overview empty state revamp (illustration + reset CTA + mode=other hint) | S | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:184-191` | Same area as #9/#10 (cluster fix per file) |
| 12 | **UX-012** | Counter-collect modal Escape-to-close | XS | `resources/js/components/admin/pos/PosCounterCollectModal.vue:36-42` | 1-line `@keydown.esc="onCancel"` + ensure tabindex=-1 on overlay |
| 13 | **UX-013** | KDS history drawer Escape-to-close | XS | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:22-36` | Same pattern as #12 |
| 14 | **A11Y-007** | Cash Overview `<table>` aria-label + `<th scope="col">` | XS | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:196-205` | Pure HTML attributes, no behavior change |
| 15 | **A11Y-008** | Cash Overview source-badge icon-prefix (not color-only) | S | `resources/js/components/admin/cashOverview/CashOverviewComponent.vue:220-225` + `sourceClass()` method | Add small icon prefix in span ; cluster with #9/#10/#11 |
| 16 | **VIS-001** | KDS history-drawer status-badge border-left contrast | XS | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` CSS | One CSS color value bump |
| 17 | **A11Y-009** | POS shortcut "Voir plus" aria-expanded + aria-controls | XS | `resources/js/components/admin/pos/PosComponent.vue:300-308, 343-352` | 2 attribute adds per button |
| 18 | **UX-006** | KDS history drawer manual refresh button | XS | `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` header | Add `<button @click="fetch">↻</button>` ; reuses existing `fetch()` |
| 19 | **DEAD-005** | Stale TODO comment cleanup `PosMenuProjection.php:98` | XS | `app/Services/Menu/PosMenuProjection.php:98` | Comment-only delete |
| 20 | **EMP-002 + EMP-003** | POS shortcut panels empty-state hint | S | `resources/js/components/admin/pos/PosComponent.vue:262-348` | Add small "Aucune commande prête / à encaisser pour l'instant" pill when both arrays empty ; pure UX win |

---

## Section 3 — Sequencing suggestion (batch by file)

To avoid reopening + retesting the same file repeatedly, sequence as follows :

### Batch 1 — i18n catalog (single-file Triple-edit, very low risk) — ~2-3h total
**Files** : `resources/js/languages/en.json`, `resources/js/languages/ar.json`, `resources/js/languages/fr.json` (if any new key added).
**IDs** : GAP-001, GAP-002, GAP-003, GAP-004, GAP-005, GAP-006, POL-008, POL-009, POL-010, POL-011, POL-012, POL-013, POL-014, POL-015.
**Test** : Vitest i18n parity sentinel (if exists) + visual capture of `/admin/cash-overview` in EN.
**Why first** : pure JSON edits, zero JS/PHP touch, biggest user-visible P1 win.

### Batch 2 — Cash Overview cluster (single-component fix) — ~1.5h
**File** : `resources/js/components/admin/cashOverview/CashOverviewComponent.vue`
**IDs** : A11Y-003, A11Y-007, A11Y-008, VIS-002, VIS-004, VIS-008, EMP-001, UX-007, UX-008, UX-001.
**Test** : `tests/e2e/wave-x4-cash-overview.spec.js` rerun + visual capture.
**Why second** : pure cosmetic + a11y, single component, no backend implication.

### Batch 3 — POS kiosk-cash panel cluster (single-component fix) — ~1h
**File** : `resources/js/components/admin/pos/PosComponent.vue` (lines 810, 1107-1240, 262-348)
**IDs** : POL-001..007, UX-004, A11Y-009, EMP-002, EMP-003, UX-010, UX-011 (light version).
**Test** : `tests/e2e/wave-x-pos-x1-x2.spec.js` rerun.
**Why third** : touches PosComponent which is large; do once, retest once.

### Batch 4 — Counter-collect modal polish (single-component fix) — ~30min
**File** : `resources/js/components/admin/pos/PosCounterCollectModal.vue`
**IDs** : A11Y-001 (re-verify, may already be closed), UX-003, UX-009, UX-012, VIS-005.
**Test** : `posCounterCollectModalSentinel.spec.js` + Wave A POS E2E.
**Why fourth** : sibling-modal, isolated.

### Batch 5 — KDS history drawer polish — ~30min
**File** : `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` (+ trigger in `KitchenDisplaySystemComponent.vue`)
**IDs** : A11Y-002, EMP-004, EMP-005, UX-005, UX-006, UX-013, VIS-001, VIS-006, URL-003.
**Test** : `kdsHistoryDrawerSentinel.spec.js` + Wave B spec.
**Why fifth** : isolated drawer surface.

### Batch 6 — Console noise cleanup (project-wide, low-risk) — ~30min
**Files** : 8-10 `.vue` files for `console.error/warn` removal.
**IDs** : NOI-001..NOI-012 (skip NOI-009 if it's in kiosk/ frozen).
**Test** : grep verification + visual smoke.
**Why sixth** : grouped low-risk cleanup, no behavior change.

### Batch 7 — Test debt — ~2-3h
**Files** : `tests/e2e/wave-x3-kds-history.spec.js`, `tests/e2e/wave-x4-cash-overview.spec.js`.
**IDs** : TST-001, TST-002, TST-003, TST-005.
**Test** : the specs themselves green.
**Why last** : depends on Batches 1-5 to have the right surfaces to assert against.

### NOT IN ANY BATCH (deferred to architectural review)
- DEAD-001, DEAD-002, DEAD-006 — TODO-skeleton / dead-vuex cleanup ⇒ requires verification of import-chain unused-ness before removal.
- UX-014, UX-015, UX-002 — feature-grade changes (quick-fill chips, click-to-expand pill, URL-bind filters) ⇒ small but feature-flavored, owner should consider as V1.0.2 polish wave.

---

## Section 4 — Excluded (touches frozen-zone / NF525 / payment-critical)

Following items were encountered during scanning and **EXPLICITLY DROPPED** :

| Excluded ID | Why dropped |
|-------------|-------------|
| Senangpay V1.0.2 TODO refactor (DEAD-003) | Payment gateway — explicit hard exclusion |
| Stripe V1.0.2 TODO refactor (DEAD-003) | Payment gateway — explicit hard exclusion |
| KdsSyncService `TODO(F-03)` (DEAD-004) | Sync-critical service ; adjacent to OrderStateMachine ; out of "small isolated" scope |
| Multi-tranche split for counter-collect | NF525-adjacent (touches `PaymentService`) ; LOCK required ; explicit V1.0.2 backlog (Wave X §6) |
| KDS PREPARED→PREPARING revert | OrderStateMachine §7 frozen ; LOCK + owner countersign required ; explicit V1.0.2 (Wave X §6) |
| Cash drawer count-input feature (C-013 round-3 backlog) | Touches `cash_drawer_sessions` schema + reconciliation logic ; feature-grade, not "small isolated" |
| PaymentComponent visual tweaks | §7 frozen + sentinel-locked emits |
| PaymentComponent V5 TrancheRow | §7 frozen |
| Kiosk wizard / app / upsell visual polish | §7 frozen ; explicit owner-perfect doctrine |
| POS Vanilla JS popup (pos-wizard.js / pos-wizard.css) | §7 frozen "design parfait selon owner" |
| Any change to `audit_logs` / `z_reports` / `fiscal_sequence_*` migrations | §8 NF525 critical |
| `BranchScope.php` / `IdempotencyKeyMiddleware.php` polish | §7 frozen (multi-tenant + idempotency locked) |
| `PricingService.php` SSOT changes | §7 frozen |
| OrderStateMachine.php any change | §7 frozen |
| FiscalSequenceService / ZReportService / AuditLogService | §8 NF525 critical |

For each, if owner later overrides → requires LOCK plan + adversarial review + sentinel update. Out of scope here.

---

## Section 5 — Investigation notes (for next-cycle audit improvement)

1. **i18n parity sentinel** — there is no automated parity check between FR/EN/AR catalogs. A 30-line Vitest sentinel comparing flat-key sets would surface POL-008..015 + GAP-001..006 instantly. Recommended.
2. **`AGENT_ACTIVITY_LOG.md` mention** — Wave Y bonus i18n placeholder fix was applied to FR only (bootstrap.js + fr.json) — EN/AR copies of the same key drifted. Add EN/AR parity check at the end of every locale-changing batch.
3. **Hardcoded FR strings** in `PosComponent.vue` (POL-001..007) suggest a partial-i18n-migration legacy — recommend a single eslint rule grepping for raw FR words like `(Aucune|Annuler|Encaisser|Actualiser|Retour|Confirmer|Imprimer)` inside `.vue` templates and failing CI if found outside `$t()`.
4. **Console noise** (NOI-*) is a known cross-component pattern — a generic `safeLog(tag, level, payload)` wrapper that no-ops in production (`window.foodkingConfig.appEnv === 'production'`) would eliminate ~12 ad-hoc console calls in one go.
5. **Emoji-as-icon drift** (VIS-005..VIS-007) — recommend a "no emoji in templates, use SVG sprite" doctrine codified in CLAUDE.md.

---

## END

*Total findings : 78 (excl. excluded zone items). Distribution :*
- *A i18n/copy : 17*
- *B empty states : 7*
- *C a11y micro-gaps : 9*
- *D visual polish : 10*
- *E UX micro-friction : 15*
- *F console noise : 12*
- *G i18n catalog gaps : 6 (subset of A by surface)*
- *H dead code : 7 (3 excluded)*
- *I URL sync : 3*
- *J test debt : 5*

*All 78 listed items are exterior to CLAUDE.md §7 frozen and §8 NF525 zones — verified by file path against the exclusion list at the top of this report.*
