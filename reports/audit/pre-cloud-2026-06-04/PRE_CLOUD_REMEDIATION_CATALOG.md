# Pre-Cloud Remediation & Validation Catalog — exhaustive companion

> Generated 2026-06-04 from `POS_BOX_AUDIT_MACHINE.json` + `SYSTEMS_AUDIT_MACHINE.json`. **PREPARE-ONLY** — this is the per-finding remediation+test map for the GOAL plan, not an execution log. No code has been changed.

**Total** 156 confirmed (BOX 66 + SYSTEMS 90) · P1 21 · P2 58 · P3 77 · frozen-touching 15 (**10 hard** = fix lands in a §7 file → LOCK; **5 referenced** = primary non-frozen, §7 file only referenced → fix non-frozen side) · NF525 33 · security 10 · needs-live-verify 5.

## Cross-report duplicate clusters (same file in BOX + SYSTEMS — fix once, validate both)

- `app/Services/OrderService.php` — M6-001[P1/BOX], S13-02[P1/SYSTEMS], S5-02[P2/SYSTEMS], S5-06[P2/SYSTEMS], S12-02[P2/SYSTEMS], S12-05[P3/SYSTEMS]
- `app/Services/PaymentService.php` — M10-01[P1/BOX], M10-05[P2/BOX], S16-01[P1/SYSTEMS]
- `app/Services/Receipt/ReceiptDataService.php` — M11-01[P1/BOX], S11-02[P1/SYSTEMS]
- `resources/js/services/appService.js` — M4-04[P2/BOX], M6-006[P3/BOX], S1-DASH-01[P1/SYSTEMS]

## Category → validation test-pattern (the discipline applied per finding)

| Category | Count | Test-pattern (acceptance) |
|---|---|---|
| logic | 69 | PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result. |
| ux | 28 | Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective. |
| functional | 14 | Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action. |
| nf525 | 12 | PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc. |
| deadcode | 11 | Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0. |
| security | 10 | PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed. |
| visual | 9 | CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures. |
| perf | 3 | Before/after measurement of the named metric (query count / block_for ms / payload size) via a timing test or live trace; assert improvement + no functional regression. |

## Remediation rows grouped by convergence wave

### W2-operator-receipt — 7 findings

| id | sev | cat | tags | micro | title | root |
|---|---|---|---|---|---|---|
| M11-01 | P1 | logic | NF525 live✓ | M11-receipt-reprint | Receipt 'Operateur' prints the CUSTOMER (Client passage), not the cashier — heal commit 6b26e1be3 is NOT in this branch | `app/Services/Receipt/ReceiptDataService.php` |
| M11-02 | P1 | nf525 | NF525 live✓ | M11-receipt-reprint | Legal ticket silently omits SIRET / TVA intra / N° caisse / legal mentions — branch 1 fiscal identity columns are all NU | `resources/js/components/admin/pos/ReceiptComponent.vue` |
| S11-02 | P1 | nf525 | NF525 | S11-staff-rbac | POS printed receipt 'Opérateur' shows the CUSTOMER name, not the cashier — operator identity wrong on the fiscal ticket  | `app/Services/Receipt/ReceiptDataService.php` |
| S16-01 | P1 | nf525 | NF525 | S16-kiosk-borne | Kiosk-counter-collected order never records the collecting cashier on the order — receipt 'Opérateur' stays the kiosk ma | `app/Services/PaymentService.php` |
| M11-03 | P2 | logic | NF525 | M11-receipt-reprint | 'Print anyway' error path emits a physical fiscal ticket with NO server counter increment and NO audit_logs entry | `resources/js/components/admin/pos/ReceiptComponent.vue` |
| M11-05 | P2 | deadcode | NF525 | M11-receipt-reprint | ReceiptDataServiceWireInTest gives FALSE GREEN on operator_name — it never models the production user_id!=creator_id cas | `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php` |
| M11-06 | P3 | ux |  | M11-receipt-reprint | Duplicata marker counts EVERY print incl. reprints from the tracker, but only fresh-payment print increments cart-reset  | `resources/js/components/admin/pos/ReceiptComponent.vue` |

### W3-money-fiscal — 27 findings

| id | sev | cat | tags | micro | title | root |
|---|---|---|---|---|---|---|
| M1-01 | P1 | logic | NF525 | M1-shell-auth-drawer | No-sale 'Ouvrir tiroir' button never reaches the backend NF525 audit route — i18n promises 'Action tracée' but nothing i | `resources/js/components/admin/pos/PosComponent.vue` |
| M1-02 | P1 | logic | NF525 | M1-shell-auth-drawer | Offline degraded-mode queues a CASH order with pos_received_amount=null — every replay 422s and the cash sale is silentl | `resources/js/components/admin/pos/PosComponent.vue` |
| M3-02 | P1 | logic | FROZEN-HARD NF525 | M3-item-wizard | Suppléments frites (Grande Portion +1,00 / Cheddar +1,00) comptés dans l'aperçu mais envoyés seulement en TEXTE — non re | `public/js/pos-wizard.js` |
| M6-002 | P1 | nf525 | FROZEN-HARD NF525 | M6-payment | Z-report 'total_by_method' buckets a split order's FULL total under the dominant tender — card/cash mix is mis-attribute | `app/Services/Fiscal/ZReportService.php` |
| M10-01 | P1(was P2) | nf525 | NF525 | M10-counter-collect | CASH collected with no open drawer session: order goes PAID but NO queryable cash-trail row — skip surfaced only as a tr | `app/Services/PaymentService.php` |
| S6-01 | P1 | functional | NF525 | S6-settings-catalog | Tax & Currency UPDATE always fails the unique rule (self-collision) — wrong route param key in ignore() | `app/Http/Requests/TaxRequest.php` |
| S7-03 | P1 | security | NF525 SEC | S7-settings-business | Site settings exposes an 'App Debug -> Enable' toggle that writes APP_DEBUG=true to .env — self-inflicted prod boot fail | `resources/js/components/admin/settings/Site/SiteComponent.vue` |
| S13-02 | P1 | nf525 | FROZEN-REF NF525 LIVE? | S13-marketing | Per-order persisted total_tax is computed on PRE-discount subtotal; only the Z-report re-nets TVA by discount ratio — or | `app/Services/OrderService.php` |
| M8-03 | P2 | ux | NF525 | M8-refund | Modal warning lies for the common case: claims 'commande miroir NF525 + ticket de remboursement' but pre-Z refunds creat | `resources/js/languages/fr.js` |
| M8-04 | P2 | nf525 | NF525 | M8-refund | REMBOURSEMENT receipt marker only appears for post-Z refunds — pre-Z refunds have no visual NF525 refund distinction | `resources/js/components/admin/pos/ReceiptRemboursementMarker.vue` |
| M10-02 | P2 | functional | NF525 | M10-counter-collect | Confirm button promises 'Confirmer & Imprimer ticket' but onConfirm never prints — no receipt is produced on this branch | `resources/js/components/admin/pos/PosCounterCollectModal.vue` |
| M10-05 | P2 | nf525 | NF525 | M10-counter-collect | Cancel of a never-paid counter order writes payment_status=REFUNDED — inflates refund reporting for orders where no mone | `app/Services/PaymentService.php` |
| M12-004 | P2 | nf525 | FROZEN-HARD NF525 | M12-tracker-zx | Z close has no open-cash-drawer gate - an unreconciled drawer at close pushes its cash_variance into the NEXT Z window | `app/Services/Fiscal/ZReportService.php` |
| S2-04 | P2 | visual | NF525 | S2-orders-mgmt | Online-orders list 'Montant' column renders raw '19.00' (US format, no € symbol) while POS list + Historique render cano | `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue` |
| S7-01 | P2(was P1) | nf525 | NF525 | S7-settings-business | Branch fiscal identity (SIRET / TVA intra / register_id / legal_footer) has NO settings UI and is never seeded — receipt | `resources/js/components/admin/settings/Branch/BranchCreateComponent.vue` |
| S8-02 | P2(was P1) | security | NF525 SEC | S8-settings-payments | Logout / Désactivation / Suppression d'une borne kiosk côté Admin ne révoque PAS le token Sanctum kiosk:order — la borne | `app/Services/KioskMachineService.php` |
| S12-02 | P2 | nf525 | NF525 | S12-delivery | COD doorstep cash collected with NO open driver session is never mirrored into any cash session — reconciliation-complet | `app/Services/OrderService.php` |
| GAP-items-pdf | P2 | logic | NF525 | SELF-AUDIT-ADD | Items report PDF export truncates to the current page (10 rows) — no paginate=0 normalization, same bug as the on-screen | `app/Http/Controllers/Admin/ItemsReportController.php` |
| M1-04 | P3 | logic | NF525 | M1-shell-auth-drawer | Close is a non-atomic 2-step close+reconcile from the client; a failure between the two leaves the session CLOSED-but-no | `resources/js/services/CashDrawerService.js` |
| M8-08 | P3 | ux | NF525 | M8-refund | UI gate is 5-char reason but backend validates min:3 — and the post-Z service trims-then-requires non-empty only; gate i | `PosRefundModal.vue` |
| M11-04 | P3(was P2) | security | NF525 SEC | M11-receipt-reprint | print-receipt route lacks the permission:pos / abort_unless(can('pos')) gate its 4 sibling POS routes carry | `routes/api.php` |
| M12-005 | P3 | ux | NF525 | M12-tracker-zx | No cashier UI to open/close a Z or pull an X report - only the cron safety-net and direct API can drive fiscal close | `resources/js/components/admin/dashboard/LastZReportWidget.vue` |
| S2-06 | P3 | ux | NF525 | S2-orders-mgmt | Transactions list date-range presets are hardcoded English ('Today', 'This month', 'This year (slot)') and the datepicke | `resources/js/components/admin/transactions/TransactionListComponent.vue` |
| S6-02 | P3 | logic | NF525 | S6-settings-catalog | Tax/ItemCategory destroy() has inverted FK-bypass branch — orphans rows when only inactive items reference it | `app/Services/TaxService.php` |
| S6-03 | P3 | nf525 | NF525 | S6-settings-catalog | tax_rate accepts up to 9 999 999 999 999 with no sane upper bound (no max:100) — a typo corrupts VAT/pricing/Z-report | `app/Http/Requests/TaxRequest.php` |
| S7-05 | P3 | functional | NF525 | S7-settings-business | TimeSlot has NO update path — opening hours can only be created or deleted, not edited | `app/Http/Controllers/Admin/TimeSlotController.php` |
| S12-03 | P3 | logic | NF525 | S12-delivery | destroy() soft-deletes a livreur without checking open cash session or active assigned orders | `app/Services/DeliveryBoyService.php` |

### W4-security — 7 findings

| id | sev | cat | tags | micro | title | root |
|---|---|---|---|---|---|---|
| S10-01 | P1 | security | SEC | S10-customers | CustomerService::show() missing target-role guard — read-side privilege escalation (any User id leaks PII) | `app/Services/CustomerService.php` |
| S11-03 | P2 | security | SEC | S11-staff-rbac | Password reset and image change gated only by the base VIEW permission (employees / administrators), not by *_edit — For | `app/Http/Controllers/Admin/EmployeeController.php` |
| S6-06 | P3 | security | SEC | S6-settings-catalog | LoyaltySetupRequest & ItemCategoryImportRequest authorize() return true (defense-in-depth gap) | `app/Http/Requests/LoyaltySetupRequest.php` |
| S8-08 | P3 | security | SEC | S8-settings-payments | Champs secrets de gateway (stripe_secret, *_client_secret) rendus en clair à l'écran (input type=text) dans la config Pa | `resources/js/components/admin/settings/PaymentGateway/PaymentGatewayComponent.vue` |
| S9-04 | P3 | security | SEC | S9-settings-system | LanguageService::fileTextStore allows arbitrary value injection into .php lang files (RCE-adjacent, admin-gated) | `app/Services/LanguageService.php` |
| S10-02 | P3 | security | SEC | S10-customers | Customer address write ops (store/update/destroy) gated by a READ permission (customers_show) | `app/Http/Controllers/Admin/CustomerAddressController.php` |
| S13-04 | P3(was P2) | security | SEC | S13-marketing | Push notification store() trusts request branch_id with no ownership check and no Rule::exists — branch user can broadca | `app/Services/PushNotificationService.php` |

### W5-logic — 74 findings

