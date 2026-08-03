# CAISSE V1 Sentinel Index

| # | Sentinel | Type | Cible (file:line) | FK-ID | Mission de fix | Status |
|---|---|---|---|---|---|---|
| 01 | PaymentConfirmAbilitySentinelTest | PHP Feature | app/Http/Controllers/Frontend/OrderController.php:85-96 | FK-009/FK-029 | CV1-M06 | RED |
| 02 | PaymentConfirmCrossBranchSentinelTest | PHP Feature | app/Http/Controllers/Frontend/OrderController.php:85-118 | FK-029/FK-008 | CV1-M06 | RED |
| 03 | PaymentConfirmCashOrderSentinelTest | PHP Feature | app/Http/Controllers/Frontend/OrderController.php:101-118 | FK-029/FK-058 | CV1-M06 | RED |
| 04 | PaymentConfirmConcurrencySentinelTest | PHP Feature | app/Services/FrontendOrderService.php:791 | FK-028/FK-029 | CV1-M04A | RED |
| 05 | OrderStatusNoopSideEffectsSentinelTest | PHP Feature | app/Services/OrderService.php:1505,1568 | FK-016/FK-028 | CV1-M06 | RED |
| 06 | CleanupVsConfirmRaceSentinelTest | PHP Feature | app/Jobs/CleanupStalePendingKioskOrders.php:30-54 | FK-029/FK-044 | CV1-M06 | RED |
| 07 | OrderListBranchExactnessSentinelTest | PHP Feature | app/Services/OrderService.php:151,194,230,267,1920 | FK-008 | CV1-M09 | RED |
| 08 | OrderShowBranchGuardSentinelTest | PHP Feature | routes/api.php:662-663 | FK-033 | CV1-M09 | RED |
| 09 | TransactionBranchExactnessSentinelTest | PHP Feature | app/Services/TransactionService.php:33-35 | FK-008 | CV1-M09 | RED |
| 10 | FiscalZBranchExactnessSentinelTest | PHP Feature | app/Services/Fiscal/ZReportService.php | FK-010/FK-062 | CV1-M08 | RED |
| 11 | OssAdminBranchPolicySentinelTest | PHP Feature | app/Services/OrderStatusScreenOrderService.php:65-67 | FK-033/FK-040 | CV1-M09 | RED |
| 12 | KdsTransitionWhitelistSentinelTest | PHP Feature | app/Http/Requests/OrderStatusRequest.php:45-47 | FK-037 | CV1-M07 | RED |
| 13 | KdsExpectedStatusConflictSentinelTest | PHP Feature | app/Services/KitchenDisplaySystemOrderService.php:117-168 | FK-068 | CV1-M07 | RED |
| 14 | PosCashEndpointSentinelTest | PHP Feature | routes/api.php:809-811 | FK-023/FK-042 | CV1-M06 | RED |
| 15 | PosSubtotalForgerySentinelTest | PHP Feature | app/Http/Requests/PosOrderRequest.php:120-160 | FK-018 | CV1-M06 | RED |
| 16 | QueueNumberUniquenessSentinelTest | PHP Feature | app/Services/OrderService.php:828-854 | FK-020 | CV1-M13 | RED |
| 17 | kioskOfflineIdPrefix.spec.js | Vitest | resources/js/helpers/kioskOfflineQueue.js:135,330 | FK-053 | CV1-M11 | RED |
| 18 | kioskCbTrOfflineRefused.spec.js | Playwright | resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292,393-414 | FK-030/FK-044 | CV1-M11 | RED |
| 19 | lint-fk-enum-status.sh | Shell lint | resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:392 | FK-031/FK-022 | CV1-M11 | RED |
| 20 | lint-fk-branch-isolation.sh | Shell lint | app/Services/OrderService.php + app/Services/FrontendOrderService.php | FK-008 | CV1-M09 | RED |
| 21 | lint-fk-legacy-imports.sh | Shell lint | resources/ legacy imports | FK-011/FK-067/FK-072 | CV1-M12 | GREEN |
| 22 | lint-fk-bundle-legacy.sh | Shell lint | public/build/*.js | FK-077 | CV1-M12 | SKIP |
| 23 | paymentComponentPropMutation.spec.js | Vitest | resources/js/components/admin/pos/PaymentComponent.vue:179-265 | FK-081 | CV1-M21B | RED |