| id | sev | cat | tags | micro | title | root |
|---|---|---|---|---|---|---|
| M3-01 | P1 | logic | FROZEN-HARD live✓ | M3-item-wizard | Aucune validation des étapes obligatoires dans le flux actif (single-page) : on peut ajouter un Tacos 0 viande / 0 sauce | `public/js/pos-wizard.js` |
| M4-02 | P1 | functional |  | M4-cart-ticket | Apres rechargement (panier restaure du localStorage), la remise est restauree mais PAS son motif -> l'encaissement est r | `resources/js/store/modules/posCart.js` |
| M6-001 | P1 | logic | FROZEN-REF | M6-payment | Cash-dominant split payment is hard-rejected (422) by the single-tender cash guard even when perfectly balanced | `app/Services/OrderService.php` |
| M7-02 | P1 | logic |  | M7-parked | Recall silently drops unavailable variations AND unavailable item lines — the cashier is never told the restored cart di | `app/Services/PosParkedOrderService.php` |
| M8-01 | P1 | logic |  | M8-refund | One 'Rembourser' button, two divergent server paths with asymmetric side-effects (pre-Z vs post-Z) | `app/Http/Controllers/Admin/PosOrderController.php` |
| S1-DASH-01 | P1 | logic |  | S1-dashboard | Date-range filters silently broken: datepicker sends raw JS Date toString (GMT+0200 ...) which Carbon cannot parse -> ba | `resources/js/services/appService.js` |
| S8-01 | P1 | logic |  | S8-settings-payments | TPE créé via Settings prend branch_id=0 (admin) → invisible/refusé à la caisse de la branche → impossible d'encaisser pa | `resources/js/components/admin/settings/PaymentTerminals/PaymentTerminalsComponent.vue` |
| S17-01 | P1 | logic |  | S17-dinein-tables | Public QR table-order endpoint has NO pos_dine_in_enabled gate — dine-in is supposed to be OFF in V1 but anyone can stil | `app/Http/Requests/TableOrderRequest.php` |
| M1-05 | P2 | logic |  | M1-shell-auth-drawer | Cash-session auto-prompt and the 'Caisse OPEN' badge are scoped per-USER, so on a shared single-box terminal a second ca | `app/Services/Cash/CashDrawerService.php` |
| M2-01 | P2 | logic |  | M2-catalog-nav | Search is silently scoped to the active category — cashier typing a product name while a category pill is active gets 0  | `resources/js/components/admin/pos/PosComponent.vue` |
| M3-03 | P2 | logic | FROZEN-HARD | M3-item-wizard | Viande supplémentaire tarifée à un prix FORFAITAIRE (VIANDE_SUPPL_PRICE €2,50) quelle que soit la viande, et synchronisé | `public/js/pos-wizard.js` |
| M4-01 | P2(was P1) | logic |  | M4-cart-ticket | Changer une quantite REMET A ZERO silencieusement une remise deja appliquee (l'input reste rempli, le total perd la remi | `resources/js/store/modules/posCart.js` |
| M4-03 | P2 | logic |  | M4-cart-ticket | Invalidation de remise incoherente entre mutations panier : quantity/delete/prune remettent a 0, mais replaceCartLine/se | `resources/js/store/modules/posCart.js` |
| M6-003 | P2 | logic |  | M6-payment | autoBalanceTranches mis-balances with 3+ tranches — one absorber takes the whole remainder, ignoring the other tranches | `resources/js/helpers/posSplitPayment.js` |
| M6-004 | P2 | logic |  | M6-payment | Frontend canConfirm tolerates a 1-cent UNDERPAY that the backend rejects — UI says OK, server 422s | `resources/js/helpers/posSplitPayment.js` |
| M7-01 | P2 | logic |  | M7-parked | Recall consumes (deletes) the parked ticket on a GET, before the client cart is rebuilt — a dropped response loses the t | `app/Services/PosParkedOrderService.php` |
| M8-02 | P2 | logic |  | M8-refund | Earned-points clawback fix was applied only to the rare post-Z path — the common pre-Z refund re-opens the documented ca | `app/Listeners/ClawbackLoyaltyPointsOnRefund.php` |
| M8-05 | P2 | logic |  | M8-refund | Dead partial-refund plumbing: refundedItems is threaded through 3 layers but every dispatch passes [] and the modal has  | `app/Events/RefundCreated.php` |
| M8-06 | P2 | functional |  | M8-refund | Idempotency key is re-minted on every modal open; the only true double-refund backstop for the pre-Z path is a status-eq | `resources/js/components/admin/pos/PosRefundModal.vue` |
| M8-07 | P2 | logic |  | M8-refund | PaymentStateMachine forbids PAID->REFUNDED, so a refunded parent's payment_status is permanently misleading on the pre-Z | `app/Http/Controllers/Admin/PosOrderController.php` |
| M9-01 | P2 | logic |  | M9-loyalty | Modal hardcodes rate=100; never receives the admin-configured loyalty rate -> wrong preview, wrong step, false POINTS_NO | `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` |
| M9-02 | P2 | logic |  | M9-loyalty | DISCOUNT_EXCEEDS_SUBTOTAL only checks the incremental discount, not the cumulative one -> points burned with no benefit  | `app/Services/Loyalty/PosRedemptionService.php` |
| M10-04 | P2 | logic | FROZEN-REF LIVE? | M10-counter-collect | Ready-orders 'Livrer' shortcut can mark a still-unpaid (PENDING_COUNTER) kiosk order DELIVERED — food handed out before  | `resources/js/components/admin/pos/PosComponent.vue` |
| M12-001 | P2(was P1) | functional |  | M12-tracker-zx | Tracker time badges blank + sort inverted + name-search dead - SimpleOrderResource never ships created_at/updated_at/use | `app/Http/Resources/SimpleOrderResource.php` |
| M12-002 | P2 | logic |  | M12-tracker-zx | Tracker re-pulls the ENTIRE day every poll - paginate never sent so per_page:100 is silently ignored | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` |
| M12-003 | P2 | logic | FROZEN-HARD | M12-tracker-zx | 'Livre' button on tracker lets a DELIVERY order skip OUT_FOR_DELIVERY - state machine permits PREPARED->DELIVERED for an | `app/Domain/Order/OrderStateMachine.php` |
| S2-01 | P2 | logic |  | S2-orders-mgmt | POS order detail: payment-status dropdown offers an option (Impayé) that the backend ALWAYS rejects — every default POS  | `resources/js/components/admin/posOrders/PosOrderShowComponent.vue` |
| S2-02 | P2 | logic | FROZEN-REF | S2-orders-mgmt | Order-status dropdown (POS + Online detail) lists ALL forward statuses regardless of current state — illegal/backward tr | `resources/js/components/admin/posOrders/PosOrderShowComponent.vue` |
| S2-03 | P2 | logic |  | S2-orders-mgmt | Unified Historique status filter cannot find Annulé / Rejeté / Retourné orders — dropdown omits all terminal states whil | `resources/js/components/admin/orderHistory/HistoriqueListComponent.vue` |
| S3-L2 | P2 | logic |  | S3-items-catalog | Reordering steps on a PUBLISHED composer profile leaves editor version stale -> spurious 409 conflict on next save/publi | `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` |
| S4-01 | P2(was P1) | logic |  | S4-stock-ingredients | Stock dashboard and customer surfaces disagree about the same ingredient: an extra/variation 86'd via the Ingredients ad | `app/Http/Controllers/Admin/StockRuptureDashboardController.php` |
| S4-02 | P2 | logic |  | S4-stock-ingredients | Two parallel, divergent toggle systems for the SAME extras: dashboard toggle writes per-extra-id to stock_levels (no nam | `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` |
| S5-01 | P2 | logic |  | S5-reports-fiscal | Items report on-screen 'Total' footer sums only the current page (10 of 45 items), contradicting the Excel export's true | `resources/js/components/admin/itemsReport/ItemsReportListComponent.vue` |
| S5-04 | P2 | logic |  | S5-reports-fiscal | Cash session 'Total clôture' counts still-open sessions as 0, understating the day's closing total | `resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue` |
| S7-02 | P2 | logic |  | S7-settings-business | TimeSlot overlap guard misses full-containment and exact-duplicate slots — overlapping opening hours can be saved | `app/Services/TimeSlotService.php` |
| S7-04 | P2 | logic |  | S7-settings-business | Order Setup lets admin disable BOTH takeaway and delivery, leaving zero web fulfillment channels with no guard | `app/Http/Requests/OrderSetupRequest.php` |
| S8-04 | P2 | functional |  | S8-settings-payments | PaymentTerminalRequest::authorize() retourne true — la validation/écriture des TPE n'est gardée que par le middleware co | `app/Http/Requests/Admin/PaymentTerminalRequest.php` |
| S11-05 | P2 | logic |  | S11-staff-rbac | EmployeeService show/changePassword/changeImage guard via optional($employee->roles[0])->id — a role-less User bypasses  | `app/Services/EmployeeService.php` |
| S13-03 | P2 | functional |  | S13-marketing | Push notification broadcast fires immediately on submit with no confirmation dialog — irreversible mass send is one clic | `resources/js/components/admin/pushNotification/PushNotificationCreateComponent.vue` |
| S13-05 | P2 | functional |  | S13-marketing | Push send swallows every per-token FCM error silently — failed/invalid tokens never logged or pruned, admin sees success | `app/Services/FirebaseService.php` |
| S13-07 | P2 | logic |  | S13-marketing | Coupon limit_per_user and max_uses_global enforced via non-atomic count-then-create (TOCTOU) — concurrent redemptions ca | `app/Services/CouponService.php` |
| S14-KDS-01 | P2 | logic |  | S14-kds | KDS sous compte admin (branch_id=0) n'a AUCUN push temps-reel : nouvelle commande visible jusqu'a 60s plus tard, sans au | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` |
| S15-oss-02 | P2 | logic |  | S15-oss | First load fires a flash+chime storm: every already-PREPARED order is treated as newly-ready on mount | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` |
| S17-02 | P2 | logic |  | S17-dinein-tables | Admin dining-tables & table-orders pages are hidden from the sidebar but fully reachable by direct URL with no dine-in f | `resources/js/config/v1-hidden-modules.js` |
| M1-03 | P3 | logic |  | M1-shell-auth-drawer | Close dialog forces a variance reason for ANY non-zero ecart (>0.005EUR) while backend only requires it above the 2.00EU | `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` |
| M1-08 | P3 | logic |  | M1-shell-auth-drawer | onCashSessionOpened handler is an empty no-op despite documenting that it should switch to the 'active' view | `resources/js/components/admin/pos/PosComponent.vue` |
| M5-03 | P3 | logic |  | M5-customer-ordertype | Livraison crée un NOUVEAU client jetable (email delivery_<timestamp>@pos.local) à chaque commande — aucun re-usage du cl | `resources/js/components/admin/pos/PosComponent.vue` |
| M5-07 | P3 | perf |  | M5-customer-ordertype | diningTable/lists est toujours chargé au montage même quand dine-in est désactivé (V1) | `resources/js/components/admin/pos/PosComponent.vue` |
| M6-006 | P3 | functional | FROZEN-REF | M6-payment | Cash input accepts multiple decimal points / numpad '.' and '00' bypass the keypress filter — change display can be sile | `resources/js/services/appService.js` |
| S1-DASH-04 | P3(was P1) | logic |  | S1-dashboard | ChannelStats card Repartition par Canal (Aujourdhui) buckets do not partition the order set -> the three percentages can | `app/Services/DashboardService.php` |
| S1-DASH-06 | P3 | perf |  | S1-dashboard | customerStates fires 18 separate queries per load and hydrates full Order models just to count (->get()->count()) | `app/Services/DashboardService.php` |
| S2-07 | P3 | logic |  | S2-orders-mgmt | Online-orders list clear() resets filters to a state that differs from the initial mount filters (excepts and exceptSour | `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue` |
| S3-L1 | P3 | logic |  | S3-items-catalog | Item update with only NEW variations orphans the old ones (variations diff never runs when no id present) | `app/Services/ItemService.php` |
| S3-L3 | P3 | logic |  | S3-items-catalog | ComposerProfileProjection emits inactive steps (no is_active filter) — defense-in-depth only; every live caller pre-filt | `app/Services/Composer/ComposerProfileProjection.php` |
| S3-F1 | P3 | logic |  | S3-items-catalog | Catalog Studio quick-create hardcodes item_type = VEG for every product (chicken/beef saved as vegetarian) | `resources/js/components/admin/items/CatalogStudioComponent.vue` |
| S3-X1 | P3 | logic |  | S3-items-catalog | Item update only patches variation name+price for existing rows — item_attribute_id / visible_on silently ignored | `app/Services/ItemService.php` |
| S4-05 | P3 | logic |  | S4-stock-ingredients | Dashboard extra/variation toggle is non-atomic: a partial fan-out failure rolls back the UI flip but leaves some extra_i | `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` |
| S4-06 | P3 | logic |  | S4-stock-ingredients | Dashboard sends reason 'out_of_stock_manual' but stock_levels.unavailable_reason column is 64-char and downstream reason | `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` |
| S5-03 | P3 | logic |  | S5-reports-fiscal | Cash session report daily totals (opening/closing/transactions) are computed per-page, so a day split across pages shows | `resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue` |
| S6-04 | P3 | logic |  | S6-settings-catalog | Category drag-sort assigns sort=index+1 from the visible page only — collides across paginated pages | `resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue` |
| S6-05 | P3 | logic |  | S6-settings-catalog | Category Excel import bypasses the CategoryCreated event fan-out — imported categories may not sync to POS/kiosk | `app/Imports/ItemCategoryImport.php` |
| S8-05 | P3 | functional |  | S8-settings-payments | KioskMachine changeStatus passe par Request brut (pas KioskMachineRequest) — status non borné/non typé peut être écrit | `app/Http/Controllers/Admin/KioskMachineController.php` |
| S9-03 | P3 | logic |  | S9-settings-system | LanguageController::fileText guards with `$explodeName > 0` (array compared to int) — always-true, dead/incorrect branch | `app/Http/Controllers/Admin/LanguageController.php` |
| S10-04 | P3 | perf |  | S10-customers | Subscriber broadcast email sent synchronously to all subscribers in one BCC, no queue/chunk | `app/Services/SubscriberService.php` |
| S11-01 | P3(was P1) | logic |  | S11-staff-rbac | AdministratorService::update() missing target/self/super-admin guards present on every sibling method — id=1 super-admin | `app/Services/AdministratorService.php` |
| S11-04 | P3 | logic | LIVE? | S11-staff-rbac | Employee role dropdown filters by hardcoded integer role IDs '1/2/3/4/5' while the backend was deliberately healed OFF i | `resources/js/components/admin/employees/EmployeeCreateComponent.vue` |
| S11-07 | P3 | functional | LIVE? | S11-staff-rbac | EmployeeCreateComponent.save catch reads err.response.data.errors unconditionally — 422 responses that return {status,me | `resources/js/components/admin/employees/EmployeeCreateComponent.vue` |
| S12-01 | P3 | logic |  | S12-delivery | Tautological role guard !in_array(DELIVERY_BOY, blockRoles) is always true — dead else-branches across 5 methods | `app/Services/DeliveryBoyService.php` |
| S12-05 | P3 | logic |  | S12-delivery | selectDeliveryBoy has no order-status gate and no target-status check — a DELIVERED order can be reassigned to another d | `app/Services/OrderService.php` |
| S13-06 | P3(was P2) | logic |  | S13-marketing | Coupon::isUsableNow() checks max_uses_global against the dead usage_count column (always 0) — global cap unenforced for  | `app/Models/Coupon.php` |
| S15-oss-01 | P3(was P2) | functional |  | S15-oss | POS comptoir orders never reach the PRET wall though they carry a queue number and are prepared in the kitchen | `app/Services/OrderStatusScreenOrderService.php` |
| S15-oss-03 | P3 | logic |  | S15-oss | Stale-prune window (now-8h) silently drops still-active overdue advance orders, contradicting the documented anti-zombie | `app/Services/OrderStatusScreenOrderService.php` |
| SLV-04 | P3 | logic | LIVE? | LIVE-visual | KDS shows 49 active orders vs OSS shows 1 'En preparation' — verify the filter difference is intentional (live vs stale  | `KDS` |
| S8-03-reinstated | P3 | functional |  | SELF-AUDIT-ADD | Kiosk-machine edit prefills a password from a nonexistent field (re-instated wrongly-dropped) | `resources/js/components/admin/settings/KioskMachine/KioskMachineListComponent.vue` |

### W6-ux-visual — 31 findings

| id | sev | cat | tags | micro | title | root |
|---|---|---|---|---|---|---|
| M4-04 | P2 | visual |  | M4-cart-ticket | Deux formats de monnaie FR incoherents sur le MEME ecran POS : '12.50EUR' (point, sans espace) pour le panier vs '12,50  | `resources/js/services/appService.js` |
| M7-04 | P2 | ux |  | M7-parked | Discard deletes a parked ticket on a single tap with no confirmation, sitting right next to Restore | `resources/js/components/admin/pos/ParkedOrdersComponent.vue` |
| LV-01 | P2 | visual | live✓ | LIVE-visual | Two distinct sandwich categories both render as truncated 'Sandwich…' | `resources/js/components/admin/pos/PosComponent.vue` |
| LV-03 | P2 | visual | FROZEN-HARD live✓ | LIVE-visual | Currency format inconsistent WITHIN the item wizard (non-FR) | `public/js/pos-wizard.js` |
| S1-DASH-02 | P2 | ux |  | S1-dashboard | Two cards both labelled Total des ventes show different scopes (all-time net vs selected-period net) with no scope hint | `OverviewComponent.vue` |
| S1-DASH-05 | P2 | ux |  | S1-dashboard | All four date-filtered widgets swallow API errors (.catch only flips loading=false) -> no empty/error state, failures in | `SalesSummaryComponent.vue` |
| S5-02 | P2 | ux |  | S5-reports-fiscal | Sales report mixes a placed-orders count card next to three realized-net money cards with no label distinguishing the tw | `app/Services/OrderService.php` |
| S5-05 | P2 | ux |  | S5-reports-fiscal | Outbox 'Retry failed' button is enabled by pending count, but the backend only retries rows that already errored — healt | `resources/js/components/admin/observability/OutboxOverviewComponent.vue` |
| S5-06 | P2(was P1) | ux |  | S5-reports-fiscal | Sales report Excel/PDF export the full filtered set with no row cap, while the screen paginates — large exports can be h | `app/Services/OrderService.php` |
| M1-07 | P3 | ux |  | M1-shell-auth-drawer | Manual 'Synchroniser' flush gives no feedback when nothing actually synced (0 synced / 0 failed produces silence) | `resources/js/components/admin/pos/PosComponent.vue` |
| M2-06 | P3 | ux |  | M2-catalog-nav | Product tile image has no @error fallback — a 404/broken thumb shows a broken-image glyph, not the 🍴 fallback (which on | `resources/js/components/admin/pos/ItemComponent.vue` |
| M3-05 | P3 | visual | FROZEN-HARD | M3-item-wizard | Format monétaire non-FR dans tout l'aperçu wizard : « €3.00 » au lieu de « 3,00 € » (symbole avant + point décimal) | `public/js/pos-wizard.js` |
| M4-05 | P3 | ux |  | M4-cart-ticket | Bouton Appliquer actif avec input remise vide -> stocke la chaine '0.00' que v-if=posDiscount traite comme truthy -> lig | `resources/js/components/admin/pos/PosComponent.vue` |
| M7-03 | P3 | ux |  | M7-parked | Park label captured via blocking window.prompt() — unusable on a keyboard-less touchscreen POS | `resources/js/components/admin/pos/PosComponent.vue` |
| M9-03 | P3 | ux |  | M9-loyalty | Main-page POS loyalty CTA only lights up for the minority non-PAID flows; for the default CASH-via-wizard flow it is per | `resources/js/components/admin/pos/PosComponent.vue` |
| M9-05 | P3 | ux |  | M9-loyalty | Modal reset on open is incomplete: loyaltyCode / pointsToRedeem / customerBalance persist across opens for a different o | `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` |
| LV-02 | P3 | visual | live✓ | LIVE-visual | Tacos category icon = forbidden/placeholder image; Tacos product tiles grey (missing photos) | `category` |
| LV-04 | P3 | ux | FROZEN-HARD live✓ | LIVE-visual | Sauce step 'Premiere gratuite' but additional-sauce surcharge not shown on pills | `public/js/pos-wizard.js` |
| LV-05 | P3 | ux | FROZEN-HARD live✓ | LIVE-visual | Viande '0/1' requirement badge + per-viande quantity steppers is ambiguous | `public/js/pos-wizard.js` |
| LV-06 | P3 | ux | live✓ | LIVE-visual | Landing prioritises 'A encaisser borne (200)' list; product grid is a thin bottom strip | `resources/js/components/admin/pos/PosComponent.vue` |
| S2-05 | P3 | visual |  | S2-orders-mgmt | Online-orders list 'Avance' badge is styled with the order-status colour helper fed a boolean enum, so it renders as a R | `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue` |
| S5-07 | P3 | ux |  | S5-reports-fiscal | Credit balance report offers Print + Excel but no PDF, inconsistent with sales/items reports (which expose PDF) — and th | `resources/js/components/admin/creditBalanceReport/CreditBalanceReportComponent.vue` |
| S8-06 | P3 | ux |  | S8-settings-payments | Liste des bornes kiosk sans état vide (pas de v-else / no_data) — table fantôme si aucune borne | `resources/js/components/admin/settings/KioskMachine/KioskMachineListComponent.vue` |
| S12-07 | P3 | ux |  | S12-delivery | Delivery cash-session list route requires full 'delivery-boys' mutation permission; backend index only needs read permis | `resources/js/router/modules/deliveryBoyCashSessionRoutes.js` |
| S13-08 | P3 | ux |  | S13-marketing | Coupon active/inactive toggle has no confirmation — one click silently activates/deactivates a live promo | `resources/js/components/admin/coupons/CouponListComponent.vue` |
| S14-KDS-02 | P3 | ux |  | S14-kds | Conflit 409 sur le board V2 (defaut prod) affiche une cle i18n inexistante au lieu du message d'avertissement | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` |
| S14-KDS-04 | P3 | ux |  | S14-kds | Board V2 limite a 8 cartes (slice 0,8) + raccourcis clavier A-H seulement : commandes 9+ visibles uniquement via une puc | `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` |
| S16-05 | P3 | visual |  | S16-kiosk-borne | Kiosk login/diagnostic screen uses a dark navy gradient, breaking the V1 kiosk light-mode mandate | `resources/js/components/frontend/kiosk/KioskLoginComponent.vue` |
| SLV-01 | P3 | ux | live✓ | LIVE-visual | OSS (customer-facing 'Suivi client') shows admin header chrome until fullscreen | `resources/js/components/admin/orderStatusScreen/*` |
| SLV-02 | P3 | visual | live✓ | LIVE-visual | KDS order timer shows raw minutes for old orders (e.g. '926:5x') with no hour formatting | `resources/js/components/admin/kitchenDisplaySystem/*` |
| SLV-03 | P3 | ux | live✓ | LIVE-visual | KDS caps the visible list (49 shown, '+41 en attente / Liste pleine') and asks the chef to filter | `resources/js/components/admin/kitchenDisplaySystem/*` |

### W7-deadcode — 10 findings

| id | sev | cat | tags | micro | title | root |
|---|---|---|---|---|---|---|
| M4-06 | P3 | deadcode |  | M4-cart-ticket | Methodes panier mortes : cartQuantityUp(id,e) et deleteCartItem(id) ne sont referencees nulle part dans le template (le  | `resources/js/components/admin/pos/PosComponent.vue` |
| M5-02 | P3 | deadcode |  | M5-customer-ordertype | Cluster de code livraison-adresse legacy orphelin coexiste avec le nouveau formulaire inline (modale adresse + selecteur | `resources/js/components/admin/pos/PosComponent.vue` |
| M5-05 | P3 | deadcode |  | M5-customer-ordertype | Les méthodes de switch order-type ajoutent/retirent une classe 'active' inexistante en CSS (le surlignage réel vient de  | `resources/js/components/admin/pos/PosComponent.vue` |
| M9-04 | P3 | deadcode |  | M9-loyalty | Success band is dead code: parent closes the modal on 'applied' before the success message can render | `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` |
| S3-D1 | P3 | deadcode |  | S3-items-catalog | Dead env('DEMO') branch in ItemController::store — both arms identical | `app/Http/Controllers/Admin/ItemController.php` |
| S4-03 | P3 | deadcode |  | S4-stock-ingredients | Entire daily-quota auto-86 subsystem is unreachable in V1: max_daily_qty is never set by any UI, seeder, or factory, so  | `app/Http/Controllers/Admin/AvailabilityController.php` |
| S4-04 | P3 | deadcode |  | S4-stock-ingredients | Rupture-scan 'run' endpoint, last-summary endpoint and the whole preventive cron have no UI trigger and the cron ships d | `app/Http/Controllers/Admin/StockRuptureDashboardController.php` |
| S7-06 | P3 | deadcode |  | S7-settings-business | SiteController::update has an identical if/else DEMO branch — both arms run the same code | `app/Http/Controllers/Admin/SiteController.php` |
| S15-oss-04 | P3 | deadcode |  | S15-oss | Entire popular-items chain is dead on the OSS surface and its store getter reads an uninitialized state key | `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue` |
| S15-oss-05 | P3 | deadcode |  | S15-oss | wsConnected reactive state is maintained but never read by the template or any computed | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` |

## Per-finding remediation + test detail (every finding, all waves)

### W2-operator-receipt

#### M11-01 [P1] Receipt 'Operateur' prints the CUSTOMER (Client passage), not the cashier — heal commit 6b26e1be3 is NOT in this branch
- **report/micro** : BOX / M11-receipt-reprint · **category** : logic · **tags** : NF525, live-verified
- **file:line** : `app/Services/Receipt/ReceiptDataService.php:70`
- **correction** : In ReceiptDataService::buildForOrderModel resolve the operator from creator_id first, falling back to user only when creator_id is null (legacy/pre-encashment self-service). E.g. read $order->creator_id and load the User by id (interface-safe for both Order + FrontendOrder on the orders table), since no creator() relation exists yet. This is exactly what commit 6b26e1be3 did on its branch — cherry-pick / re-apply it here. NOT a frozen file. Add a test case where user_id != creator_id (see M11-05).
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M11-02 [P1] Legal ticket silently omits SIRET / TVA intra / N° caisse / legal mentions — branch 1 fiscal identity columns are all NULL
- **report/micro** : BOX / M11-receipt-reprint · **category** : nf525 · **tags** : NF525, live-verified
- **file:line** : `resources/js/components/admin/pos/ReceiptComponent.vue:67-73`
- **correction** : Owner data-ops: populate branch 1 with the real Le Cayenne SIRET, TVA intra, register_id, and legal_footer (admin branch settings UI or a seeder). No code change needed — the rendering path is correct once data exists. Optionally add a production boot/health check warning when branch fiscal identity is empty so this never ships silently.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.
- **self-audit note** : Self-audit reword: root cause is that branch fiscal-identity columns (SIRET / TVA intra / register / legal_footer) are never populated (no seeder, no admin UI); the Vue receipt template is the render-omission symptom, not the cause.

#### M11-03 [P2] 'Print anyway' error path emits a physical fiscal ticket with NO server counter increment and NO audit_logs entry
- **report/micro** : BOX / M11-receipt-reprint · **category** : logic · **tags** : NF525
- **file:line** : `resources/js/components/admin/pos/ReceiptComponent.vue:567-589`
- **correction** : Keep print-anyway (continuity) but reconcile the counter: on failure do NOT optimistically bump localPrintCount as if the server recorded it, OR queue a retry of the increment+audit (offline outbox pattern) so the fiscal counter and audit chain eventually catch up. At minimum, log a structured client-side fiscal-gap event. Behavior change — confirm with owner before altering the continuity fallback.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M11-05 [P2] ReceiptDataServiceWireInTest gives FALSE GREEN on operator_name — it never models the production user_id!=creator_id case
- **report/micro** : BOX / M11-receipt-reprint · **category** : deadcode · **tags** : NF525
- **file:line** : `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php:59-66,91`
- **correction** : Add a test case to ReceiptDataServiceWireInTest where user_id points to a 'Client passage' customer and creator_id points to a distinct cashier user, asserting operator_name resolves to the CASHIER. Land it together with the M11-01 fix so the guard actually guards the NF525 operator scenario.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### M11-06 [P3] Duplicata marker counts EVERY print incl. reprints from the tracker, but only fresh-payment print increments cart-reset — semantics differ across entry points (no bug, but a blur worth noting)
- **report/micro** : BOX / M11-receipt-reprint · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/ReceiptComponent.vue:444-454`
- **correction** : Document the duplicata counter as 'intent-based, best-effort' or tie the increment strictly to a confirmed server-side success (resolve M11-03 first). No urgent change for V1 single-box.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S11-02 [P1] POS printed receipt 'Opérateur' shows the CUSTOMER name, not the cashier — operator identity wrong on the fiscal ticket while creator_id (cashier) is captured but unused
- **report/micro** : SYSTEMS / S11-staff-rbac · **category** : nf525 · **tags** : NF525
- **file:line** : `app/Services/Receipt/ReceiptDataService.php:70`
- **correction** : Add a `creator()` belongsTo(User::class,'creator_id') relation on Order, then in ReceiptDataService:70 use `optional($order->creator)->name ?? optional($order->user)->name` (fallback to user only for kiosk/online orders that have no cashier). Eager-load creator in OrderDetailsResource's hydration to avoid an extra SELECT. Note ReceiptDataService is NF525-adjacent — change is a one-line SSOT fix, no fiscal-chain logic touched, but keep it covered by ReceiptDataServiceWireInTest.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

#### S16-01 [P1] Kiosk-counter-collected order never records the collecting cashier on the order — receipt 'Opérateur' stays the kiosk machine (live Plan B path)
- **report/micro** : SYSTEMS / S16-kiosk-borne · **category** : nf525 · **tags** : NF525
- **file:line** : `app/Services/PaymentService.php:389-401 (confirmCounterPayment) + app/Services/Receipt/ReceiptDataService.php:70 + app/Services/FrontendOrderService.php:260-272`
- **correction** : At collection time in confirmCounterPayment, persist the collecting cashier onto the order (e.g. set editor_id = Auth::id() on $locked before save, line ~362) AND change ReceiptDataService:70 to resolve operator from editor_id/creator_id (the cashier) with a fallback, not from $order->user (the customer/machine). Coordinate with the receipt/POS owners; ReceiptDataService is fiscal-adjacent so gate the change.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

### W3-money-fiscal

#### M1-01 [P1] No-sale 'Ouvrir tiroir' button never reaches the backend NF525 audit route — i18n promises 'Action tracée' but nothing is traced
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : logic · **tags** : NF525
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3669-3686 (triggerNoSaleOpenDrawer) + resources/js/services/kioskHardware.js:259-263 (openDrawer) + resources/js/languages/fr.json:202`
- **correction** : Wire triggerNoSaleOpenDrawer to POST /admin/pos/cash-drawer/open (with the selected printer_id) so the backend forensic movement fires regardless of Electron presence, then let the bridge physically pop the drawer as a secondary step. The backend already handles the no-open-session case (warning) and is idempotency-protected. This is a frontend wiring change only — backend CashDrawerController is untouched (not frozen).
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M1-02 [P1] Offline degraded-mode queues a CASH order with pos_received_amount=null — every replay 422s and the cash sale is silently lost
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : logic · **tags** : NF525
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3894-3908 (orderSubmit offline branch) + resources/js/composables/usePosOfflineState.js:39-63 (tryFlush) + app/Http/Requests/PosOrderRequest.php:116-118`
- **correction** : Either (a) do not advertise offline capture for CASH until the payment step is captured offline too, or (b) before enqueuing, run the cart through a minimal payment-capture (ask tendered amount) so the queued payload carries pos_payment_method + pos_received_amount + a stringified items JSON + quote fields the server requires. Minimum: stamp pos_received_amount = grand total for CASH and surface a clear 'offline = exact cash only' UX. Backend request rules are the SSOT and should not be loosened.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M3-02 [P1] Suppléments frites (Grande Portion +1,00 / Cheddar +1,00) comptés dans l'aperçu mais envoyés seulement en TEXTE — non re-tarifés par le quote serveur → sous-facturation vs aperçu
- **report/micro** : BOX / M3-item-wizard · **category** : logic · **tags** : FROZEN-HARD (public/js/pos-wizard.js), NF525
- **file:line** : `public/js/pos-wizard.js:1324-1326 (calculateRunningTotal ajoute FRITES_GRANDE_PRICE/FRITES_CHEDDAR_PRICE) ; 4084-4159 (buildMenuExtras/addonToPayload : extras frites poussés uniquement dans instruction/menu_extras, convert_price=base addon) ; quote serveur PosController.php:196 OrderQuoteService::quote`
- **correction** : FROZEN : décrire seulement. Soit (a) créer de vrais addon-items DB tarifés 'Grande Portion' / 'Cheddar' et les envoyer comme lignes pos_line_addons tarifées (le quote les reprendra), soit (b) retirer ces upcharges de l'aperçu tant qu'ils ne sont pas des options DB, pour que l'aperçu = montant facturé. Gate owner + alignement OrderQuoteService.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M6-002 [P1] Z-report 'total_by_method' buckets a split order's FULL total under the dominant tender — card/cash mix is mis-attributed in the signed daily close
- **report/micro** : BOX / M6-payment · **category** : nf525 · **tags** : FROZEN-HARD (app/Services/Fiscal/ZReportService.php), NF525
- **file:line** : `app/Services/Fiscal/ZReportService.php:661-668 (applyOrderToTotals) reading order->pos_payment_method, which is set to the dominant tranche by PaymentComponent.vue:837`
- **correction** : ZReportService is a FROZEN NF525 file — DO NOT change behavior without an owner gate / LOCK doc + triple-green regression. Recommended scope-gated fix: in applyOrderToTotals, when the order has order_payments rows, distribute $ttc across $byMethod by each tranche's amount (read order_payments.mode/amount) instead of the single dominant method; fall back to pos_payment_method when no breakdown exists. Add a regression test asserting Z total_by_method == per-tranche sums for a mixed split before touching the signed payload.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.
- **self-audit note** : Self-audit example correction: the Z mis-bucketing is reachable only via a CARD-(non-cash-)dominant split (e.g. card 20 + cash 10 -> pos_payment_method=CARD -> full 30 buckets under CARD, the cash leg mis-attributed). The cash-dominant variant is hard-rejected upstream by M6-001's single-tender guard and never reaches the Z. NF525 P1 stands.

#### M10-01 [P1] CASH collected with no open drawer session: order goes PAID but NO queryable cash-trail row — skip surfaced only as a transient, vanishing toast
- **report/micro** : BOX / M10-counter-collect · **category** : nf525 · **tags** : NF525
- **file:line** : `app/Services/PaymentService.php:442-444,484-521,573-576 ; resources/js/components/admin/pos/PosCounterCollectModal.vue:465-483`
- **correction** : Persist the skip so reconciliation can find it: either write an audit_log action 'order.cash_movement_skipped' (resource=order, payload {order_id,total}) inside flagCashMovementSkipped, or record a marked cash_movement (type ORDER_PAYMENT, flagged unreconciled) against a 'no-session' bucket. PaymentService is payment/NF525-adjacent (CLAUDE.md §7) — the behaviour change (new persisted write) needs an owner gate, but the finding stands: today the broken cash-trail is knowable only at confirm-time and is thrown away.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.
- **self-audit note** : Self-audit (reword + upgrade): a Transaction + audit_log row DO exist, but there is NO CashMovement / cash-drawer-session linkage — flagCashMovementSkipped sets only a transient, never-persisted property. Restored P2->P1 as an NF525 cash-trail/drawer-linkage breach (cash physically received, order PAID, no queryable drawer movement).

#### M8-03 [P2] Modal warning lies for the common case: claims 'commande miroir NF525 + ticket de remboursement' but pre-Z refunds create NEITHER
- **report/micro** : BOX / M8-refund · **category** : ux · **tags** : NF525
- **file:line** : `resources/js/languages/fr.json:319 (pos.refund.warning) rendered by PosRefundModal.vue:108; pre-Z path PosOrderController.php:215-248 creates no mirror`
- **correction** : The modal does not know the path in advance (sealed? is server-side SSOT). Either (a) soften the warning to be path-agnostic ('Cette action rembourse la commande et est irreversible. Une ecriture NF525 conforme est generee.'), or (b) have the controller return the chosen mode and show a post-confirm toast describing what actually happened (mirror created vs commande passee en Retourne). Frontend-only text change is low-risk.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M8-04 [P2] REMBOURSEMENT receipt marker only appears for post-Z refunds — pre-Z refunds have no visual NF525 refund distinction
- **report/micro** : BOX / M8-refund · **category** : nf525 · **tags** : NF525
- **file:line** : `resources/js/components/admin/pos/ReceiptRemboursementMarker.vue:43-49 (isRefund := parent_order_id>0)`
- **correction** : Extend isRefund to also treat status===RETURNED (with a reason) as a refund for marker purposes, OR render the marker when payment carries a cash_back transaction. Keep parent_order_id>0 as the primary trigger; add status===RETURNED as a secondary. Frontend-only, low risk.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

#### M10-02 [P2] Confirm button promises 'Confirmer & Imprimer ticket' but onConfirm never prints — no receipt is produced on this branch
- **report/micro** : BOX / M10-counter-collect · **category** : functional · **tags** : NF525
- **file:line** : `resources/js/components/admin/pos/PosCounterCollectModal.vue:177,421-534 ; resources/js/languages/fr.json:1337 ; app/Providers/EventServiceProvider.php:176-178`
- **correction** : Either (a) make the label honest on this branch — use a 'Confirmer l'encaissement' key without the print promise until the printer-saga branch merges, or (b) wire the post-confirm receipt (mount ReceiptComponent / dispatch the print) so the label is true. Pure-frontend label swap is scope-minimal and non-frozen; wiring the print is the better fix but should land with the printer-saga merge.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### M10-05 [P2] Cancel of a never-paid counter order writes payment_status=REFUNDED — inflates refund reporting for orders where no money was ever collected
- **report/micro** : BOX / M10-counter-collect · **category** : nf525 · **tags** : NF525
- **file:line** : `app/Services/PaymentService.php:621-690 (esp. 652) ; app/Enums/PaymentStatus.php:7-10`
- **correction** : Introduce a distinct negative terminal (e.g. CANCELED/VOID) in PaymentStatus and assert it on the cancel-of-unpaid path, OR keep payment_status=PENDING_COUNTER/UNPAID and rely on status=CANCELED for the terminal, reserving REFUNDED strictly for orders that were PAID then reversed. Enum + state-machine change is payment-critical — owner gate required; documenting the modeling gap is the immediate deliverable.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

#### M12-004 [P2] Z close has no open-cash-drawer gate - an unreconciled drawer at close pushes its cash_variance into the NEXT Z window
- **report/micro** : BOX / M12-tracker-zx · **category** : nf525 · **tags** : FROZEN-HARD (app/Services/Fiscal/ZReportService.php), NF525
- **file:line** : `app/Services/Fiscal/ZReportService.php:180-286 (close, no drawer check) + app/Services/Fiscal/ZReportCashEnrichmentService.php:53-91 (RECONCILED-only window)`
- **correction** : FROZEN file (ZReportService) - owner-gated. Before close, query open/non-reconciled CashDrawerSession for the branch and either (a) block close with an operator warning (reconcile drawer #N first), or (b) at minimum emit a fiscal-log warning (mirroring warnOnOrphanedPaidOrders) so the omission is observable. Do NOT add cash fields to the signed payload. Any behavior change requires a LOCK doc + owner sign-off per CLAUDE.md section 7.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

#### M1-04 [P3] Close is a non-atomic 2-step close+reconcile from the client; a failure between the two leaves the session CLOSED-but-not-RECONCILED with no UI path to finish it
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : logic · **tags** : NF525
- **file:line** : `resources/js/services/CashDrawerService.js:closeSession (POST .../close then POST .../reconcile) + resources/js/store/modules/cashDrawer.js:121-149`
- **correction** : Prefer a single backend endpoint that closes+reconciles in one transaction (the service methods already exist; add a wrapper controller action), OR have the store detect a CLOSED-not-RECONCILED session on reload and offer a 'Terminer la reconciliation' action that calls the existing CashDrawerService.reconcile(sessionId). Make closeSession resilient to the already-closed idempotent case.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M8-08 [P3] UI gate is 5-char reason but backend validates min:3 — and the post-Z service trims-then-requires non-empty only; gate is inconsistent across layers
- **report/micro** : BOX / M8-refund · **category** : ux · **tags** : NF525
- **file:line** : `PosRefundModal.vue:255-265 (>=5) vs PosOrderController.php:64-66 (min:3) vs RefundWithCounterEntryService.php:93-96 (non-empty after trim)`
- **correction** : Pick one floor (5 is the stated intent) and enforce it server-side: change PosOrderController validation to 'min:5' so both branches inherit it. Trivial, low-risk, removes the cross-layer inconsistency.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M11-04 [P3] print-receipt route lacks the permission:pos / abort_unless(can('pos')) gate its 4 sibling POS routes carry
- **report/micro** : BOX / M11-receipt-reprint · **category** : security · **tags** : NF525, SECURITY
- **file:line** : `routes/api.php:911`
- **correction** : Add the same gate as siblings: either `->middleware('permission:pos')` on the route, or `abort_unless($request->user()?->can('pos'), 403)` at the top of PosReceiptPrintController::increment. The Vue already handles a 403 gracefully (receipt_print_forbidden toast), so the UX is already wired for it.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.
- **self-audit note** : Self-audit re-rating P2->P3: exploitation requires an already-authenticated admin-area session; it is a gate-consistency gap vs its 4 sibling POS routes (missing permission:pos), not an unauthenticated hole.

#### M12-005 [P3] No cashier UI to open/close a Z or pull an X report - only the cron safety-net and direct API can drive fiscal close
- **report/micro** : BOX / M12-tracker-zx · **category** : ux · **tags** : NF525
- **file:line** : `resources/js/components/admin/dashboard/LastZReportWidget.vue:1-113 (display only) + routes/api.php:1230-1240 + app/Console/Commands/FiscalCloseAllActiveBranchesCommand.php`
- **correction** : Owner decision: if manual/X access is wanted, add a read-only X-snapshot view (GET admin/fiscal/x-report, already built) and an optional permission-gated Cloturer-Z button wired to POST z-report/close. If automated-only close is the intended V1 envelope, label the widget (Cloture automatique en fin de journee) so the absence is explained, not mistaken for a missing feature.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S6-01 [P1] Tax & Currency UPDATE always fails the unique rule (self-collision) — wrong route param key in ignore()
- **report/micro** : SYSTEMS / S6-settings-catalog · **category** : functional · **tags** : NF525
- **file:line** : `app/Http/Requests/TaxRequest.php:40 ; app/Http/Requests/CurrencyRequest.php:35`
- **correction** : Use the model-bound param: `->ignore($this->route('tax'))` (and `$this->route('currency')`) — Laravel resolves the bound Model and ignores by its key. (Other requests in repo that use `route('xxx.id')` likely share this bug where the route param is `{xxx}`.)
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S7-03 [P1] Site settings exposes an 'App Debug -> Enable' toggle that writes APP_DEBUG=true to .env — self-inflicted prod boot failure / debug leak
- **report/micro** : SYSTEMS / S7-settings-business · **category** : security · **tags** : NF525, SECURITY
- **file:line** : `resources/js/components/admin/settings/Site/SiteComponent.vue:331-354 (toggle) -> app/Services/SiteService.php:48 (.env write)`
- **correction** : Remove the App Debug radio from SiteComponent.vue and stop writing APP_DEBUG from SiteService::update (drop the APP_DEBUG key from the addData array, line 48). Debug state is an ops/deploy concern, never a runtime admin toggle. No frozen-zone touch.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.
- **self-audit note** : Self-audit: keep P1 but frame as an OPERATIONAL footgun (not an external security breach): the admin 'App Debug -> Enable' toggle writes APP_DEBUG=true to .env; in production the AppServiceProvider boot guard then REFUSES to boot (self-inflicted outage) and in any env it leaks stack/SQL. Especially dangerous during the in-progress cloud cutover.

#### S13-02 [P1] Per-order persisted total_tax is computed on PRE-discount subtotal; only the Z-report re-nets TVA by discount ratio — order/receipt TVA disagrees with Z (residual F1 surface)
- **report/micro** : SYSTEMS / S13-marketing · **category** : nf525 · **tags** : FROZEN-REFERENCED (fix non-frozen side; §7 ref: app/Services/Fiscal/ZReportService.php), NF525, NEEDS-LIVE-VERIFY
- **file:line** : `app/Services/OrderService.php:551-563 ; app/Services/Fiscal/ZReportService.php:672-685`
- **correction** : Net the per-order total_tax by the same discount ratio at order creation (or recompute TVA on the post-discount base) so the persisted/receipt TVA matches the Z netting. Cover with a behavioral test asserting order.total_tax == Z per-rate contribution for a discounted order. Touches frozen pricing/fiscal zone -> requires lock-plan.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.
- **self-audit note** : Self-audit: keep P1 — with manual_discount_enabled=true (verified at runtime, no .env override) the coupon/discount divergence is LIVE, not residual.

#### S2-04 [P2] Online-orders list 'Montant' column renders raw '19.00' (US format, no € symbol) while POS list + Historique render canonical '19,00 €' — money column formatting diverges across the three order screens
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : visual · **tags** : NF525
- **file:line** : `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue:119 ({{ order.total_amount_price }}) vs resources/js/components/admin/posOrders/PosOrderListComponent.vue:126 ({{ formatPrice(order.total) }}) ; app/Libraries/AppLibrary.php:306-308 (flatAmountFormat = number_format(amount, decimals, '.', '') )`
- **correction** : In OnlineOrderListComponent, import adminPriceMixin (already used by PosOrderList/Historique) and render {{ formatPrice(order.total) }} instead of {{ order.total_amount_price }} (total is already exposed raw by SimpleOrderResource).
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### S7-01 [P2] Branch fiscal identity (SIRET / TVA intra / register_id / legal_footer) has NO settings UI and is never seeded — receipt prints them blank
- **report/micro** : SYSTEMS / S7-settings-business · **category** : nf525 · **tags** : NF525
- **file:line** : `resources/js/components/admin/settings/Branch/BranchCreateComponent.vue:14-118 (form) + app/Http/Requests/BranchRequest.php:34-46 (rules) + app/Services/Receipt/ReceiptDataService.php:66-69 (consumer)`
- **correction** : Add siret/vat_intra/register_id/legal_footer inputs to BranchCreateComponent.vue (and BranchShow), add their validation rules to BranchRequest (e.g. siret digits:14 nullable, vat_intra regex FR, register_id/legal_footer string max). For immediate V1 single-box compliance, also seed branch_id=1 with Le Cayenne's real values. No frozen-zone touch (BranchService/Request/Vue are all editable).
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.
- **self-audit note** : Self-audit reframe + downgrade P1->P2: the 'receipt prints SIRET/TVA blank' impact is now STALE — branch 1 fiscal identity is populated (E.DELICE SAS: SIRET 10417050100019 / TVA FR19104170501 / register CAISSE-01 / legal_footer), verified live. The REAL residual is durability/UX: there is no admin Settings UI to set fiscal identity; it was set by the foodking:set-branch-legal CLI, so a reseed/migrate:fresh would blank it again with no in-app way to restore.

#### S8-02 [P2] Logout / Désactivation / Suppression d'une borne kiosk côté Admin ne révoque PAS le token Sanctum kiosk:order — la borne peut continuer à commander
- **report/micro** : SYSTEMS / S8-settings-payments · **category** : security · **tags** : NF525, SECURITY
- **file:line** : `app/Services/KioskMachineService.php:179-191 (logout) + 108-124 (destroy) + 145-167 (changeStatus)`
- **correction** : Dans logout()/destroy()/changeStatus(INACTIVE), révoquer les tokens kiosk de l'user lié : `User::find($kioskMachine->user_id)?->tokens()->where('name','kiosk-token')->delete()` dans la même transaction, en miroir de KioskMachineLoginController.php:95. Pour changeStatus INACTIVE, ne révoquer que si on passe ACTIVE→INACTIVE.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.
- **self-audit note** : Self-audit re-rating P1->P2 + citation fix: scope the token-revocation gap to the logout()/is_login path (controller app/Http/Controllers/Auth/KioskMachineLoginController.php); destroy + changeStatus are already server-enforced, so remove them from the vector. The real residual: logging out / deactivating a borne from Admin does not revoke its active Sanctum kiosk:order token (TTL 480min) until expiry.

#### S12-02 [P2] COD doorstep cash collected with NO open driver session is never mirrored into any cash session — reconciliation-completeness gap
- **report/micro** : SYSTEMS / S12-delivery · **category** : nf525 · **tags** : NF525
- **file:line** : `app/Services/OrderService.php:1919-1986`
- **correction** : Either (a) require an open driver cash session before allowing OFD→DELIVERED on COD orders (strict mode 422 LIVREUR_SHIFT_NOT_OPEN, which recordMovement already supports), or (b) auto-open a session / route the orphan collection into a dedicated unassigned-cash bucket surfaced in the Z report so no collected COD cash is invisible to reconciliation.
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

#### GAP-items-pdf [P2] Items report PDF export truncates to the current page (10 rows) — no paginate=0 normalization, same bug as the on-screen footer
- **report/micro** : SYSTEMS / SELF-AUDIT-ADD · **category** : logic · **tags** : NF525
- **file:line** : `app/Http/Controllers/Admin/ItemsReportController.php (pdf path calls itemReport(request) without $request->merge(['paginate'=>0]))`
- **correction** : In ItemsReportController::pdf, $request->merge(['paginate'=>0]) before itemReport(), exactly like the Excel export.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S2-06 [P3] Transactions list date-range presets are hardcoded English ('Today', 'This month', 'This year (slot)') and the datepicker lacks the aria-label present on the other order screens
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : ux · **tags** : NF525
- **file:line** : `resources/js/components/admin/transactions/TransactionListComponent.vue:186-199 (preset labels) + :58-60 (Datepicker, no :aria-labels) vs OnlineOrderListComponent.vue:214-227 (French presets) + :64-66 (aria-labels)`
- **correction** : Translate the Transactions preset labels to French (or i18n keys) matching the sibling lists, drop the '(slot)' debug suffix, and add :aria-labels='{ input: $t("label.date") }' to the Datepicker for parity.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S6-02 [P3] Tax/ItemCategory destroy() has inverted FK-bypass branch — orphans rows when only inactive items reference it
- **report/micro** : SYSTEMS / S6-settings-catalog · **category** : logic · **tags** : NF525
- **file:line** : `app/Services/TaxService.php:83-90 ; app/Services/ItemCategoryService.php:170-183`
- **correction** : Invert the branches (only bypass FK when there are NO references) AND/OR null the dependent FK before delete. Better: block deletion (422) when any item — active or inactive — references the tax/category, instead of disabling FK_CHECKS. Use Item::withTrashed()->where('tax_id',...)->exists() for the real reference check.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S6-03 [P3] tax_rate accepts up to 9 999 999 999 999 with no sane upper bound (no max:100) — a typo corrupts VAT/pricing/Z-report
- **report/micro** : SYSTEMS / S6-settings-catalog · **category** : nf525 · **tags** : NF525
- **file:line** : `app/Http/Requests/TaxRequest.php:42`
- **correction** : Tighten to `['required','numeric','min:0','max:100']` for a percentage VAT; constrain status to `Rule::in([Status::ACTIVE, Status::INACTIVE])`. Optionally warn the admin when editing a rate that already has historical orders (history is protected by snapshot, but UX clarity helps).
- **validation** : PHPUnit fiscal test (sequence gap-free / Z netting / chain HMAC) + `php artisan fiscal:verify-chain --all` attestation BEFORE & AFTER (count+last_hash appended-only). Frozen fiscal-service diff MUST = 0 lines unless under a LOCK doc.

#### S7-05 [P3] TimeSlot has NO update path — opening hours can only be created or deleted, not edited
- **report/micro** : SYSTEMS / S7-settings-business · **category** : functional · **tags** : NF525
- **file:line** : `app/Http/Controllers/Admin/TimeSlotController.php:20 + app/Services/TimeSlotService.php (no update method)`
- **correction** : Add an update endpoint (TimeSlotController::update + TimeSlotService::update) reusing the corrected overlap check, and an edit modal in TimeSlotCreate/List. Scope-minimal, no frozen-zone.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S12-03 [P3] destroy() soft-deletes a livreur without checking open cash session or active assigned orders
- **report/micro** : SYSTEMS / S12-delivery · **category** : logic · **tags** : NF525
- **file:line** : `app/Services/DeliveryBoyService.php:149-169`
- **correction** : Before delete, abort 409 if an OPEN DeliveryBoyCashSession exists for the driver, or if active (non-terminal) orders are assigned. Force close+reconcile / reassign first.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

### W4-security

#### S10-01 [P1] CustomerService::show() missing target-role guard — read-side privilege escalation (any User id leaks PII)
- **report/micro** : SYSTEMS / S10-customers · **category** : security · **tags** : SECURITY
- **file:line** : `app/Services/CustomerService.php:128-140 ; route routes/api.php:547 ; gate CustomerController.php:39`
- **correction** : Add `$this->assertTargetRole($customer);` at the top of CustomerService::show() (mirror update/changePassword/changeImage). Apply equivalently before OrderService::userOrder() use in CustomerController::myOrder and before address index, OR scope the {customer} route binding to the Customer role via Route::bind / a scoped implicit binding.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.

#### S11-03 [P2] Password reset and image change gated only by the base VIEW permission (employees / administrators), not by *_edit — FormRequest authorize() returns true
- **report/micro** : SYSTEMS / S11-staff-rbac · **category** : security · **tags** : SECURITY
- **file:line** : `app/Http/Controllers/Admin/EmployeeController.php:28`
- **correction** : Move changePassword and changeImage out of the `permission:employees` .only(...) group into a `permission:employees_edit` group (and `administrators_edit` for the admin controller). Optionally tighten the two FormRequests' authorize() to check `$this->user()?->can('employees_edit')` for defense-in-depth, mirroring EmployeeRequest::authorize().
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.

#### S6-06 [P3] LoyaltySetupRequest & ItemCategoryImportRequest authorize() return true (defense-in-depth gap)
- **report/micro** : SYSTEMS / S6-settings-catalog · **category** : security · **tags** : SECURITY
- **file:line** : `app/Http/Requests/LoyaltySetupRequest.php:11 ; app/Http/Requests/ItemCategoryImportRequest.php:14`
- **correction** : Mirror the Tax/Currency pattern: `return $this->user()?->can('settings') ?? false;` in both requests (and lower the FormRequestAuthz sentinel baseline accordingly per CLAUDE.md §9).
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.

#### S8-08 [P3] Champs secrets de gateway (stripe_secret, *_client_secret) rendus en clair à l'écran (input type=text) dans la config Paiement
- **report/micro** : SYSTEMS / S8-settings-payments · **category** : security · **tags** : SECURITY
- **file:line** : `resources/js/components/admin/settings/PaymentGateway/PaymentGatewayComponent.vue:47-48 + app/Http/Resources/GatewayOptionsResource.php:22 (value brut)`
- **correction** : Rendre les champs marqués secret en type=password avec bouton afficher/masquer, et idéalement masquer la valeur côté API (renvoyer un placeholder type ●●●●1234 et n'écrire que si l'admin saisit une nouvelle valeur). Aligner avec la pratique de masquage des credentials.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.

#### S9-04 [P3] LanguageService::fileTextStore allows arbitrary value injection into .php lang files (RCE-adjacent, admin-gated)
- **report/micro** : SYSTEMS / S9-settings-system · **category** : security · **tags** : SECURITY
- **file:line** : `app/Services/LanguageService.php:279`
- **correction** : Escape values before writing (addslashes / var_export the string), or restrict fileTextStore to .json files only and never write into includable .php files; alternatively persist translations to DB rather than rewriting PHP source.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.

#### S10-02 [P3] Customer address write ops (store/update/destroy) gated by a READ permission (customers_show)
- **report/micro** : SYSTEMS / S10-customers · **category** : security · **tags** : SECURITY
- **file:line** : `app/Http/Controllers/Admin/CustomerAddressController.php:22`
- **correction** : Split the gates: keep customers_show on index/show; gate store with customers_create (or a new customers_edit), update with customers_edit, destroy with customers_delete — matching CustomerController.php:36-38.
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.

#### S13-04 [P3] Push notification store() trusts request branch_id with no ownership check and no Rule::exists — branch user can broadcast globally by sending branch_id=0
- **report/micro** : SYSTEMS / S13-marketing · **category** : security · **tags** : SECURITY
- **file:line** : `app/Services/PushNotificationService.php:63,82-92 ; app/Http/Requests/PushNotificationRequest.php:37 ; app/Http/Controllers/Admin/PushNotificationController.php:37-44`
- **correction** : In store() (or PushNotificationRequest), force branch_id to the authenticated non-admin user's branch via AdminController::authorizeWritableBranchScope(), and add Rule::exists('branches','id'); only Admin/Tenant Admin may target branch_id=0 (global).
- **validation** : PHPUnit authz/branch-isolation test (expect 403 / scoped result for branch_id>0, admin bypass intact) + adversarial RED replay of the exploit path proving it is now closed.
- **self-audit note** : Self-audit re-rating P2->P3: push-notification target lacks Rule::exists + branch-ownership — V2 multi-tenant hardening; low risk on V1 single-branch.

### W5-logic

#### M3-01 [P1] Aucune validation des étapes obligatoires dans le flux actif (single-page) : on peut ajouter un Tacos 0 viande / 0 sauce au panier
- **report/micro** : BOX / M3-item-wizard · **category** : logic · **tags** : FROZEN-HARD (public/js/pos-wizard.js), live-verified
- **file:line** : `public/js/pos-wizard.js:5850-5855 (handler add-to-cart) + 4773/5135 (renderSinglePage = seul renderer) ; canProceedFromStep défini 5149-5226 mais jamais appelé dans le chemin actif`
- **correction** : FROZEN : ne pas modifier sans gate owner. Correction décrite : appeler une validation de complétude (équivalent canProceedFromStep agrégé sur viandes requises + sauce requise + pain) dans le handler add-to-cart single-page avant syncAndSubmit ; bloquer + showValidationError si incomplet. Réutiliser les messages déjà présents (5156-5208).
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit evidence correction: the dead completeness guard lives in the never-invoked bindEvents() (pos-wizard.js:5288) bound to renderNav()'s [data-nav] buttons (emitted only inside the also-dead renderWizard()); the ACTIVE add-to-cart handler is 5851-5854 -> syncAndSubmit() with no viande/sauce/pain gating. Conclusion unchanged (a 0-viande/0-sauce Tacos can be added). LIVE-VERIFIED post-self-audit: opened the Tacos wizard, clicked Ajouter au panier (data-action=add-to-cart, handler pos-wizard.js:5850-5854 -> syncAndSubmit() with no gate) with ZERO viande/sauce selected -> cart 0->1 article, total 6,90 EUR, wizard closed. The red 0/1 badge is purely cosmetic; the button is enabled and adds. Capture: 40-wizard-add-zero-viande.png.

#### M4-02 [P1] Apres rechargement (panier restaure du localStorage), la remise est restauree mais PAS son motif -> l'encaissement est rejete 422 par le backend sans cause visible
- **report/micro** : BOX / M4-cart-ticket · **category** : functional · **tags** : —
- **file:line** : `resources/js/store/modules/posCart.js:38-45 (saveCartToStorage persiste lists/subtotal/discount, JAMAIS discount_reason) + :538-549 (hydrateFromScope restaure state.discount) ; resources/js/components/admin/pos/PosComponent.vue:3687-3698 (discount_reason fixe UNIQUEMENT dans applyDiscount) + :3800-3849 (orderSubmit ne repopule jamais form.discount_reason) ; app/Services/OrderService.php:2894-2898 (backend re-exige reason>=3) + :836-840 / :1027-1031 (assertPosManualDiscountAllowed appele a la creation)`
- **correction** : Persister `discount_reason` dans saveCartToStorage et le re-hydrater dans hydrateFromScope -> orderSubmit (ou un mounted hook) doit alors recharger `checkoutProps.form.discount_reason` depuis le store restaure. Alternative minimale : a l'hydratation, si `state.discount > 0` et aucun motif disponible, forcer `posDiscount=0` + vider l'input pour que l'UI n'affiche jamais une remise non-encaissable. Backend reste SSOT — ne pas relaxer l'exigence de motif.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.
- **self-audit note** : Self-audit caveat: this whole M4 discount cluster is live-reachable only because POS_MANUAL_DISCOUNT_ENABLED is unset (config default true) — see ESC-01. If the owner sets it false, M4-01/02/03/05 become unreachable.

#### M6-001 [P1] Cash-dominant split payment is hard-rejected (422) by the single-tender cash guard even when perfectly balanced
- **report/micro** : BOX / M6-payment · **category** : logic · **tags** : FROZEN-REFERENCED (fix non-frozen side; §7 ref: resources/js/components/admin/pos/PaymentComponent.vue)
- **file:line** : `app/Services/OrderService.php:1071-1078 (guard) + resources/js/components/admin/pos/PaymentComponent.vue:836-841 (payload build)`
- **correction** : Backend fix (OrderService.php:1071 is NOT a frozen file): skip the single-tender cash guard when payment_breakdown is present and non-empty (i.e. when $splitActive). SplitPaymentService::validateBreakdown already enforces sum >= server total per-tranche, so the legacy whole-order received>=total check is wrong for splits. Concretely: wrap the guard in `if (! $splitActive && ...)`. The frontend alternative (sending full order total as pos_received_amount) is blocked because PaymentComponent.vue is FROZEN — touching the payload build needs an owner gate, so the backend skip is the correct, scope-minimal fix.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M7-02 [P1] Recall silently drops unavailable variations AND unavailable item lines — the cashier is never told the restored cart differs from what was parked
- **report/micro** : BOX / M7-parked · **category** : logic · **tags** : —
- **file:line** : `app/Services/PosParkedOrderService.php:86-97 & 113-193 (warnings built, attached to snapshot); resources/js/store/modules/posParked.js:95-133 (_recall_purged_item_ids built); resources/js/components/admin/pos/ParkedOrdersComponent.vue:186-203 (restore path) & PosComponent.vue:3474-3528 (applyParkedSnapshot)`
- **correction** : Surface the diff: when warnings.unavailable_variations is non-empty or _recall_purged_item_ids has entries, replace the plain success toast with an explicit warning listing the dropped items/variations (alertService.warning) so the cashier can re-offer or re-price before completing the sale.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M8-01 [P1] One 'Rembourser' button, two divergent server paths with asymmetric side-effects (pre-Z vs post-Z)
- **report/micro** : BOX / M8-refund · **category** : logic · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/PosOrderController.php:105-153 (isSealed branch) vs :215-248 (refundPreZ)`
- **correction** : Make the two paths side-effect-symmetric. Simplest scope-safe fix: after the successful pre-Z changeStatus(RETURNED) in PosOrderController::refundPreZ (or inside the RETURNED branch of OrderService::changeStatus at :2196), dispatch RefundCreated::dispatch($order) so the SAME cascade (stock + availability + earned-points clawback + payment-status broadcast) runs for pre-Z refunds. Guard against double-release is already built in (released_qty ledger idempotent; loyalty clawback idempotent on type=manual_deduct; refundPoints idempotent on type=manual_add). Owner-gate any change inside OrderService::changeStatus given its blast radius; prefer dispatching from the controller branch.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M1-05 [P2] Cash-session auto-prompt and the 'Caisse OPEN' badge are scoped per-USER, so on a shared single-box terminal a second cashier sees 'no session' and the drawer the first cashier opened is invisible (two open sessions live is expected, not handled)
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : logic · **tags** : —
- **file:line** : `app/Services/Cash/CashDrawerService.php:475-482 (findOpenSessionForUser) + resources/js/components/admin/pos/PosComponent.vue:2400-2422 (autoLoadCashSession) + cashDrawer.js getter isOpen:26`
- **correction** : For V1 single-box, scope the open-session lookup/badge to branch (one drawer = one open session per branch), or at minimum surface 'Une session est deja ouverte par <user> sur cette caisse' instead of prompting a fresh open. Decision is owner-gated (changes accounting model) — flag for owner before code change.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M2-01 [P2] Search is silently scoped to the active category — cashier typing a product name while a category pill is active gets 0 results for products that exist
- **report/micro** : BOX / M2-catalog-nav · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3536-3539 (onSearchInput) + 3622-3626 (setCategory) + resources/js/store/modules/item.js:84-87`
- **correction** : In onSearchInput, when a non-empty query is entered, clear props.search.item_category_id (set to '') so search queries the full catalogue as the inline comment at PosComponent.vue:1958 intends; restore the previously-active category on resetName/clear. Alternatively keep scoped search but make it obvious (badge 'recherche dans Tacos') so the operator understands the scope. Scope-minimal: one line in onSearchInput.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M3-03 [P2] Viande supplémentaire tarifée à un prix FORFAITAIRE (VIANDE_SUPPL_PRICE €2,50) quelle que soit la viande, et synchronisée comme extra binaire (×N perdu côté Vue)
- **report/micro** : BOX / M3-item-wizard · **category** : logic · **tags** : FROZEN-HARD (public/js/pos-wizard.js)
- **file:line** : `public/js/pos-wizard.js:1287-1293 (calculateRunningTotal: supplTotal*VIANDE_SUPPL_PRICE) ; 3886-3894 (syncAndSubmit: allSelectedExtras[id]=true binaire) ; prix injecté 89`
- **correction** : FROZEN : décrire. Lire le prix DB réel de chaque viande extra (variation/extra convert_price) au lieu du forfait, et transmettre la quantité réelle (item_extras avec quantité) pour que le quote serveur reprenne le bon montant. Gate owner.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M4-01 [P2] Changer une quantite REMET A ZERO silencieusement une remise deja appliquee (l'input reste rempli, le total perd la remise sans avertir)
- **report/micro** : BOX / M4-cart-ticket · **category** : logic · **tags** : —
- **file:line** : `resources/js/store/modules/posCart.js:296-300 (action quantity commits discount=0) + :489-503 (mutation quantity) ; resources/js/components/admin/pos/PosComponent.vue:3635-3639 (cartQuantityIncrement/Decrement) + :956 (v-if=posDiscount) + :4409-4437 (carts watcher ne nettoie l'input QUE si panier vide)`
- **correction** : Coherence du cycle de vie de la remise : soit (a) re-appliquer automatiquement la remise apres une modif panier en re-resolvant le % contre le nouveau subtotal (preferable pour un %), soit (b) si on garde le reset a 0, vider AUSSI l'input `this.discount`, `discountType` et `discountReason` dans le meme tour et afficher un toast 'Remise annulee suite a modification du panier' pour que l'UI ne mente plus. Le total affiche reste = total envoye au backend (orderSubmit:3823 lit posDiscount) donc PAS de fuite fiscale ; le defaut est purement UX/logique cote caisse.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit re-rating P1->P2: total charged == total displayed (the Remise footer line disappears with the cleared discount), so no money loss and not sale-blocking; residual harm is only the stale discount input field. (Contrast M4-02, a real 422-blocking flow, which stays P1.)

#### M4-03 [P2] Invalidation de remise incoherente entre mutations panier : quantity/delete/prune remettent a 0, mais replaceCartLine/setItemVariations laissent une remise EUR perimee
- **report/micro** : BOX / M4-cart-ticket · **category** : logic · **tags** : —
- **file:line** : `resources/js/store/modules/posCart.js:296-305 (quantity+deleteCartItem -> discount 0) + :510-519 (pruneUnavailable -> discount 0) VS :318-325 (replaceCartLine + setItemVariations ne touchent jamais discount) ; resources/js/components/admin/pos/PosComponent.vue:3764-3768 (editCartLine) + ItemComponent.vue:1434-1438 (replaceCartLine dispatch)`
- **correction** : Unifier l'invalidation de remise : faire que replaceCartLine et setItemVariations declenchent la meme logique que quantity (soit reset a 0, soit re-resolution du % contre le nouveau subtotal). Idealement stocker le TYPE+valeur saisie (% ou EUR) et recalculer le montant EUR a chaque mutation subtotal, plutot que figer un EUR resolu.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M6-003 [P2] autoBalanceTranches mis-balances with 3+ tranches — one absorber takes the whole remainder, ignoring the other tranches
- **report/micro** : BOX / M6-payment · **category** : logic · **tags** : —
- **file:line** : `resources/js/helpers/posSplitPayment.js:275-301 (autoBalanceTranches) + PaymentComponent.vue:678-685 (autoBalanceFromIndex), button rendered at PaymentComponent.vue:254 (tranches.length >= 2)`
- **correction** : Fix autoBalanceTranches (helper is NOT frozen): compute remainder as total minus the sum of ALL non-absorber tranches, not just the edited one: `const othersCents = tranches.reduce((s,t,i)=> i===absorberIndex ? s : s + toCents(t.amount), 0); const newAmountCents = Math.max(0, totalCents - othersCents);`. Add a unit test for a 3-tranche balance asserting sum === total.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M6-004 [P2] Frontend canConfirm tolerates a 1-cent UNDERPAY that the backend rejects — UI says OK, server 422s
- **report/micro** : BOX / M6-payment · **category** : logic · **tags** : —
- **file:line** : `resources/js/helpers/posSplitPayment.js:144-151 (canConfirm) vs app/Services/Payments/SplitPaymentService.php:147-155 (validateBreakdown strict underpay reject)`
- **correction** : Tighten canConfirm (helper not frozen) to forbid underpay: change `remaining <= 1` to `remaining <= 0` (or strictly `<= 0`), keeping the overpay branch. Align the slack with backend (backend allows 0 underpay, up to TOLERANCE_OVERPAY=1,00 overpay). This makes the client gate a true subset of the server contract.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M7-01 [P2] Recall consumes (deletes) the parked ticket on a GET, before the client cart is rebuilt — a dropped response loses the ticket permanently
- **report/micro** : BOX / M7-parked · **category** : logic · **tags** : —
- **file:line** : `app/Services/PosParkedOrderService.php:72-103 (delete at :99); resources/js/store/modules/posParked.js:80-134 (resetCart at :85, populate at :90)`
- **correction** : Do not mutate on GET. Either (a) make show() read-only and add an explicit DELETE/POST 'consume' step that the client only fires AFTER the cart is verified restored, or (b) keep recall transactional but soft-delete (consumed_at) and only hard-purge on a confirmed client ack; and on the client, populate the cart fully and validate before issuing the consume, so a failed restore leaves the ticket intact.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M8-02 [P2] Earned-points clawback fix was applied only to the rare post-Z path — the common pre-Z refund re-opens the documented cash+points double-dip
- **report/micro** : BOX / M8-refund · **category** : logic · **tags** : —
- **file:line** : `app/Listeners/ClawbackLoyaltyPointsOnRefund.php:11-36 (docblock) + app/Providers/EventServiceProvider.php:197-211 (only on RefundCreated) + app/Services/OrderService.php:2195 (pre-Z calls refundPoints only)`
- **correction** : Covered structurally by M8-01 (dispatch RefundCreated on the pre-Z branch — the listener is idempotent on type=manual_deduct so it is safe). If a narrower fix is preferred, call LoyaltyService::clawbackEarnedPoints directly in the RETURNED branch right after refundPoints (OrderService.php:2195), mirroring the post-Z service. Owner-gate if touching OrderService.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M8-05 [P2] Dead partial-refund plumbing: refundedItems is threaded through 3 layers but every dispatch passes [] and the modal has no per-item UI
- **report/micro** : BOX / M8-refund · **category** : logic · **tags** : —
- **file:line** : `app/Events/RefundCreated.php:25-29 (refundedItems param) + all 3 dispatch sites pass order only (PaymentService.php:187, RefundWithCounterEntryService.php:415, Stripe.php:442) + PosRefundModal.vue has no item selection`
- **correction** : Decision call for owner: either (a) delete the partial-refund branches (RefundCreated.refundedItems default-empty, the non-empty branches in the two listeners, the requestedByOrderItemId logic in StockService) to simplify to full-refund-only, OR (b) wire a per-line picker in PosRefundModal + pass refundedItems through the controller -> service -> dispatch. Do not leave half-built. No urgency; pure clarity/maintenance.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M8-06 [P2] Idempotency key is re-minted on every modal open; the only true double-refund backstop for the pre-Z path is a status-equality early-return, not the advertised UNIQUE/409
- **report/micro** : BOX / M8-refund · **category** : functional · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosRefundModal.vue:280-298 (watch mints key with Date.now()) + docblock :219-223 claims UNIQUE catches reopens; pre-Z has no such UNIQUE`
- **correction** : Two cheap improvements: (1) Hide the Rembourser CTA once status===RETURNED (add to canShowRefund in PosOrderShowComponent.vue:522). (2) Correct the PosRefundModal docblock to state that pre-Z double-protection is the status-equality early-return + existing-cash_back guard, not UNIQUE/409. Optionally key idempotency on order id only (drop Date.now()) so reopens reuse the key. Frontend + doc only.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### M8-07 [P2] PaymentStateMachine forbids PAID->REFUNDED, so a refunded parent's payment_status is permanently misleading on the pre-Z path
- **report/micro** : BOX / M8-refund · **category** : logic · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/PosOrderController.php:94-103 (deliberate non-flip) + PersistOrderPaymentStatusChangedOnRefundCreated.php:85-123 (post-Z synthetic broadcast)`
- **correction** : Two options, owner-gated since fiscal-adjacent: (a) widen PaymentStateMachine to allow PAID->REFUNDED and flip on refund (parallels the synthetic broadcast intent), or (b) keep PAID but ensure the pre-Z path dispatches RefundCreated (M8-01) so PersistOrderPaymentStatusChangedOnRefundCreated emits the synthetic refund broadcast and clients show the refund consistently. Prefer (b) as lower-risk; it also resolves M8-01/M8-02.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M9-01 [P2] Modal hardcodes rate=100; never receives the admin-configured loyalty rate -> wrong preview, wrong step, false POINTS_NOT_MULTIPLE
- **report/micro** : BOX / M9-loyalty · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:187-189,206-214,90; PosComponent.vue:1117-1122; PosOrderShowComponent.vue:354-359`
- **correction** : Pass the real rate into both mounts: bind :rate to the loyaltySetup store value (the loyaltySetup module already exists, resources/js/store/modules/loyaltySetup.js) at PosComponent.vue:1117 and PosOrderShowComponent.vue:354. Cheap, no frozen-zone touch, no backend change.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit: correct citation to resources/js/components/admin/posOrders/PosOrderShowComponent.vue:354-357. LATENT today because the live loyalty rate IS 100 (the hardcoded preview matches by coincidence); becomes wrong the moment the admin configures a non-100 rate.

#### M9-02 [P2] DISCOUNT_EXCEEDS_SUBTOTAL only checks the incremental discount, not the cumulative one -> points burned with no benefit when a coupon discount already exists
- **report/micro** : BOX / M9-loyalty · **category** : logic · **tags** : —
- **file:line** : `app/Services/Loyalty/PosRedemptionService.php:144-151,168-174,203-227`
- **correction** : Check remaining redeemable headroom: compute $remaining = max(0, $subtotal - (float)$order->discount) and reject (or clamp points to) when $discountEur > $remaining, before decrementing the balance. Mirror the cumulative semantics the kiosk path gets for free via PricingService. Surface a DISCOUNT_EXCEEDS_SUBTOTAL/headroom error so the cashier can reduce the points.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M10-04 [P2] Ready-orders 'Livrer' shortcut can mark a still-unpaid (PENDING_COUNTER) kiosk order DELIVERED — food handed out before cash collected
- **report/micro** : BOX / M10-counter-collect · **category** : logic · **tags** : FROZEN-REFERENCED (fix non-frozen side; §7 ref: app/Domain/Order/OrderStateMachine.php), NEEDS-LIVE-VERIFY
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3220-3271 ; app/Domain/Order/OrderStateMachine.php:65-71`
- **correction** : Guard the ready board against unpaid counter-deferred orders: in loadReadyOrders, exclude rows where payment_status===PENDING_COUNTER (or order is counter-deferred) so they cannot be delivered before collection; and/or add a payment_status===PAID precondition on the PREPARED->DELIVERED transition for counter-deferred orders. Frontend filter is scope-minimal; the state-machine guard touches OrderStateMachine (business-critical) and needs an owner gate.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit framing: this is a cashier-action footgun (the 'Livrer' shortcut can mark a still-unpaid PENDING_COUNTER kiosk order DELIVERED under Plan B kiosk.payment_route_all_to_counter=true), NOT silent/automatic money loss.

#### M12-001 [P2] Tracker time badges blank + sort inverted + name-search dead - SimpleOrderResource never ships created_at/updated_at/user
- **report/micro** : BOX / M12-tracker-zx · **category** : functional · **tags** : —
- **file:line** : `app/Http/Resources/SimpleOrderResource.php:33-112 (no created_at/updated_at/user key) vs resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:157,553-556,500,742-746,981-992`
- **correction** : Add raw fields to SimpleOrderResource (unfrozen): 'created_at' => $this->created_at?->toIso8601String() (and optionally updated_at). Cheapest correct fix. Alternative: repoint the tracker to order_datetime which the resource ALREADY sends. For name-search, ship a minimal user:{name,first_name} subset or add o.customer_name to the search hay.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.
- **self-audit note** : Self-audit re-rating P1->P2: all three sub-defects are real (no created_at/updated_at/user in SimpleOrderResource -> blank time badges + inverted sort + dead name-search), but the customer name still renders via the customer_name fallback and there is no money/fiscal/sale-blocking impact -> display/triage defect, not P1.

#### M12-002 [P2] Tracker re-pulls the ENTIRE day every poll - paginate never sent so per_page:100 is silently ignored
- **report/micro** : BOX / M12-tracker-zx · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:751-756 vs app/Services/OrderService.php:129-130 (list())`
- **correction** : Either (a) send paginate:1 so per_page:100 actually caps, or (b) drop per_page and add a backend status filter so the tracker pulls only active statuses (ACCEPT/PREPARING/PREPARED/OUT_FOR_DELIVERY) + a small DELIVERED tail. Also drop media eager-loads for this path (tracker shows only item_name/quantity). All edits in unfrozen files.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M12-003 [P2] 'Livre' button on tracker lets a DELIVERY order skip OUT_FOR_DELIVERY - state machine permits PREPARED->DELIVERED for any order type
- **report/micro** : BOX / M12-tracker-zx · **category** : logic · **tags** : FROZEN-HARD (app/Domain/Order/OrderStateMachine.php)
- **file:line** : `app/Domain/Order/OrderStateMachine.php:65-71 + resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:270-280,772-790`
- **correction** : Scope the tracker 'Livre' CTA: show on prepared cards only when order_type is NOT delivery; for delivery show 'Depart livraison' transitioning to OUT_FOR_DELIVERY. Defense-in-depth: gate the state machine PREPARED->DELIVERED edge to non-delivery types. OrderStateMachine is domain-critical - confirm with owner before tightening; the tracker-side button guard is a safe scope-minimal first fix.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M1-03 [P3] Close dialog forces a variance reason for ANY non-zero ecart (>0.005EUR) while backend only requires it above the 2.00EUR threshold — UX is stricter than the rule and blocks legitimate closes
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue:400-407 (varianceRequiresReason/varianceReasonMissing) vs app/Services/Cash/CashDrawerService.php:266-312 + config/cash.php:31`
- **correction** : Make the dialog read the same threshold the backend uses (expose cash.variance_threshold_eur to the SPA config/bootstrap, default 2.00) and gate varianceRequiresReason on |variance|>threshold, not >0.005. Keep the visual variance highlight for any non-zero ecart, but only REQUIRE a reason past the threshold to match CashDrawerService.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M1-08 [P3] onCashSessionOpened handler is an empty no-op despite documenting that it should switch to the 'active' view
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:2430-2434 (onCashSessionOpened)`
- **correction** : Either remove the empty handler (and its @session-opened binding) or make it do the documented work (e.g. ensure the badge/movements refresh from the parent). Smallest fix: delete the dead method + listener to reduce surface.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M5-03 [P3] Livraison crée un NOUVEAU client jetable (email delivery_<timestamp>@pos.local) à chaque commande — aucun re-usage du client comptoir/sélectionné
- **report/micro** : BOX / M5-customer-ordertype · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:4360-4372`
- **correction** : Réutiliser un client 'Livraison comptoir' générique (pattern walk-in) OU matcher sur le téléphone saisi avant de créer. Si création nécessaire, générer un mot de passe aléatoire fort, pas 'delivery123'. Idéalement router la livraison anonyme vers le même walk-in que takeaway et n'attacher que l'adresse.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### M5-07 [P3] diningTable/lists est toujours chargé au montage même quand dine-in est désactivé (V1)
- **report/micro** : BOX / M5-customer-ordertype · **category** : perf · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:2335-2340`
- **correction** : Garder l'appel diningTable/lists derrière if (this.dineInEnabled) dans mounted(). Idem diningTable côté store si non utilisé ailleurs en V1.
- **validation** : Before/after measurement of the named metric (query count / block_for ms / payload size) via a timing test or live trace; assert improvement + no functional regression.

#### M6-006 [P3] Cash input accepts multiple decimal points / numpad '.' and '00' bypass the keypress filter — change display can be silently wrong
- **report/micro** : BOX / M6-payment · **category** : functional · **tags** : FROZEN-REFERENCED (fix non-frozen side; §7 ref: resources/js/components/admin/pos/PaymentComponent.vue)
- **file:line** : `resources/js/services/appService.js:65-69 (floatNumber) + resources/js/components/admin/pos/PaymentComponent.vue:90 (v-on:keypress) / 544-555 (numpadInput/Back/Clear)`
- **correction** : Fix floatNumber to validate the resulting value, not the single char (e.g. reject if the field would contain >1 '.'), and route numpadInput through the same guard (reject a second '.'). Both are outside frozen zones (appService + PaymentComponent's own numpad handlers are app logic, but note PaymentComponent.vue itself is FROZEN — the safer change is in appService.floatNumber + a small guard in numpadInput, which is owner-gated since the file is frozen). If frozen scope blocks it, document as backlog.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S1-DASH-01 [P1] Date-range filters silently broken: datepicker sends raw JS Date toString (GMT+0200 ...) which Carbon cannot parse -> backend 422 -> chart keeps showing the STALE previous range with no error
- **report/micro** : SYSTEMS / S1-dashboard · **category** : logic · **tags** : —
- **file:line** : `resources/js/services/appService.js:243-263 (requestHandler) + SalesSummaryComponent.vue:88-91 + OrderSummaryComponent.vue:105-110 + OrderStatisticsComponent.vue:186-194 + CustomerStatsComponent.vue:75-80 ; consumed by app/Services/DashboardService.php:135-137 (Carbon::parse($request->first_date))`
- **correction** : Format dates to Y-m-d before dispatch. (a) in each handler convert e[0] to a local YYYY-MM-DD string, OR (b) harden appService.requestHandler to URL-encode values and convert Date instances to YYYY-MM-DD -- option (b) fixes every caller at once with smallest blast radius. Add a test that feeds a real Date-derived string (with spaces) through the controller; current sentinels only feed clean Y-m-d and miss this.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S8-01 [P1] TPE créé via Settings prend branch_id=0 (admin) → invisible/refusé à la caisse de la branche → impossible d'encaisser par carte
- **report/micro** : SYSTEMS / S8-settings-payments · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/settings/PaymentTerminals/PaymentTerminalsComponent.vue:202-211 (defaultForm sans branch_id) + app/Http/Controllers/Admin/PaymentTerminalController.php:108-117 (resolveBranchId)`
- **correction** : Ajouter un sélecteur de branche dans le modal (visible/obligatoire quand l'utilisateur courant est admin branch_id=0, comme le fait KioskMachineCreateComponent.vue:67-78), OU faire que resolveBranchId() retombe sur la branche par défaut V1 (config branche unique) au lieu de 0 quand aucune branche n'est fournie, OU valider 'branch_id' required pour l'admin. Cohérent avec le module Kiosk Machine qui, lui, expose bien branch_id.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit citation fix: add app/Services/Payments/SplitPaymentService.php to the citation. Defect and links unchanged — TPE created via Settings can take branch_id=0 (resolveBranchId returns validated branch_id for an admin, PaymentTerminalController.php:104) and is then invisible to the branch caisse. DIRECTLY relevant to the owner's terminal-association task: terminals must be assigned to the branch (branch_id=1), not left admin-global.

#### S17-01 [P1] Public QR table-order endpoint has NO pos_dine_in_enabled gate — dine-in is supposed to be OFF in V1 but anyone can still create a DINING_TABLE order
- **report/micro** : SYSTEMS / S17-dinein-tables · **category** : logic · **tags** : —
- **file:line** : `app/Http/Requests/TableOrderRequest.php:54-72 (withValidator) vs app/Http/Requests/PosOrderRequest.php:163-170 and app/Http/Requests/OrderRequest.php:218-230`
- **correction** : In TableOrderRequest::withValidator, mirror PosOrderRequest L163-170: if (int)request('order_type')===OrderType::DINING_TABLE && ! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false) -> $validator->errors()->add('order_type', 'Le service a table est desactive.'); This is a non-frozen FormRequest, scope-minimal, symmetric with the two existing gates.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S2-01 [P2] POS order detail: payment-status dropdown offers an option (Impayé) that the backend ALWAYS rejects — every default POS order is created PAID and PaymentStateMachine has no PAID->UNPAID edge
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:577-582 (paymentStatusObject) + :139-150 (dropdown) ; app/Domain/Order/PaymentStateMachine.php:17 (PAID => []) ; app/Services/OrderService.php:783 (payment_status => PaymentStatus::PAID at POS creation)`
- **correction** : Make paymentStatusObject status-aware like a transition list: when order.payment_status === PAID, render no actionable options (or a disabled/read-only badge); only when payment_status is UNPAID/PENDING_COUNTER expose the {PAID} option. Mirror the state machine (PaymentStateMachine::TRANSITIONS) so the UI never offers an edge the backend forbids.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S2-02 [P2] Order-status dropdown (POS + Online detail) lists ALL forward statuses regardless of current state — illegal/backward transitions are offered as clickable and fail with a 422 toast instead of being hidden
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : logic · **tags** : FROZEN-REFERENCED (fix non-frozen side; §7 ref: app/Domain/Order/OrderStateMachine.php)
- **file:line** : `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:535-566 (orderStatusObject) + :159-170 ; resources/js/components/admin/onlineOrders/OnlineOrderShowComponent.vue:370-398 + :145-162 ; guarded by app/Domain/Order/OrderStateMachine.php:30-92 (allows())`
- **correction** : Derive orderStatusObject from OrderStateMachine::allows() for the current order.status (expose an allowed-next-states list via the resource, or replicate the small transition table client-side). Render only the legal next states (advance-one-step + permitted terminals); disable/grey the rest. Keep the full enum only for the display label map.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S2-03 [P2] Unified Historique status filter cannot find Annulé / Rejeté / Retourné orders — dropdown omits all terminal states while the page advertises full history + a REFUNDED payment filter
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/orderHistory/HistoriqueListComponent.vue:33-40 (status options) vs :44-52 (payment offers REFUNDED) ; backend exact-match at app/Services/OrderService.php:172-173 ($query->where('status',(int)$request))`
- **correction** : Add CANCELED, REJECTED, RETURNED to the Historique status options array (they already exist in orderStatusEnum and the orderStatusClass helper). No backend change needed (exact-match already supports them).
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S3-L2 [P2] Reordering steps on a PUBLISHED composer profile leaves editor version stale -> spurious 409 conflict on next save/publish
- **report/micro** : SYSTEMS / S3-items-catalog · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue:737-745 + app/Services/Composer/ComposerStepService.php:109-118`
- **correction** : After the Promise.all in onStepsReordered, re-hydrate from the last PATCH response (it returns the fresh profile/version) or call reloadProfile-without-resetting-edits; simplest: have the step PATCH responses return the profile version and set this.version to the max returned. Alternatively route reorder through saveDraft (single profile PUT) so only one version bump occurs and the editor re-hydrates via hydrateProfile.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S4-01 [P2] Stock dashboard and customer surfaces disagree about the same ingredient: an extra/variation 86'd via the Ingredients admin still shows IN-STOCK on the Stock-Rupture dashboard
- **report/micro** : SYSTEMS / S4-stock-ingredients · **category** : logic · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/StockRuptureDashboardController.php:384-393 + 442-451; app/Services/Stock/ChoiceAvailabilityResolver.php:296-302 + 308-318; app/Services/Ingredients/IngredientAvailabilityService.php:34-62`
- **correction** : Make catalogOverview() the single read model: when building extra/variation payloads, also factor ItemExtra.is_available / ItemAttribute.is_available (ingredient-level rupture), e.g. SELECT those columns and treat is_available=false as out-of-stock with reason 'ingredient_rupture', mirroring ChoiceAvailabilityResolver's precedence (manual ingredient flag wins, then stock_levels.manual_unavailable_reason). Alternatively unify both admin toggles onto one storage column. Either way the dashboard must read every signal the customer surfaces read.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit re-rating P1->P2: same root cause as S4-02 (two divergent availability columns — stock_levels.manual_unavailable_reason vs item_extra/item_attribute.is_available). Real divergence, but align severity with its own root-cause finding.

#### S4-02 [P2] Two parallel, divergent toggle systems for the SAME extras: dashboard toggle writes per-extra-id to stock_levels (no name-cascade); Ingredients admin writes cascade-by-name to item_extra — they do not converge
- **report/micro** : SYSTEMS / S4-stock-ingredients · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:545-565 + 604-623; app/Services/Menu/AvailabilityService.php:523-571 (toggleStockable); app/Services/Ingredients/IngredientAvailabilityService.php:41-62`
- **correction** : Pick ONE SSOT for extra/variation rupture. Recommended: route the Stock dashboard's extra/variation toggles through IngredientAvailabilityService (cascade-by-name on item_extra.is_available) so both admin surfaces and ChoiceAvailabilityResolver agree, and reserve stock_levels.manual_unavailable_reason for true per-branch on-hand rupture. Document the chosen SSOT in CLAUDE.md to prevent re-divergence.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S5-01 [P2] Items report on-screen 'Total' footer sums only the current page (10 of 45 items), contradicting the Excel export's true total
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/itemsReport/ItemsReportListComponent.vue:316-320 (subTotal) + :120-127 (tfoot) + :234-236 (paginate:1, per_page:10)`
- **correction** : Compute the footer total from a server-provided grand total (add a summed 'total_units' to the index response meta, mirroring ItemsReportExport's paginate=0 accumulation) instead of reducing over the current page. Do NOT just paginate=0 the screen list (defeats pagination). Render the page-independent total in the tfoot.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S5-04 [P2] Cash session 'Total clôture' counts still-open sessions as 0, understating the day's closing total
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue:182-183 (reducer) + :247-250 (formatMoney) + :64-66 (closing_total render)`
- **correction** : Exclude null closing_amount from the totalClosing reducer and show a 'n sessions ouvertes' note, OR sum expected_closing_amount for open sessions. Make the header label distinguish 'clôtures enregistrées' from 'sessions ouvertes en cours'.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S7-02 [P2] TimeSlot overlap guard misses full-containment and exact-duplicate slots — overlapping opening hours can be saved
- **report/micro** : SYSTEMS / S7-settings-business · **category** : logic · **tags** : —
- **file:line** : `app/Services/TimeSlotService.php:54-61`
- **correction** : Replace the 3-branch check with the canonical interval-overlap test: reject when (new.opening < existing.closing) AND (new.closing > existing.opening). That single condition covers partial overlap, containment (both directions) and exact duplicates.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S7-04 [P2] Order Setup lets admin disable BOTH takeaway and delivery, leaving zero web fulfillment channels with no guard
- **report/micro** : SYSTEMS / S7-settings-business · **category** : logic · **tags** : —
- **file:line** : `app/Http/Requests/OrderSetupRequest.php:30-31 + app/Http/Requests/OrderRequest.php:238-244`
- **correction** : Add a cross-field rule in OrderSetupRequest (withValidator) rejecting the save when both order_setup_takeaway and order_setup_delivery are DISABLE, with a clear message ('au moins un canal de commande doit rester actif'). Optionally surface the active-channel state in OrderSetupComponent.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S8-04 [P2] PaymentTerminalRequest::authorize() retourne true — la validation/écriture des TPE n'est gardée que par le middleware contrôleur ; index/show non gardés exposent fees + serial
- **report/micro** : SYSTEMS / S8-settings-payments · **category** : functional · **tags** : —
- **file:line** : `app/Http/Requests/Admin/PaymentTerminalRequest.php:13-16 (authorize return true) + app/Http/Controllers/Admin/PaymentTerminalController.php:27 (middleware only store/update/destroy) + routes/api.php:954-959`
- **correction** : Aligner authorize() sur KioskMachineRequest (`return $this->user()?->can('settings') ?? false`) pour les mutations. Pour la lecture index par la caisse, NE PAS gater index sur 'settings' (la caisse en dépend) mais restreindre PaymentTerminalResource au strict nécessaire pour le POS (id, name, gateway_type, status) et omettre fee_percent/fee_fixed/serial pour les appelants non-'settings', ou créer une resource caisse séparée. Vérifier le sentinel authz drift.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S11-05 [P2] EmployeeService show/changePassword/changeImage guard via optional($employee->roles[0])->id — a role-less User bypasses the block-roles gate (no assertTargetRole like Waiter/Chef)
- **report/micro** : SYSTEMS / S11-staff-rbac · **category** : logic · **tags** : —
- **file:line** : `app/Services/EmployeeService.php:230`
- **correction** : Adopt the WAVE5-SEC-001 positive guard: add an assertTargetRole($employee) that requires the user to actually hold one of the employee roles (BRANCH_MANAGER/POS_OPERATOR/STUFF or any non-blocked role) before mutation, mirroring WaiterService::assertTargetRole. Treat a null roles[0] as a 403, not a pass.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S13-03 [P2] Push notification broadcast fires immediately on submit with no confirmation dialog — irreversible mass send is one click
- **report/micro** : SYSTEMS / S13-marketing · **category** : functional · **tags** : —
- **file:line** : `resources/js/components/admin/pushNotification/PushNotificationCreateComponent.vue:169-205 ; app/Services/PushNotificationService.php:55-116`
- **correction** : Add a confirmation step before dispatch that states the resolved audience ('Envoyer a N destinataires ? Action irreversible.'). Optionally surface the computed recipient count from the backend before committing the send.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S13-05 [P2] Push send swallows every per-token FCM error silently — failed/invalid tokens never logged or pruned, admin sees success for a no-op send
- **report/micro** : SYSTEMS / S13-marketing · **category** : functional · **tags** : —
- **file:line** : `app/Services/FirebaseService.php:56-66`
- **correction** : Log per-token failures at warning level with the FCM error code; on UNREGISTERED/INVALID_ARGUMENT responses, null out the offending web_token/device_token so they are not retried. Optionally return a delivered/failed count to the UI.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S13-07 [P2] Coupon limit_per_user and max_uses_global enforced via non-atomic count-then-create (TOCTOU) — concurrent redemptions can exceed the cap
- **report/micro** : SYSTEMS / S13-marketing · **category** : logic · **tags** : —
- **file:line** : `app/Services/CouponService.php:437-459 ; app/Services/OrderService.php:589-595`
- **correction** : Add a UNIQUE(user_id, coupon_id) DB constraint (or a redemption ledger with unique key) for single-use-per-user, and wrap the global-cap check + insert in a locked transaction (Cache::lock or SELECT FOR UPDATE) mirroring the fiscal-sequence triple-defense pattern.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S14-KDS-01 [P2] KDS sous compte admin (branch_id=0) n'a AUCUN push temps-reel : nouvelle commande visible jusqu'a 60s plus tard, sans aucun indicateur
- **report/micro** : SYSTEMS / S14-kds · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1911-1914 + :1889-1896 + resources/js/services/kdsSyncService.js:301-302 + resources/js/services/WebSocketService.js:79-111`
- **correction** : Pour un admin mono-branche, soit (a) s'abonner au canal Echo de la branche reelle (resoudre la branche unique cote front via foodkingConfig.branchCount===1 -> branch.1) au lieu du early-return, soit (b) ne PAS basculer le polling a 60s / ne PAS stopper kdsSyncService tant qu'aucun abonnement Echo KDS actif n'existe (garder 5s). Idealement: decoupler wsConnected (socket) de 'pushKdsActif' (abonnement au canal de la commande). A minima documenter que la cuisine DOIT utiliser chef@lecayenne.fr (branch_id=1) et afficher la banniere polling meme en mono-branche.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S15-oss-02 [P2] First load fires a flash+chime storm: every already-PREPARED order is treated as newly-ready on mount
- **report/micro** : SYSTEMS / S15-oss · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:385-402 (_hydrateFromRows) called from list() at :403-415 on mount :114`
- **correction** : Add a one-shot guard: on the first _hydrateFromRows after mount, seed prevPreparedIds from the incoming rows (or set a this._hydratedOnce flag) so _markNewReady only fires for transitions observed AFTER initial load.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S17-02 [P2] Admin dining-tables & table-orders pages are hidden from the sidebar but fully reachable by direct URL with no dine-in feature check
- **report/micro** : SYSTEMS / S17-dinein-tables · **category** : logic · **tags** : —
- **file:line** : `resources/js/config/v1-hidden-modules.js:20-22, resources/js/router/modules/diningTableRoutes.js:24-35, resources/js/router/modules/adminTableOrderRoutes.js:24-39`
- **correction** : Add a router beforeEnter guard (or a top-level v-if on a 'dine-in disabled' notice) on diningTableRoutes + adminTableOrderRoutes that redirects to dashboard when the frontend settings store pos_dine_in_enabled is falsy — reuse the exact dineInEnabledFrom() helper already unit-tested in tests/js/posDineInFlag.spec.js. Keeps code/routes intact (V2 re-enable) while making the V1 'off' state honest.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S1-DASH-04 [P3] ChannelStats card Repartition par Canal (Aujourdhui) buckets do not partition the order set -> the three percentages can sum to >100 or <100
- **report/micro** : SYSTEMS / S1-dashboard · **category** : logic · **tags** : —
- **file:line** : `app/Services/DashboardService.php:520-543 (channelStatistics bucketing)`
- **correction** : Make the buckets a true partition: classify each order exactly once (kiosk first, then POS only if not kiosk, then web) -- reuse the single-pass bucketChannels logic already correct in eodSynthesis.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit re-rating P1->P3: the Repartition-par-Canal buckets provably do not partition (POS double-counts a kiosk-tagged source=POS row; an order in none of WEB/APP/POS/kiosk counts in none) — REAL, but it is a read-only dashboard percentage with no money/fiscal/blocking impact.

#### S1-DASH-06 [P3] customerStates fires 18 separate queries per load and hydrates full Order models just to count (->get()->count())
- **report/micro** : SYSTEMS / S1-dashboard · **category** : perf · **tags** : —
- **file:line** : `app/Services/DashboardService.php:301-316`
- **correction** : Replace ->get()->count() with ->count() (single COUNT(*) per bucket), or better collapse all 18 buckets into one GROUP BY hour query so the whole widget is a single round-trip.
- **validation** : Before/after measurement of the named metric (query count / block_for ms / payload size) via a timing test or live trace; assert improvement + no functional regression.

#### S2-07 [P3] Online-orders list clear() resets filters to a state that differs from the initial mount filters (excepts and exceptSource drift), so 'Effacer' does not restore the pristine view
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue:277-278 (mount: excepts=DINING_TABLE, exceptSource=POS) vs :345-356 (clear(): excepts=POS|DINING_TABLE, exceptSource left untouched, never reset)`
- **correction** : Make clear() reset to the exact mount defaults: excepts = orderTypeEnum.DINING_TABLE and re-assert exceptSource = SourceEnum.POS (or pick ONE consistent exclusion signal). Align mount + clear so 'Effacer' restores the pristine view.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S3-L1 [P3] Item update with only NEW variations orphans the old ones (variations diff never runs when no id present)
- **report/micro** : SYSTEMS / S3-items-catalog · **category** : logic · **tags** : —
- **file:line** : `app/Services/ItemService.php:314-349 (guard at 338)`
- **correction** : Mirror the extras diff pattern: collect ALL surviving ids (both updated-with-id AND newly-created ids) into $variationIdsArray, then unconditionally delete `whereNotIn('item_id'=$item->id, 'id', $variationIdsArray)`. Drop the `if ($variationIdsArray)` gate so an all-new payload still removes the old rows.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S3-L3 [P3] ComposerProfileProjection emits inactive steps (no is_active filter) — defense-in-depth only; every live caller pre-filters
- **report/micro** : SYSTEMS / S3-items-catalog · **category** : logic · **tags** : —
- **file:line** : `app/Services/Composer/ComposerProfileProjection.php:29-48`
- **correction** : Add `->reject(fn($s)=>!$s->is_active)` (or `->where('is_active', ...)`) inside project() at line 29-30 so the projection is self-consistent regardless of how the caller loaded the relation; keep the caller-side filters as a fast path.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S3-F1 [P3] Catalog Studio quick-create hardcodes item_type = VEG for every product (chicken/beef saved as vegetarian)
- **report/micro** : SYSTEMS / S3-items-catalog · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/items/CatalogStudioComponent.vue:488`
- **correction** : Either expose a veg/non-veg toggle in the quick form, or default to NON_VEG (safer for a meat-forward menu), or drop item_type from the quick payload and let the backend default apply. Best: align with the menu reality and remove the silent VEG assumption.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S3-X1 [P3] Item update only patches variation name+price for existing rows — item_attribute_id / visible_on silently ignored
- **report/micro** : SYSTEMS / S3-items-catalog · **category** : logic · **tags** : —
- **file:line** : `app/Services/ItemService.php:326-329`
- **correction** : Update the full set of editable variation columns for id-bearing rows (mirror the create payload), or document/enforce that the blob path only accepts name+price.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S4-05 [P3] Dashboard extra/variation toggle is non-atomic: a partial fan-out failure rolls back the UI flip but leaves some extra_ids already persisted out-of-stock in the DB
- **report/micro** : SYSTEMS / S4-stock-ingredients · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:604-623 (sendBulkToggle) + 567-575 (catch rollback)`
- **correction** : Either make the toggle truly atomic server-side (one endpoint accepting extra_ids[] wrapped in a DB transaction) or, on partial failure, fire compensating re-toggles for the ids that already succeeded before reverting the UI, and surface a precise error. At minimum correct the misleading 'all-or-nothing' docstring.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S4-06 [P3] Dashboard sends reason 'out_of_stock_manual' but stock_levels.unavailable_reason column is 64-char and downstream reasons are inconsistent ('out_of_stock' vs 'out_of_stock_manual' vs 'stock_rupture') — no canonical reason vocabulary
- **report/micro** : SYSTEMS / S4-stock-ingredients · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue:248 (DEFAULT_REASON='out_of_stock_manual'); app/Services/Menu/AvailabilityService.php:141 ('out_of_stock') + 742 ('released_after_cancel_or_refund'); app/Console/Commands/StockScanRupture.php:130 ('stock_rupture'); app/Http/Controllers/Admin/StockRuptureDashboardController.php:59 (filters where unavailable_reason='stock_rupture')`
- **correction** : Introduce a single UnavailableReason enum/const shared by JS (i18n-mapped) and PHP; normalize the dashboard manual reason and the lastSummary filter to the same canonical set, or have lastSummary include all manual/auto reasons rather than only 'stock_rupture'.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S5-03 [P3] Cash session report daily totals (opening/closing/transactions) are computed per-page, so a day split across pages shows partial daily totals
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue:166-188 (groupedByDay) + :194-201 (per_page:50, server pagination)`
- **correction** : Either return server-computed per-day aggregates in the response meta (grouped server-side, page-independent), or page by whole days rather than by row count. Until then, label the per-day header totals as 'sur cette page' to avoid implying a true daily total.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S6-04 [P3] Category drag-sort assigns sort=index+1 from the visible page only — collides across paginated pages
- **report/micro** : SYSTEMS / S6-settings-catalog · **category** : logic · **tags** : —
- **file:line** : `resources/js/components/admin/settings/ItemCategory/ItemCateogryListComponent.vue:238-248 ; app/Services/ItemCategoryService.php:211-235`
- **correction** : Compute the global base offset (e.g. (page-1)*per_page) on the client, or send the full ordered id list, or have the backend reorder using a contiguous renumber across all rows rather than 1-based per submitted batch.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S6-05 [P3] Category Excel import bypasses the CategoryCreated event fan-out — imported categories may not sync to POS/kiosk
- **report/micro** : SYSTEMS / S6-settings-catalog · **category** : logic · **tags** : —
- **file:line** : `app/Imports/ItemCategoryImport.php:23-29 ; app/Services/ItemCategoryService.php:118-120`
- **correction** : Route the import through ItemCategoryService::store() per row (or dispatch CategoryCreated in a WithEvents/afterImport hook) so both creation paths share the same event fan-out and column defaults.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S8-05 [P3] KioskMachine changeStatus passe par Request brut (pas KioskMachineRequest) — status non borné/non typé peut être écrit
- **report/micro** : SYSTEMS / S8-settings-payments · **category** : functional · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/KioskMachineController.php:82-92 (changeStatus(KioskMachine, Request)) + app/Services/KioskMachineService.php:145-167`
- **correction** : Valider status dans changeStatus via un FormRequest dédié ou un Rule::in([Status::ACTIVE, Status::INACTIVE]) inline avant update. Symétrique au reste du module.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S9-03 [P3] LanguageController::fileText guards with `$explodeName > 0` (array compared to int) — always-true, dead/incorrect branch guard
- **report/micro** : SYSTEMS / S9-settings-system · **category** : logic · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/LanguageController.php:89`
- **correction** : Replace `if ($explodeName > 0)` with `if (count($explodeName) > 1)` (or `isset($explodeName[1])`) in both LanguageController.php:90 and LanguageService.php:253.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S10-04 [P3] Subscriber broadcast email sent synchronously to all subscribers in one BCC, no queue/chunk
- **report/micro** : SYSTEMS / S10-customers · **category** : perf · **tags** : —
- **file:line** : `app/Services/SubscriberService.php:110-123`
- **correction** : Queue the mail (implement ShouldQueue on SubscriberMail or dispatch a job) and chunk recipients (e.g. Subscriber::chunk(200) → queued Mail::to() per recipient), so the controller returns immediately and one bad address doesn't fail the whole batch.
- **validation** : Before/after measurement of the named metric (query count / block_for ms / payload size) via a timing test or live trace; assert improvement + no functional regression.

#### S11-01 [P3] AdministratorService::update() missing target/self/super-admin guards present on every sibling method — id=1 super-admin protection bypassable via update
- **report/micro** : SYSTEMS / S11-staff-rbac · **category** : logic · **tags** : —
- **file:line** : `app/Services/AdministratorService.php:87-110`
- **correction** : Add the same guard destroy() uses at the top of update(): assert `$administrator->hasRole(EnumRole::ADMIN)` (reject mutating non-admins through this endpoint) and `$administrator->id != 1` / self-id checks consistent with destroy(), mirroring the WAVE5-SEC-001 assertTargetRole pattern used in WaiterService/ChefService. Place the assertion BEFORE the try/catch so it surfaces as 403 rather than being rewritten to 422 (per the existing WAVE5-SEC-001 rationale comments).
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit re-rating P1->P3: the AdministratorService::update() missing target/self/super-admin guard asymmetry is REAL (siblings carry it) but is defense-in-depth/code-quality, not a demonstrated privilege-escalation exploit in V1 single-admin. Keep the recommended guard.

#### S11-04 [P3] Employee role dropdown filters by hardcoded integer role IDs '1|2|3|4|5' while the backend was deliberately healed OFF integer IDs onto role NAMES — stale on fresh seeds
- **report/micro** : SYSTEMS / S11-staff-rbac · **category** : logic · **tags** : NEEDS-LIVE-VERIFY
- **file:line** : `resources/js/components/admin/employees/EmployeeCreateComponent.vue:227`
- **correction** : Filter the role list by NAME (exclude 'Admin','Customer','Delivery Boy','Waiter','Chef') or expose a backend-driven assignable-roles endpoint, consistent with the role-NAME identity the backend services already adopted. Keep EmployeeService::blockRoles as the authoritative backstop.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S11-07 [P3] EmployeeCreateComponent.save catch reads err.response.data.errors unconditionally — 422 responses that return {status,message} (not {errors}) leave the form with no error feedback
- **report/micro** : SYSTEMS / S11-staff-rbac · **category** : functional · **tags** : NEEDS-LIVE-VERIFY
- **file:line** : `resources/js/components/admin/employees/EmployeeCreateComponent.vue:307`
- **correction** : In the catch, fall back to alertService.error(err.response?.data?.message) when err.response.data.errors is absent (the AdministratorList destroy() already uses alertService.error(err.response.data.message) for this exact shape). Guard against undefined response.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

#### S12-01 [P3] Tautological role guard !in_array(DELIVERY_BOY, blockRoles) is always true — dead else-branches across 5 methods
- **report/micro** : SYSTEMS / S12-delivery · **category** : logic · **tags** : —
- **file:line** : `app/Services/DeliveryBoyService.php:28,103,134,152,202,224`
- **correction** : Delete the `if (!in_array(EnumRole::DELIVERY_BOY,$this->blockRoles))` wrappers and their unreachable else-branches; rely on assertTargetRole() (already the genuine guard). If a future need is to block self-mutation of Admins, compare the TARGET user's role, not the DELIVERY_BOY constant.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S12-05 [P3] selectDeliveryBoy has no order-status gate and no target-status check — a DELIVERED order can be reassigned to another driver
- **report/micro** : SYSTEMS / S12-delivery · **category** : logic · **tags** : —
- **file:line** : `app/Services/OrderService.php:2535-2581`
- **correction** : Reject (422) reassignment when order is in a terminal status (DELIVERED/RETURNED/CANCELLED) and reject assignment to a target whose status is inactive.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S13-06 [P3] Coupon::isUsableNow() checks max_uses_global against the dead usage_count column (always 0) — global cap unenforced for any caller relying on the model alone
- **report/micro** : SYSTEMS / S13-marketing · **category** : logic · **tags** : —
- **file:line** : `app/Models/Coupon.php:150-155 ; app/Services/CouponService.php:236,449-459`
- **correction** : Either remove the usage_count branch from isUsableNow() and make it count OrderCoupon (matching CouponService), or actually increment usage_count atomically on redemption. Drop the dead column if unused.
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.
- **self-audit note** : Self-audit re-rating P2->P3: Coupon::isUsableNow() max_uses_global check is a latent trap / dead path (every live caller pre-checks); frame as deadcode, not an active over-redemption hole.

#### S15-oss-01 [P3] POS comptoir orders never reach the PRET wall though they carry a queue number and are prepared in the kitchen
- **report/micro** : SYSTEMS / S15-oss · **category** : functional · **tags** : —
- **file:line** : `app/Services/OrderStatusScreenOrderService.php:59-62 and :220-223 (whereIn order_type [KIOSK, TAKEAWAY])`
- **correction** : Confirm the pickup model with the owner. If the wall is the universal pickup signal, add OrderType::POS to the allowlist in BOTH list() (line 59-62) and listForBranch() (line 220-223), keeping them byte-identical per the service docstring. If counter orders are hand-delivered by design, leave as-is but document the rationale next to the allowlist so future audits don't re-flag it.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.
- **self-audit note** : Self-audit re-rating P2->P3 / design-decision: OSS list restricts to order_type [KIOSK, TAKEAWAY] by design (same allowlist the POS box audit flagged), so POS-counter orders not reaching the PRET wall may be intentional. OWNER to confirm whether counter orders should appear on the customer screen.

#### S15-oss-03 [P3] Stale-prune window (now-8h) silently drops still-active overdue advance orders, contradicting the documented anti-zombie intent
- **report/micro** : SYSTEMS / S15-oss · **category** : logic · **tags** : —
- **file:line** : `app/Services/OrderStatusScreenOrderService.php:98-101 vs :132 (and mirror :243-245 vs :256)`
- **correction** : Decide one model and make the code match the comment. Either exempt active advance orders from the stale_window prune (move line 132 to apply only to the non-advance branch), or update the [AUDIT-52-BUG1] comment to state advance orders are also capped at stale_window_hours. Mirror in listForBranch().
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### SLV-04 [P3] KDS shows 49 active orders vs OSS shows 1 'En preparation' — verify the filter difference is intentional (live vs stale backlog), not a sync gap
- **report/micro** : SYSTEMS / LIVE-visual · **category** : logic · **tags** : NEEDS-LIVE-VERIFY
- **file:line** : `KDS vs OSS list queries (kitchenDisplaySystem vs orderStatusScreen controllers)`
- **correction** : (see source report)
- **validation** : PHPUnit Feature test on a real DB order/row asserting the corrected branch + (if frontend) Vitest unit on the component method; live Playwright reproduction of the exact click-path proving the fixed result.

#### S8-03-reinstated [P3] Kiosk-machine edit prefills a password from a nonexistent field (re-instated wrongly-dropped)
- **report/micro** : SYSTEMS / SELF-AUDIT-ADD · **category** : functional · **tags** : —
- **file:line** : `resources/js/components/admin/settings/KioskMachine/KioskMachineListComponent.vue:185 (edit() :176-190)`
- **correction** : Bind the edit form to the real machine fields returned by the API; verify against KioskMachineResource.
- **validation** : Playwright E2E that reproduces the broken click-path and asserts the corrected end-state (button reaches backend / route resolves / state transitions); PHPUnit Feature on the controller action.

### W6-ux-visual

#### M4-04 [P2] Deux formats de monnaie FR incoherents sur le MEME ecran POS : '12.50EUR' (point, sans espace) pour le panier vs '12,50 EUR' (virgule + espace) pour les chips raccourcis/commandes
- **report/micro** : BOX / M4-cart-ticket · **category** : visual · **tags** : —
- **file:line** : `resources/js/services/appService.js:71-77 (currencyFormat = parseFloat(amount).toFixed(decimal) + currency) ; resources/js/components/admin/pos/PosComponent.vue:3346-3348 (formatKioskPrice = Intl.NumberFormat('fr-FR',{currency:'EUR'})) ; usages panier :835, :954-974, :989 (currencyFormat) vs chips :296, :360, :1200 (formatKioskPrice)`
- **correction** : Aligner currencyFormat sur le format FR (virgule decimale + espace insecable avant le symbole), idealement en deleguant a Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}) comme formatKioskPrice, pour une source unique de formatage monetaire dans tout le POS. Verifier que la borne/kiosk et le recu restent coherents.
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### M7-04 [P2] Discard deletes a parked ticket on a single tap with no confirmation, sitting right next to Restore
- **report/micro** : BOX / M7-parked · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/ParkedOrdersComponent.vue:84-91 & 204-215`
- **correction** : Add a confirmation (lightweight inline 'confirm?' two-step, or a confirm dialog) before dispatching discard, and/or visually separate the destructive action from Restore. Optionally soft-delete to allow undo.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### LV-01 [P2] Two distinct sandwich categories both render as truncated 'Sandwich…'
- **report/micro** : BOX / LIVE-visual · **category** : visual · **tags** : live-verified
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue (category pill) + .pos-v4-category-pill CSS`
- **correction** : (see source report)
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### LV-03 [P2] Currency format inconsistent WITHIN the item wizard (non-FR)
- **report/micro** : BOX / LIVE-visual · **category** : visual · **tags** : FROZEN-HARD (public/js/pos-wizard.js), live-verified
- **file:line** : `public/js/pos-wizard.js (FROZEN)`
- **correction** : (see source report)
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.
- **self-audit note** : Self-audit: re-categorised logic->visual; shares the same non-FR fmtPrice root as M3-05 (cosmetic FR-locale violation on the frozen pos-wizard.js surface).

#### M1-07 [P3] Manual 'Synchroniser' flush gives no feedback when nothing actually synced (0 synced / 0 failed produces silence)
- **report/micro** : BOX / M1-shell-auth-drawer · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3916-3930 (tryManualFlush)`
- **correction** : Add an else branch: when !skipped and synced===0 && failed===0, alertService.info('File deja synchronisee.'). Optionally toast on skipped (flush already in progress).
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M2-06 [P3] Product tile image has no @error fallback — a 404/broken thumb shows a broken-image glyph, not the 🍴 fallback (which only covers a null thumb)
- **report/micro** : BOX / M2-catalog-nav · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/ItemComponent.vue:25-30`
- **correction** : Add @error="$event.target.style.display='none'" (or a data flag that flips to the 🍴 fallback) on the tile <img>, mirroring a standard image-fallback pattern. Same applies to the cart-line image (PosComponent.vue:776). Scope-minimal, no logic change.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M3-05 [P3] Format monétaire non-FR dans tout l'aperçu wizard : « €3.00 » au lieu de « 3,00 € » (symbole avant + point décimal)
- **report/micro** : BOX / M3-item-wizard · **category** : visual · **tags** : FROZEN-HARD (public/js/pos-wizard.js)
- **file:line** : `public/js/pos-wizard.js:218-221 (fmtPrice) + 666 ('€'+toFixed(2)) ; utilisé partout (badges sauce, formule, frites, recap, total sticky)`
- **correction** : FROZEN : décrire. fmtPrice devrait déléguer à appService.currencyFormat (decimal=',', position=after) ou formater 'N,NN €'. Aligner sur la locale FR. Gate owner (frozen file).
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### M4-05 [P3] Bouton Appliquer actif avec input remise vide -> stocke la chaine '0.00' que v-if=posDiscount traite comme truthy -> ligne fantome 'Remise -0,00 EUR'
- **report/micro** : BOX / M4-cart-ticket · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:1889-1892 (isDiscountApplyable retourne true si amount<=0) + :3687-3717 (applyDiscount stocke toFixed sans garde >0) + :956-961 (v-if=posDiscount) ; store posCart.js:520-523 (discount mutation stocke la valeur brute)`
- **correction** : Dans applyDiscount, court-circuiter si `discountAmountValue <= 0` en dispatchant explicitement discount=0 (Number, pas la chaine '0.00'), et/ou changer le v-if en `v-if="Number(posDiscount) > 0"` pour ne jamais afficher une ligne remise nulle.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M7-03 [P3] Park label captured via blocking window.prompt() — unusable on a keyboard-less touchscreen POS
- **report/micro** : BOX / M7-parked · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3449-3454`
- **correction** : Replace window.prompt with an in-app modal/input wired to the existing POS on-screen keyboard component, with the label optional and a clear Park/Cancel; keep the empty-cart guard. Scope-limited to PosComponent (not a frozen file).
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M9-03 [P3] Main-page POS loyalty CTA only lights up for the minority non-PAID flows; for the default CASH-via-wizard flow it is permanently hidden
- **report/micro** : BOX / M9-loyalty · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:2465-2483,2491-2493; resources/js/helpers/posLoyaltyMainCta.js:70-92; PaymentComponent.vue:990-994`
- **correction** : Decide the intended model with the owner: either (a) allow capturing the loyalty code BEFORE payment so the discount lands pre-collection (requires wiring into the wizard, frozen - owner gate), or (b) drop the main-page CTA entirely and keep only the PosOrderShowComponent canonical surface to remove the misleading dead entry point. At minimum, the disabled tooltip should explain why (already PAID).
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### M9-05 [P3] Modal reset on open is incomplete: loyaltyCode / pointsToRedeem / customerBalance persist across opens for a different order
- **report/micro** : BOX / M9-loyalty · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:224-243,193-203`
- **correction** : In the watch.open(true) block also reset loyaltyCode='', pointsToRedeem=0, customerBalance=null so every open starts clean.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### LV-02 [P3] Tacos category icon = forbidden/placeholder image; Tacos product tiles grey (missing photos)
- **report/micro** : BOX / LIVE-visual · **category** : visual · **tags** : live-verified
- **file:line** : `category image asset for Tacos + item images`
- **correction** : (see source report)
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.
- **self-audit note** : Self-audit re-scope: the Tacos category + item images EXIST on disk (config/menu_images.php maps cat-tacos.png / tacos.png, files present). The grey/placeholder render is a DB items.thumb mapping mismatch on THIS instance (owner data-ops), made visible by the missing @error fallback (M2-06) — not a code defect.

#### LV-04 [P3] Sauce step 'Premiere gratuite' but additional-sauce surcharge not shown on pills
- **report/micro** : BOX / LIVE-visual · **category** : ux · **tags** : FROZEN-HARD (public/js/pos-wizard.js), live-verified
- **file:line** : `public/js/pos-wizard.js (FROZEN) — sauce step`
- **correction** : (see source report)
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.
- **self-audit note** : Self-audit reword: the per-sauce surcharge ('+0,50 €') IS rendered once a sauce is selected (pos-wizard.js:1233-1240); it is absent only in the pristine empty-sauce state. Minor P3 UX nuance, not a 'surcharge never shown' defect.

#### LV-05 [P3] Viande '0/1' requirement badge + per-viande quantity steppers is ambiguous
- **report/micro** : BOX / LIVE-visual · **category** : ux · **tags** : FROZEN-HARD (public/js/pos-wizard.js), live-verified
- **file:line** : `public/js/pos-wizard.js (FROZEN) — viande step`
- **correction** : (see source report)
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.
- **self-audit note** : Self-audit: this is a UX/clarity opinion (the 0/1 badge + per-viande stepper + supplement expander coexist and are functional), not a functional defect.

#### LV-06 [P3] Landing prioritises 'A encaisser borne (200)' list; product grid is a thin bottom strip
- **report/micro** : BOX / LIVE-visual · **category** : ux · **tags** : live-verified
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue (POS landing layout)`
- **correction** : (see source report)
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S1-DASH-02 [P2] Two cards both labelled Total des ventes show different scopes (all-time net vs selected-period net) with no scope hint
- **report/micro** : SYSTEMS / S1-dashboard · **category** : ux · **tags** : —
- **file:line** : `OverviewComponent.vue:12-13 + app/Services/DashboardService.php:347-356 (totalSales, all-time) vs SalesSummaryComponent.vue:24-26 + app/Services/DashboardService.php:228-234 (salesSummary, date-ranged)`
- **correction** : Relabel: Overview tile -> CA total / Total des ventes (cumul); SalesSummary figure -> CA de la periode (or show the active range under it). No backend change; add distinct i18n keys.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S1-DASH-05 [P2] All four date-filtered widgets swallow API errors (.catch only flips loading=false) -> no empty/error state, failures invisible; RealtimeReport has no .catch at all
- **report/micro** : SYSTEMS / S1-dashboard · **category** : ux · **tags** : —
- **file:line** : `SalesSummaryComponent.vue:126-128 + OrderSummaryComponent.vue:155-157 + OrderStatisticsComponent.vue:206-208,230-232 + CustomerStatsComponent.vue:128-130 + RealtimeReportComponent.vue:45-49`
- **correction** : Add a per-widget error flag (set in .catch) rendering Donnees indisponibles, reessayer, and reset displayed KPI values on failure so stale numbers are not presented as live. Add a .catch to RealtimeReportComponent.fetchData.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S5-02 [P2] Sales report mixes a placed-orders count card next to three realized-net money cards with no label distinguishing the two definitions
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : ux · **tags** : —
- **file:line** : `app/Services/OrderService.php:2796-2800 + resources/js/components/admin/salesReport/SalesReportListComponent.vue:127-170`
- **correction** : Add a clarifying sub-label/tooltip: total_orders = 'commandes passées', and group the three money cards under an 'Encaissé net (concorde avec le Z)' heading. Optionally add a PAID/status badge column to the table so it visually reconciles to the cards. Do NOT change the sums (owner-healed SALES-NET-01).
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S5-05 [P2] Outbox 'Retry failed' button is enabled by pending count, but the backend only retries rows that already errored — healthy pending events are silently ignored
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/observability/OutboxOverviewComponent.vue:60-66 (:disabled) + app/Http/Controllers/Admin/Observability/SyncOverviewController.php:403-409 (outboxRetryFailed query)`
- **correction** : Gate the button on a count that matches the backend predicate (events with last_error set), e.g. expose pending_with_error in the overview payload and disable on (that===0 && failedJobs.count===0); or surface the requeued count in a toast so a 0-requeue click is explained.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S5-06 [P2] Sales report Excel/PDF export the full filtered set with no row cap, while the screen paginates — large exports can be heavy and silently differ in size from what the user sees
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : ux · **tags** : —
- **file:line** : `app/Services/OrderService.php:129-130 + app/Exports/SalesReportExport.php:26-41 + resources/js/components/admin/salesReport/SalesReportListComponent.vue:494-523`
- **correction** : Mirror ItemsReportExport: $this->request->merge(['paginate'=>0]) inside SalesReportExport::collection and SalesReportController::pdf so both reports' exports are deterministically the full filtered dataset.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.
- **self-audit note** : Self-audit re-rating P1->P2: two reviewers flagged the export-pagination framing as imprecise (over-export vs current-page-only) and non-blocking; the concrete verified instance is the items PDF truncation (GAP-items-pdf). Downgraded pending a direct read of SalesReportExport vs the paginate=0 merge.

#### S2-05 [P3] Online-orders list 'Avance' badge is styled with the order-status colour helper fed a boolean enum, so it renders as a RED (cancelled/error-coloured) pill instead of an informative tag
- **report/micro** : SYSTEMS / S2-orders-mgmt · **category** : visual · **tags** : —
- **file:line** : `resources/js/components/admin/onlineOrders/OnlineOrderListComponent.vue:127-130 (:class="orderStatusClass(order.is_advance_order)") ; helper resources/js/services/appService.js orderStatusClass() else-branch -> red ; isAdvanceOrderEnum.YES = 5`
- **correction** : Use a dedicated neutral/info class for the advance marker (e.g. an amber/blue 'Avance' chip) rather than passing a boolean enum to orderStatusClass(). The status pill itself already uses orderStatusClass(order.status) correctly on the adjacent span.
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### S5-07 [P3] Credit balance report offers Print + Excel but no PDF, inconsistent with sales/items reports (which expose PDF) — and the on-screen balance has no total row
- **report/micro** : SYSTEMS / S5-reports-fiscal · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/creditBalanceReport/CreditBalanceReportComponent.vue:19-21 (no PdfComponent) + app/Http/Controllers/Admin/CreditBalanceReportController.php:53-60 (export only, no pdf method)`
- **correction** : Add a total-outstanding-credit footer row (sum of balances, ideally server-provided like S5-01) and either add a PDF export for parity or document why credit-balance is Excel-only.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S8-06 [P3] Liste des bornes kiosk sans état vide (pas de v-else / no_data) — table fantôme si aucune borne
- **report/micro** : SYSTEMS / S8-settings-payments · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/settings/KioskMachine/KioskMachineListComponent.vue:31 (tbody v-if kioskMachines.length>0 sans v-else)`
- **correction** : Ajouter un `<tbody v-else>` avec une ligne colspan=6 affichant {{ $t('label.no_data') }}, en miroir de PaymentTerminalsComponent.vue:57-64.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S12-07 [P3] Delivery cash-session list route requires full 'delivery-boys' mutation permission; backend index only needs read permission
- **report/micro** : SYSTEMS / S12-delivery · **category** : ux · **tags** : —
- **file:line** : `resources/js/router/modules/deliveryBoyCashSessionRoutes.js:24`
- **correction** : Set the list/show route permissionUrl to 'delivery-boys_show' (or the read variant) to match the backend read gate; keep mutation buttons gated by the mutation permission.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S13-08 [P3] Coupon active/inactive toggle has no confirmation — one click silently activates/deactivates a live promo
- **report/micro** : SYSTEMS / S13-marketing · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/coupons/CouponListComponent.vue:182-188,382-398`
- **correction** : Add a lightweight confirm (or an undo toast) on toggleStatus, especially when deactivating a currently-active coupon.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S14-KDS-02 [P3] Conflit 409 sur le board V2 (defaut prod) affiche une cle i18n inexistante au lieu du message d'avertissement
- **report/micro** : SYSTEMS / S14-kds · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1646`
- **correction** : Ligne 1646: remplacer `this.$t('label.kds_status_conflict')` par `this.$t('message.kds_status_conflict')` (cle existante, deja utilisee par le chemin legacy 2280).
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.
- **self-audit note** : Self-audit reasoning fix: this is a raw missing-i18n label (KitchenDisplaySystemComponent.vue:1646 this.$t('label....')), NOT the 409-conflict path. Keep P3.

#### S14-KDS-04 [P3] Board V2 limite a 8 cartes (slice 0,8) + raccourcis clavier A-H seulement : commandes 9+ visibles uniquement via une puce de comptage, non actionnables tant qu'une des 8 ne sort pas
- **report/micro** : SYSTEMS / S14-kds · **category** : ux · **tags** : —
- **file:line** : `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:55-56 + :247-249 + :291-307`
- **correction** : Permettre l'acces aux commandes 9+ : soit scroll vertical du grid avec densite reduite, soit pagination/'voir plus' depliant les cartes suivantes, soit etendre les raccourcis au-dela de H. La puce overflow peut rester comme filet de securite mais ne doit pas etre le seul acces aux commandes en surplus.
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### S16-05 [P3] Kiosk login/diagnostic screen uses a dark navy gradient, breaking the V1 kiosk light-mode mandate
- **report/micro** : SYSTEMS / S16-kiosk-borne · **category** : visual · **tags** : —
- **file:line** : `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:148-155,279-294`
- **correction** : Restyle the login/diagnostic screen to the kiosk light tokens (white surface, var(--kiosk-primary) #F4501E accents, dark text) to match idle/cart/payment.
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### SLV-01 [P3] OSS (customer-facing 'Suivi client') shows admin header chrome until fullscreen
- **report/micro** : SYSTEMS / LIVE-visual · **category** : ux · **tags** : live-verified
- **file:line** : `resources/js/components/admin/orderStatusScreen/* (page header) ; route admin.order-status-screen`
- **correction** : (see source report)
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

#### SLV-02 [P3] KDS order timer shows raw minutes for old orders (e.g. '926:5x') with no hour formatting
- **report/micro** : SYSTEMS / LIVE-visual · **category** : visual · **tags** : live-verified
- **file:line** : `resources/js/components/admin/kitchenDisplaySystem/* (timer render)`
- **correction** : (see source report)
- **validation** : CLAUDE.md §6 visual gate: Playwright capture of the affected surface → Read screenshot → analyze (no raw label / layout intact / branding / i18n). Re-capture after fix; two consecutive clean captures.

#### SLV-03 [P3] KDS caps the visible list (49 shown, '+41 en attente / Liste pleine') and asks the chef to filter
- **report/micro** : SYSTEMS / LIVE-visual · **category** : ux · **tags** : live-verified
- **file:line** : `resources/js/components/admin/kitchenDisplaySystem/* + KDS list endpoint (server cap)`
- **correction** : (see source report)
- **validation** : Playwright capture + screenshot analysis; if copy/i18n, assert the resolved key in fr.json (no `Label.x`/`0undefined`). Owner sign-off on wording where subjective.

### W7-deadcode

#### M4-06 [P3] Methodes panier mortes : cartQuantityUp(id,e) et deleteCartItem(id) ne sont referencees nulle part dans le template (le design V5 utilise cartQuantityIncrement/Decrement + cancelLastCartLine)
- **report/micro** : BOX / M4-cart-ticket · **category** : deadcode · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:3627-3633 (cartQuantityUp) + :3641-3643 (deleteCartItem)`
- **correction** : Supprimer cartQuantityUp et deleteCartItem du composant (ou confirmer via test qu'aucun ref externe ne les appelle puis retirer). Scope-minimal, hors frozen-zone.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### M5-02 [P3] Cluster de code livraison-adresse legacy orphelin coexiste avec le nouveau formulaire inline (modale adresse + selecteur jamais déclenchés)
- **report/micro** : BOX / M5-customer-ordertype · **category** : deadcode · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:4099-4158, 2143-2147`
- **correction** : Supprimer updateSelectedAddress/openAddressModal/editAddressModal/filteredCustomerAddresses/customerAddresses et le montage de CreateCustomerAddressComponent du POS s'ils sont confirmés inutilisés (vérifier d'abord qu'aucun raccourci clavier / event externe ne les appelle). Conserver uniquement le flux inline. Réduit la surface et le risque de double-source address_id.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### M5-05 [P3] Les méthodes de switch order-type ajoutent/retirent une classe 'active' inexistante en CSS (le surlignage réel vient de :class is-active)
- **report/micro** : BOX / M5-customer-ordertype · **category** : deadcode · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosComponent.vue:4015-4048`
- **correction** : Retirer toutes les lignes classList.add/remove('active') des trois méthodes. Conserver uniquement le toggle block/hidden des div inline (load-bearing) et laisser :class is-active gérer le visuel. Idéalement migrer aussi l'affichage des div inline vers v-show piloté par order_type pour supprimer la manip DOM impérative restante.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### M9-04 [P3] Success band is dead code: parent closes the modal on 'applied' before the success message can render
- **report/micro** : BOX / M9-loyalty · **category** : deadcode · **tags** : —
- **file:line** : `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:42-49,312-316; PosOrderShowComponent.vue:626-627; PosComponent.vue:2506-2507`
- **correction** : Either keep the modal open briefly to show successMessage (delay the emit/close by ~1s), or delete the success band + successMessage state as dead code and rely on the parent's post-refresh feedback. Decide one; do not keep both.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.
- **self-audit note** : Self-audit: correct citation to resources/js/components/admin/posOrders/PosOrderShowComponent.vue:626-627. Deadcode P3 stands.

#### S3-D1 [P3] Dead env('DEMO') branch in ItemController::store — both arms identical
- **report/micro** : SYSTEMS / S3-items-catalog · **category** : deadcode · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/ItemController.php:137-141`
- **correction** : Replace the whole if/else with a single `return new ItemResource($this->itemService->store($request));`.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### S4-03 [P3] Entire daily-quota auto-86 subsystem is unreachable in V1: max_daily_qty is never set by any UI, seeder, or factory, so setMaxDailyQty endpoint + decrementForOrder auto-86 + releaseForOrderItems auto-restore are all dormant
- **report/micro** : SYSTEMS / S4-stock-ingredients · **category** : deadcode · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/AvailabilityController.php:111-147 (setMaxDailyQty); app/Services/Menu/AvailabilityService.php:99-166 + 286-381 + 710-750; database/migrations/2026_04_15_230100_create_item_branch_availability_table.php:22; routes/api.php:305-306`
- **correction** : Decide explicitly: either (a) re-expose a per-item daily-cap input in the Stock dashboard so the subsystem is reachable, or (b) mark the quota path as V1.x-deferred and remove/quarantine setMaxDailyQty route + the decrement/restore quota branches to shrink the surface. Do not leave it half-wired. Note: NF525 not affected (daily_consumed_qty is a UX counter, not fiscal).
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### S4-04 [P3] Rupture-scan 'run' endpoint, last-summary endpoint and the whole preventive cron have no UI trigger and the cron ships disabled by default
- **report/micro** : SYSTEMS / S4-stock-ingredients · **category** : deadcode · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/StockRuptureDashboardController.php:207-239 (run) + 50-85 (lastSummary); config/catalog_v15.php:137; app/Console/Kernel.php:253-255; resources/js (no caller)`
- **correction** : If preventive auto-86 is desired for V1, enable the cron (set FK_CATALOG_AUTO_86_CRON_ENABLED=true) and/or add a 'Lancer le scan' button in the dashboard wired to POST /stock/scan-rupture/run with dry-run preview. If not desired for V1, mark run/last-summary/cron as deferred so they are not mistaken for live features.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### S7-06 [P3] SiteController::update has an identical if/else DEMO branch — both arms run the same code
- **report/micro** : SYSTEMS / S7-settings-business · **category** : deadcode · **tags** : —
- **file:line** : `app/Http/Controllers/Admin/SiteController.php:33-37`
- **correction** : Delete the if/else and keep a single line: $resource = new SiteResource($this->siteService->update($request));
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### S15-oss-04 [P3] Entire popular-items chain is dead on the OSS surface and its store getter reads an uninitialized state key
- **report/micro** : SYSTEMS / S15-oss · **category** : deadcode · **tags** : —
- **file:line** : `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue:11-21 (PopularItemComponent un-mounted); store getter orderStatusScreenOrder.js:13-14`
- **correction** : If popular-items is permanently dropped from OSS (owner Wave Q-3 directive), delete admin/orderStatusScreen/PopularItemComponent.vue, the mostPopularItems store action/getter/mutation, controller mostPopularItems/publicMostPopularItems, the 2 routes, and service mostPopularItems. If it may return, at minimum initialize state.mostPopularItems = [] to remove the latent undefined-getter bug and update the stale '4 callers' comment.
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.

#### S15-oss-05 [P3] wsConnected reactive state is maintained but never read by the template or any computed
- **report/micro** : SYSTEMS / S15-oss · **category** : deadcode · **tags** : —
- **file:line** : `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:93 (declared), 222-226 / 244-246 (written), never read`
- **correction** : Either render wsConnected somewhere intentional (a tiny offline dot) or remove the property and the _bindWsService/ws_state writes that exist only to feed it (keep the list() refresh on reconnect, drop the unused flag).
- **validation** : Static: grep proves 0 remaining references to the removed symbol/route; full PHPUnit + Vitest suite green (no regression); frozen-zone diff = 0.
