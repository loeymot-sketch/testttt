# T01 — Rapport divergence worktrees (2026-04-20)

## Verdict : PASS

**Motif.** Les 12 chemins « critiques » ont un verdict explicite (identité, divergence fonctionnelle documentée, ou artefact hors index / copie de travail) ; les écarts les plus graves (fichiers PHP vidés, `phpunit.xml` vide, helpers observabilité non suivis) sont attribués à un **état de copie de travail** et à des **fichiers non versionnés**, pas à une absence de commit inexpliquée.

## Checklist : V1 ✓ V2 ✓ V3 ✓ V4 ✓ V5 ✓ V6 ✓ V7 ✓

## Méthodologie

- `git -C A ls-files` × `git -C B ls-files` → ensembles **A ∖ B**, **B ∖ A**, intersection.
- Fichiers suivis communs : `git hash-object` sur le chemin absolu dans chaque worktree (contenu disque).
- Branches HEAD : **A** = `c00a8cd610ef1729a69423b44970e1ae0d8d4987` (`feat/ton-sujet`), **B** = `0e1c1b2a7da2ffe83947140a1457959e4e9e857b` (`feat/kiosk-phase-9-3`).

## A ∖ B (n=107)

Chemins suivis dans **A** absents de l’index **B** (exhaustif, n≥100).

| Path | Type |
|------|------|
| `app/Console/Commands/FiscalArchiveCommand.php` | php |
| `app/Http/Controllers/Admin/Fiscal/XReportController.php` | php |
| `app/Http/Controllers/Admin/Fiscal/ZReportController.php` | php |
| `app/Jobs/CleanupStalePendingKioskOrders.php` | php |
| `app/Models/AuditLog.php` | php |
| `app/Models/ZReport.php` | php |
| `app/Services/Fiscal/AuditLogService.php` | php |
| `app/Services/Fiscal/FiscalSequenceService.php` | php |
| `app/Services/Fiscal/XReportService.php` | php |
| `app/Services/Fiscal/ZReportService.php` | php |
| `app/Services/Orders/OrderItemAllergenSnapshot.php` | php |
| `config/fiscal.php` | php |
| `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php` | migration |
| `database/migrations/2026_04_18_140004_add_allergens_snapshot_to_order_items.php` | migration |
| `database/migrations/2026_04_22_000001_add_fiscal_sequence_no_to_orders.php` | migration |
| `database/migrations/2026_04_22_000002_create_audit_logs_table.php` | migration |
| `database/migrations/2026_04_22_000003_create_z_reports_table.php` | migration |
| `database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php` | migration |
| `database/migrations/2026_04_22_200000_add_composite_index_branch_created_to_action_logs.php` | migration |
| `database/seeders/SpatieRoleLookup.php` | php |
| `docs/FISCAL_SECRETS.md` | doc |
| `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md` | doc |
| `plans/PLAN_P2_MULTI_TENDER_HANDOFF.md` | doc |
| `plans/PLAN_P3_REFUND_HANDOFF.md` | doc |
| `reports/execution/HANDOFF_P9_5_2026-04-18.md` | doc |
| `reports/execution/HANDOFF_P9_6_2026-04-18.md` | doc |
| `reports/execution/HANDOFF_POS_9_2_AFTER_KIOSK_P9_5_2026-04-18.md` | doc |
| `reports/execution/PLAN_PHASE_POS_9_HARDENING_2026-04-18.md` | doc |
| `reports/execution/RUN_P9_4_KIOSK_2026-04-18.md` | doc |
| `reports/execution/RUN_P9_5_KIOSK_2026-04-18.md` | doc |
| `reports/execution/RUN_POS_9_4_2026-04-18.md` | doc |
| `reports/execution/RUN_POS_9_4_BL_2026-04-18.md` | doc |
| `reports/execution/RUN_POS_9_H_2026-04-18.md` | doc |
| `reports/execution/VERIFY_POS_9_4_2026-04-18.md` | doc |
| `reports/execution/VERIFY_POS_9_H_2026-04-18.md` | doc |
| `reports/review/AUDIT_POS_9_HARDENING_2026-04-18.md` | doc |
| `reports/review/VERIFY_P9_4_2026-04-18.md` | doc |
| `reports/review/VERIFY_P9_5_2026-04-18.md` | doc |
| `scripts/check-invariants.sh` | shell |
| `tasks/orchestration/CLAUDE_CODE_BOOTSTRAP.md` | doc |
| `tasks/orchestration/FIRST_PROMPT_CLAUDE_CODE.md` | doc |
| `tasks/orchestration/QUEUE_CURSOR_1_KIOSK.md` | doc |
| `tasks/phase9-pos/PLAN_POS_9_2_ET_9_3_2026-04-18.md` | doc |
| `tasks/phase9-pos/VISION_POS_FINAL.md` | doc |
| `tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md` | doc |
| `tasks/phase9-sync/BLOCKER_POS_9_4_2b_OrderService_posOrderStore_2026-04-18.md` | doc |
| `tasks/phase9-sync/BLOCKER_POS_9_4_5_AuditLog_call_sites_2026-04-18.md` | doc |
| `tasks/phase9-sync/BROADCAST_P9_5_MERGED_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_A_P9_5_FrontendOrderService_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_A_P9_5_OrderItem_migration_allergens_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_A_P9_5_OrderService_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_A_P9_5_PricingService_PricingRequests_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_2_3_OrderService_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_2_3_PaymentService_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_2_FrontendOrderService_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_2_OrderController_admin_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_2_routes_api_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_3_EventContract_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_B_POS_9_4_BL_DiscountCalculator_2026-04-18.md` | doc |
| `tasks/phase9/P9_5_BLOCKER_9.5.5_frontend_order_idempotency_lock_scope.md` | doc |
| `tasks/phase9/P9_5_BLOCKER_9.5.8_order_request_validation.md` | doc |
| `tests/Feature/CouponCheckNegativeTotalTest.php` | test |
| `tests/Feature/CouponRequestNegativeAmountsTest.php` | test |
| `tests/Feature/Fiscal/AuditLogBranchRequiredTest.php` | test |
| `tests/Feature/Fiscal/AuditLogConcurrencyTest.php` | test |
| `tests/Feature/Fiscal/AuditLogHashChainTest.php` | test |
| `tests/Feature/Fiscal/AuditLogImmutabilityTest.php` | test |
| `tests/Feature/Fiscal/Concerns/InstallsAuditLogImmutabilityTriggers.php` | test |
| `tests/Feature/Fiscal/FiscalArchiveMemoryBoundedTest.php` | test |
| `tests/Feature/Fiscal/FiscalArchiveTest.php` | test |
| `tests/Feature/Fiscal/FiscalHardeningMinorTest.php` | test |
| `tests/Feature/Fiscal/FiscalObservabilityTest.php` | test |
| `tests/Feature/Fiscal/FiscalPermissionTest.php` | test |
| `tests/Feature/Fiscal/FiscalRateLimitTest.php` | test |
| `tests/Feature/Fiscal/FiscalSecretProductionGuardTest.php` | test |
| `tests/Feature/Fiscal/FiscalSequenceTest.php` | test |
| `tests/Feature/Fiscal/OrderFiscalSequenceSchemaTest.php` | test |
| `tests/Feature/Fiscal/PosOrderBL1WireInTest.php` | test |
| `tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest.php` | test |
| `tests/Feature/Fiscal/PosOrderBL3DestroyAfterZTest.php` | test |
| `tests/Feature/Fiscal/XReportTest.php` | test |
| `tests/Feature/Fiscal/ZReportAggregateFilterTest.php` | test |
| `tests/Feature/Fiscal/ZReportBoundaryTest.php` | test |
| `tests/Feature/Fiscal/ZReportCloseTest.php` | test |
| `tests/Feature/Fiscal/ZReportControllerTest.php` | test |
| `tests/Feature/Fiscal/ZReportSchemaTest.php` | test |
| `tests/Feature/Fiscal/ZReportTaxBreakdownTest.php` | test |
| `tests/Feature/KdsChangeStatusConcurrencyTest.php` | test |
| `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php` | test |
| `tests/Feature/Migrations/ActionLogsCompositeIndexTest.php` | test |
| `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php` | test |
| `tests/Feature/OrderRequestNegativeTotalTest.php` | test |
| `tests/Feature/OrderSetupRequestNegativeValuesTest.php` | test |
| `tests/Feature/Orders/CleanupStalePendingOrdersTest.php` | test |
| `tests/Feature/Orders/CrossItemGuardTest.php` | test |
| `tests/Feature/Orders/IdempotencyBranchScopedTest.php` | test |
| `tests/Feature/Orders/KDSAllergenVisibilityTest.php` | test |
| `tests/Feature/Orders/KioskIdsOnlyPayloadTest.php` | test |
| `tests/Feature/Orders/OrderAllergenSnapshotTest.php` | test |
| `tests/Feature/PosDineInServerGateTest.php` | test |
| `tests/Feature/PosOrderRestoreIntegrityTest.php` | test |
| `tests/Feature/PosTicketRestaurantPaymentTest.php` | test |
| `tests/Feature/Seeders/RolePermissionSeederTest.php` | test |
| `tests/Feature/TableOrderNegativeTotalTest.php` | test |
| `tests/js/PosComponent.spec.js` | test |
| `tests/js/kioskCartSendPayload.spec.js` | test |

## B ∖ A (n=21)

Chemins suivis dans **B** absents de l’index **A** (exhaustif).

| Path | Type |
|------|------|
| `database/migrations/2026_04_18_120000_add_role_to_item_attributes.php` | migration |
| `database/seeders/ItemAttributeRoleSeeder.php` | php |
| `reports/execution/HANDOFF_P9_4_2026-04-18.md` | doc |
| `reports/execution/RUN_P9_3_KIOSK_2026-04-18.md` | doc |
| `reports/review/VERIFY_P9_3_2026-04-18.md` | doc |
| `resources/js/helpers/kioskPainCatalog.js` | frontend |
| `resources/js/helpers/kioskWizardFocusA11y.js` | frontend |
| `resources/js/helpers/kioskWizardResumeSnapshot.js` | frontend |
| `resources/js/helpers/kioskWizardSubmitGuard.js` | frontend |
| `tasks/k-hardening/K_TRACKER.md` | doc |
| `tasks/k-hardening/PLAN_K1_WIZARD_STRESS_2026-04-18.md` | doc |
| `tasks/phase9-sync/LOCK_A_P9_3_ItemAttribute_2026-04-18.md` | doc |
| `tasks/phase9/VISION_KIOSK_FINAL.md` | doc |
| `tests/Feature/Database/ItemAttributeRoleTest.php` | test |
| `tests/js/kioskPainCatalog.spec.js` | test |
| `tests/js/kioskPricing.spec.js` | test |
| `tests/js/kioskStepGarnitures.spec.js` | test |
| `tests/js/kioskStepTaille.spec.js` | test |
| `tests/js/kioskWizardFocusA11y.spec.js` | test |
| `tests/js/kioskWizardResumeSnapshot.spec.js` | test |
| `tests/js/kioskWizardSubmitGuard.spec.js` | test |

## Communs divergents (n=151)

Contenu disque différent pour un même chemin suivi des deux côtés (`hash-object`).

| Path | Hash A | Hash B |
|------|--------|--------|
| `.cursor/ACTIVE_CYCLE.md` | `8a4d059165ed7ddf700cb53e25cc8707591530f1` | `9f40499ed7a74e55da8b62e6eb793a9cbed57fe4` |
| `.cursor/agents/app-routine-implementer.md` | `3c2a8c26aa84fe955dab02dbb5b5a3e0fb408ebc` | `936738ce1d73221a01c8a98b9677496a11756fd2` |
| `.cursor/context/audit-context.md` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `f6a92fa9b86c8bd5bdd88a37f6fbb52a63ba9b89` |
| `.cursor/mcp/start-litellm-bg.sh` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `b5dff22fecb8701d08ff94c782a40a1d0c559d8d` |
| `.cursor/rules/claude.mdc` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `a20cd20f0994d8eccfb2ae8c3b72fcb195434a30` |
| `.cursor/rules/global-operating-principles.md` | `5cb002d04ca93a079fb427b85ec1c4390520abb3` | `df8c3b1a260c90cf85b5bd3bb6cab0e9eba787e2` |
| `.cursor/skills/project-handoff/SKILL.md` | `bd3644921b47e59f291853910ac0609ea471d7bb` | `5870456df6eb9da164eb1dd52c5d8ba59dee192a` |
| `.env.example` | `41d9fa37663f694f17059eaef5579207f55efab4` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` |
| `.github/workflows/phpunit.yml` | `42ccde784ba1376e661666eefaa5a2780f73afb0` | `0b22a077ca2b613ab197783f9a096243ccb79b41` |
| `.github/workflows/playwright.yml` | `d9b8ff21464512e25b5dcc29ed5ad770a86145b2` | `5824f74f410901ce76004e64bae3ddbf55c8e4bb` |
| `app/Console/Kernel.php` | `3bf11dd632c4b0e968b910959163a71784447834` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` |
| `app/Enums/PosPaymentMethod.php` | `af3b2a858381a68d94cbd1bb80bcd6487753cf57` | `70da384609b217c0fcbfa477472946b517a26ca5` |
| `app/Events/ItemAvailabilityChanged.php` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `d7f2056fa0210b741af977eade8a2b624952de33` |
| `app/Http/Controllers/Admin/AvailabilityController.php` | `43aeb44a631ea7878bfe4fbfb94731e82918451f` | `0e0bdc86e2139708191a9056804ed77f9aa49354` |
| `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` | `6425d13b4a7162e08c3b9de6a462872e7e58a68e` | `d24e5ef87f028d6e713017942b37cf7c7d3473d2` |
| `app/Http/Controllers/Frontend/ItemController.php` | `6131992f50dc4fcca778e4575505d79bcbc118fe` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` |
| `app/Http/Controllers/Frontend/KioskEventController.php` | `24e6d73b505d785b8b04ae2ee41f616a83a82472` | `05b73e6ffb90be3734df16b52f08954abe8724ca` |
| `app/Http/Controllers/Frontend/OrderController.php` | `74c1e58e5d1362f9855107954648ecf7858c3a39` | `a88c1e745e4fa995c75497a12861858a7b28dae3` |
| `app/Http/Controllers/HealthController.php` | `83a0797f08013bcd20260b2acf02acca6ec14bce` | `f53b3d9d51d2abf0f49e658fe933f1670656bb4a` |
| `app/Http/Kernel.php` | `09d4aee03480ff2827a67fadb88fa6dd2c7f17d3` | `6e2f4cb6004a123d2021280ba33ca74621e64445` |
| `app/Http/Requests/CouponCheckRequest.php` | `98b31798da066e6799613e41002a588004b347e7` | `e0c706b5246d161633d34a3018f6f3dd78e2c7f7` |
| `app/Http/Requests/CouponRequest.php` | `595f31266054f28b57bf3b4dffbecd1d35cf05e4` | `ce0d39694219af14daba8e9ed3fbdadc3c25db5a` |
| `app/Http/Requests/KioskMachineRequest.php` | `fb93eaacdb308d15efe527e7a8029b766b52cff5` | `b27e14c7f7b0b29bdff5f7e15de95610fcf9d180` |
| `app/Http/Requests/OrderRequest.php` | `e869d202c577edc8c58c722ee3c1ca6b7cf07621` | `7df55c3f6d0cb11b1f0cc895e016388ba4f4072f` |
| `app/Http/Requests/OrderSetupRequest.php` | `389bf724b9d852f14f6bb36fb617d6544aab66b5` | `f55e962907dd2bcb98ac2f500b038b31dfdc200b` |
| `app/Http/Requests/PosOrderRequest.php` | `8ac5039874f8f0b6813838a295414add3449bde4` | `62d687342f31b574405289b434be9854525db929` |
| `app/Http/Requests/TableOrderRequest.php` | `06a25cb485fdff23fd25a8ec2c01930779d04f0e` | `1f2342c93e4bb75651e90efb9373b1024f31b0ca` |
| `app/Http/Resources/KDSOrderDetailsResource.php` | `93d9da5f333724c443a3d401e044bdeab4cc0fb4` | `d289b00190d95e34df9bc91bfe4f52d7f225e8de` |
| `app/Http/Resources/OrderDetailsResource.php` | `4692d76bb13475a2a346e371ff9e7fe5ab6a9125` | `c338fee43550caf2f3a0ef1fb59eacdf627f3f5c` |
| `app/Http/Resources/OrderItemResource.php` | `247d2476dcd7e5e026ae34a1280050fc7cceb4bc` | `d3856205dac237893d9d2f41caa9a786e6258d8b` |
| `app/Http/Resources/SimpleItemResource.php` | `745c3a84c7605a8144809656b3465edeabf4eac8` | `1e5312fc996f23925ed440d622f3a06d16ab31cb` |
| `app/Jobs/DispatchDomainEventsJob.php` | `fec9b88b60f5025f863b8c9c858360e8fc92dc11` | `3083563e145b1eb4f1c954f4642f294e217f9eb3` |
| `app/Models/ActionLog.php` | `4b4180d179d0f03f010fcca360991f0f8d7801a6` | `788ce93bdc52c51029e97340415225fe5f7dc33f` |
| `app/Models/Branch.php` | `2d7a421f8490bb0685066b4ed27dcd0135704b3c` | `03a82fde206551170692c86722e5d6137b5476e1` |
| `app/Models/Item.php` | `cdd5ab05890d58eebad267bb893cfefcf21d2d46` | `fed0d7c7225c3218d7e55826aca66c33f5504c51` |
| `app/Models/ItemAttribute.php` | `e6d6a9c1e4d5b9a6e21700d766367fc56585e727` | `ac14c234f32c59a7f18d33f695ceef0ce26a04dc` |
| `app/Models/KioskMachine.php` | `9002b8f49eda97b141f8f8b63be854c4e772f94d` | `c083653d6cd2bce495d2e11beba3e9106c00b213` |
| `app/Models/Order.php` | `e6cd2c6d2fff28857b972232372e8dc9e7a251fe` | `c095f477bad80ab02e17ca7c64f42d130b0915b2` |
| `app/Models/OrderItem.php` | `0afc6a7335c081c804ad0f75d385d806f622a43d` | `e357dc6f26c2e271939fa3da55c1d70738014bcc` |
| `app/Providers/RouteServiceProvider.php` | `d57da1a4efdbf70804fbf2061e410ac49717deaf` | `e076faf6b4a11096c5cf1562a4414ba04aaa5a34` |
| `app/Services/DashboardService.php` | `b6f049d5986f7f0c018bde539b81607493be874b` | `2d50128dd7659e073ffed383204131cb8d6474f5` |
| `app/Services/FrontendOrderService.php` | `b6ff305d8ef720bf3188e9aee99d07c080184967` | `10a1836fd0d320ba5b92f7dc920c12745d369e12` |
| `app/Services/ItemService.php` | `d54a24a1f1fbdc126be56c8a637e5612fc84d220` | `d781713e2300784b44159bb5dd915c1571feafef` |
| `app/Services/KitchenDisplaySystemOrderService.php` | `f2e84d987af5c46d8a6200133499c9138e46a64f` | `67d513230fd705332fcfa1129fda42c3ac293e33` |
| `app/Services/Menu/AvailabilityService.php` | `2ded7faa1bce000c035bae51e3bfa42f4085fc81` | `adcd065040903eb134dc8caf1b822c136b673e34` |
| `app/Services/OrderService.php` | `1f0d83fc96911c5c63cfeae0e9ae4aec6ab1b32b` | `5d972283fa070727fc53c2836737b400bfaaab78` |
| `app/Services/PaymentService.php` | `3c9363f771072deed2f16fdc3550e4ae108ffd5a` | `55a49d43a28f91daafe70250b8cc4e7c27ce32e9` |
| `app/Services/Pricing/PricingRequest.php` | `463a7ca5af91f64ce1cb3c60cf609612ac86ca08` | `16dfeea76b30ad365fef524872b4c018c466d1bf` |
| `app/Services/Pricing/PricingService.php` | `fead8d040d5d74f05eab263acc871bcfc6cdfdb7` | `771c04a5e67829e06733757323f5782af11bb119` |
| `composer.json` | `94fda85ff343f60eb5ee2ce2bb9cfcc56e844f28` | `2f04af650e9e4e054164d27469344e5f1ca4e962` |
| `composer.lock` | `cbe0b8a24ab16718c99252a5ebfe4dc489c2176c` | `55b522f51d8e586c347e4acd44759721d62bc60c` |
| `config/app.php` | `1a6679bb6d1985ce96920a6d7cf92175597de4ce` | `4d5261d6179c88f3ad158ff715b831c83c6619e2` |
| `config/logging.php` | `d4299525fbed0262d4db304945d869e27c041eca` | `736946a53eae647dd83b9bdc7c929f042fd6f662` |
| `cursor-export-new-account/rules/COPY_INTO_CURSOR_USER_RULES.md` | `8c5e0c0523aed50f0dfe735a32f1b79158fb2554` | `bc7b58778ca74632b169f689e19bbc831d41513f` |
| `cursor-export-new-account/skills/project-handoff/SKILL.md` | `6888f1828b8a01849fdb7a592ebfe3e56e3d7c2b` | `689e2ab6699c142763a533dcc46ed62385d137d0` |
| `database/seeders/MenuSeeder.php` | `e3cb2ecc10cb8a4d3ada766725e12b6266d34747` | `312f8883dffe869988be32874d5c86cdec14843b` |
| `database/seeders/PermissionTableSeeder.php` | `e9eb3ed13f93f9eb1552019fd647278cb0bf1ae4` | `27fb5656237b0f8b988ad10485245fa891647f2a` |
| `database/seeders/PermissionTableSeederVersionTwo.php` | `05b4905a92b1d34d6745fe0e2e5a8c34a044e81e` | `9b72628ccdcf524516d9efa936c3c87e6472e92c` |
| `database/seeders/RolePermissionTableSeeder.php` | `3e41a404d3576b67994327a19c003ffdd7fb4ab8` | `0674d54ab9603b8dfc5d1e83943e2bc63ab6e63a` |
| `docs/AUTHZ_MATRIX.md` | `ca55ba1f6f298620063e14c2314e55a81607fa03` | `e895e19a008b18a82c9a67f12a8e7cbc32a1a2a1` |
| `docs/orchestration/AGENT_ROLES.md` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `51f83ce3a8ebe365afd5629ee8e33dc877a959cf` |
| `lang/ar/pos_payment_method.php` | `5446253086cfe0b66766d3579d1ac6b6f4502082` | `31643d388aab3c00bc2cc8693cdb221a36867486` |
| `lang/bn/pos_payment_method.php` | `b952ea4013b1468986b3e3169a4f31f15010eafb` | `c83216a1a573dfba9cf0041d6d32f3ac75582deb` |
| `lang/de/pos_payment_method.php` | `fe50b1f4bcb5b45485425d4d214dabe8cdd33bab` | `e4372ec8a347c94de2592e8220eb45fdea70de3c` |
| `lang/en/pos_payment_method.php` | `05b14dafb6b1f1c2e8a8747f95d04c105cd54fbc` | `da712322298d97d75ef863c784acd06e329f069e` |
| `lang/fr/pos_payment_method.php` | `4e464acf963be8096de5b10203941c22bbd3d0d8` | `da712322298d97d75ef863c784acd06e329f069e` |
| `playwright.config.js` | `d830185d0dd39d579a1874d1893addf7f7e67efd` | `5360d5c776bc08b566e521df0969000d1855ffab` |
| `public/js/pos-wizard.js` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `1a01565b3170c8f18be51709bbc715c599327bd6` |
| `reports/antigravity/playwright-latest.json` | `471053cfac939492446dc59cbdb08d3cc7b009f0` | `35c5eba801cc6909a949ea91b694808de069d0df` |
| `reports/compact_snapshot.md` | `aa9526163bd59dbb7751d0498bb73d8cb87c06a0` | `ea965304de4b89ddd67c07c580527a1b6c4f2709` |
| `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md` | `871233a48319873426e5c8216afcb5b8ce2e1cdb` | `af0ca979cb0d9b4d20eb5bf3516969f1a868b158` |
| `resources/css/kiosk/tokens.css` | `26c25e6b36054a48ab506d20ef1538226f5e8f57` | `f8b8aa04123eddc934fa1327858963aa0a6b2f28` |
| `resources/js/bootstrap.js` | `1737e7af777fc512becc31de667de8a6db259027` | `2391346c2d9c153a9a8e7b3d0a0f7a047552d679` |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | `9183c587ceeaff24d1bb892dd3ffb9ea7de5bbe2` | `50a4b56a86f882cd55a09a23e67e2dff1efd4041` |
| `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `c8af95ecc9a2c0e616ad3ff6f915a028490fa8c0` |
| `resources/js/components/admin/pos/PosComponent.vue` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `c9b6d0f30fe7fe0b40f0a611cbea7853892c8f11` |
| `resources/js/components/frontend/auth/LoginComponent.vue` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `4a1000ec7c9c341382a78b4d19226e10812274cc` |
| `resources/js/components/frontend/kiosk/KioskAdminComponent.vue` | `36555505766305154f8555aa142aa9ec424f4491` | `37e1ff0088be44871be0cac94e4ffc5525656b89` |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | `d79a2c9f4c3452bcd281be62c523f1fa1e284759` | `8ea49d2537d22f937aa56c16e54f1d2189397794` |
| `resources/js/components/frontend/kiosk/KioskCartComponent.vue` | `d9fbcfc4e2f2dfda76b8f0035f71ca3479d383d3` | `add096a141b565c5b5ed4e6636375561a828359c` |
| `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue` | `3836f14ec79c51292aa85ba1ab6c539b08f92d63` | `350f9fa949174fdc5720320d7c602b1380f4d09a` |
| `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` | `d35ae0b9208a5fa02a95c6bb9fedaa409b138291` | `0f5397bfead6eb5e1aec07c0f94cb8965741fce7` |
| `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` | `365b8e895c91d3f442ab47f4cbee15eae69ccfe3` | `bb50abb1f4fd35b0100ec474ea9a173643302ce0` |
| `resources/js/components/frontend/kiosk/KioskLoginComponent.vue` | `bc12d458eb3c7e1cf12320d5657dd263e947f9cf` | `409ac7bc09659771c468706272fc5a84d3eefd27` |
| `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue` | `2ed965a68209be0dc4f983ef211fc720deba8665` | `d0e93ae021377a84db35f93a63587a4ca36d98d8` |
| `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `1581b200e3cf13e761dbc434948a93042d280b74` |
| `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` | `fbd32b5e8e760593e11310a67f2b1ee3fe42b9b9` | `1f75cf0d539fcceca4da778de39dbaa479a6f26e` |
| `resources/js/components/frontend/kiosk/KioskProductListComponent.vue` | `4bf8f97806a5443f7076bb99df4a87a9486ab698` | `e7ecf6be140ad08b3098d43bd72d85f8d5f352a5` |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | `02136e3f855ce4f8b6f10f169850a3cc85b93829` | `19cf68351c727fa503869c2059aa820bec022248` |
| `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | `3c9514c896e5ec63bace315905b7efd766e39f11` | `41ca8e4f359306bb3f97ec17bdeb3ab5ad8812d2` |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | `11523716b33946523971f7cb832613c50649f740` | `a1bd8379bb73466e140072ab3dec438642d89e01` |
| `resources/js/components/frontend/kiosk/ds/KsA11ySettings.vue` | `25ca2c13821736199754e7ed2e096512c4acc4a9` | `34bfdc00c310b1ef3dfd3db8220818e96adb4098` |
| `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue` | `f06543346bd732897453a27958706fe7a8eb1895` | `c32ee5b63ad85efae2512a3eed1c0957b19d00b1` |
| `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue` | `024dc13c03ef9df4808f23a0f6f75c64b135bf98` | `06355e7f1730e93d545d5a003326f2d673804617` |
| `resources/js/components/frontend/kiosk/steps/KioskStepPainComponent.vue` | `5d58d7063c38929fa99adcf41f065febf20cd8b7` | `4b978b6bb5528274c91f2ea2506ccfbfaa568774` |
| `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue` | `a5e1763225058c31d94f8367a4588bb650d6431e` | `a6481fc46413f0ef67953a5b0e5c8092c7c43a1d` |
| `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue` | `742579168d4fb05293ce6128c13e8ee635bfd2f8` | `91c4fb3db1af551f93ac86e303234e167db05fa4` |
| `resources/js/components/frontend/kiosk/steps/KioskStepTailleComponent.vue` | `0cad2803ddc505817a43c01523f5a50a262617f0` | `7f04d372c5e5637bb87aa64729edc8bcdec27103` |
| `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue` | `afea80cf1183db68807db3d4e25644a0d0171b2b` | `bb727e7183d3b2b27b82473aaf0ca3734ef1c6f5` |
| `resources/js/helpers/kioskAnalytics.js` | `568630c3d70f0ca004592161c172c3adc7bd7fa4` | `d02bf9da686ec7cefe8d44ef8601cb5565faa9d9` |
| `resources/js/helpers/kioskOfflineQueue.js` | `be53422f8258f35edb0e2445d0aca725145a1596` | `5c42c5f58d492d520c495f194294d0070f21089a` |
| `resources/js/helpers/kioskPricing.js` | `5d3bbad8ec7507d9e4bd6bed6b6f90a445256568` | `157e7f7120f143d898e44c9506dca507bcbba62b` |
| `resources/js/helpers/kioskPricingPreview.js` | `6474d6b613b3a028148450ba8fc373ec5b32b7f6` | `4aa0ee19dd06074af9d36c3945cb38bd1be4ae20` |
| `resources/js/helpers/kioskPrinter.js` | `8fd4d21498a8ade1025c625e55526a0b74d162be` | `2777de8b3805ace0afdd2d71721117cbb43cfaaf` |
| `resources/js/helpers/kioskSauceCatalog.js` | `064d75b96b25af313746b7aba11923de3cd2a2bc` | `3e9dd6378785eedce0729a6fa4a97c61c3508b9a` |
| `resources/js/helpers/kioskViandeCatalog.js` | `a8fa8764ab9e60b4b89a6b6002cdc21d5bad0a15` | `c2c96a744b90d9c7089f406957fd8751bf376aa2` |
| `resources/js/languages/ar.json` | `3197b437a40a6cd0012ff1827c31ccaf53137c1e` | `71f06f19d797d15b2c73f76a5af47427ced729ef` |
| `resources/js/languages/en.json` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `c4dd87102df0f1595992f77afeab2eedce1ae7ae` |
| `resources/js/languages/fr.json` | `4a146333c0dea009ef23a3b6a680108cf6287d86` | `afe381f4e35cc580510be2c1054d55717cbd1a0b` |
| `resources/js/services/WebSocketService.js` | `d408da023479390de71e398a618625a3de461f59` | `ab7841fc43ed335c55d3e7871699eb26d0122097` |
| `resources/js/services/kioskHardware.js` | `7670b6147abc1315938817f60b865831cc6ec04a` | `8f742cd76b510fe8a70e20cd2af659e24a566555` |
| `resources/js/store/index.js` | `5f84f6e50a434cde81f594577ac7d99de264dd76` | `dcfe875bf6dcdc26ea35ad8bd53fa3d0e13984f2` |
| `resources/js/store/modules/kioskCart.js` | `baaae0566c7be1877d5165ecaa8293e7d8019ae3` | `4eac1513a576bc98d67ccd6a4b65afde1b51f931` |
| `resources/js/store/modules/kitchenDisplaySystemOrder.js` | `81d729eb04bb970ef88759322f6634767782bd8f` | `2da80200d7afe5d97cd417588c9e8b8c809070b4` |
| `resources/js/store/modules/posCart.js` | `a20d5772da62f85a0271345025b272c1ac2d6b1e` | `b9d9ee471b82a2c21af5051bc2b5d7c0992c7d6e` |
| `resources/views/master.blade.php` | `c65f2e089a3287997427fe00d04f3b55d9fbfb08` | `d6187394cb6d3ad5d9c8f0bc18958ed3857dfe9d` |
| `routes/api.php` | `ce83d72091ff98963561fd420536a12fd862f1cf` | `780eb6148806d7b48c1e23ac200904efdee98873` |
| `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` | `d26c61e01abc9e5938eafec673659ff805947f46` | `5399145fbff8ce8c33dfe1abac02dc8564026036` |
| `tasks/phase9-sync/CROSS_TRACK_STATUS.md` | `54221640c83778bfea9b38198511355f9551b266` | `45c53c0576650273a39e40b628333ffde16b367c` |
| `tasks/phase9/FINDINGS_TRACKER.md` | `c660b5ca28ea507e4b8a9affbde365d7b14a3412` | `ede96beb603676825d99161255b8917b96c43da5` |
| `tests/Feature/ActionLogBranchIsolationTest.php` | `c1f267372943c1045729d862f76877ef3f078f03` | `6c6afa9146adb6f62a772186393e9dde511a3584` |
| `tests/Feature/AdminCrudComprehensiveTest.php` | `737de104d6f5fe244f1f3dfe5fca4a7a6d7532fb` | `2b4713c05776bdcbba099172dde939e855fa7e90` |
| `tests/Feature/BranchIsolationTest.php` | `c5f848b2f86d47d4c9f17426662f19664eecfe42` | `ee914f699c8ed358190c6c159ac43a8cbb0bb11b` |
| `tests/Feature/BranchScopeTest.php` | `f54eadd6c820b7795eb070917b20ecf9df10a431` | `3a688165c1d2a879b19809f6873edbc9563beb74` |
| `tests/Feature/EventContractTest.php` | `a565ec9cfc3ce2f67eb21bdf47da847da7f46eba` | `0bb9fd29be7d874e1c2073c91470c2b20f80865f` |
| `tests/Feature/HealthControllerTest.php` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `1cfc111918b13a4a5bca11a4a031edbf3ff84176` |
| `tests/Feature/ItemResourceAllergensTest.php` | `3437f8785cf7c27525cdec7c011485a70436d6fa` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` |
| `tests/Feature/KioskPhase1/KioskEndpointsTest.php` | `b00a6d92b8f30eb0eddfa4a10171cf0639e9b064` | `fe79f6288690da28ac4bb74ab3787c0e81506a4d` |
| `tests/Feature/KioskPhase1/NormalItemResourceAllergensTest.php` | `4e278cbee851286a71c369813fcef81b3052476d` | `8648b7af0fd4df634e5b724a66ce02b71b70d956` |
| `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` | `46cb78de2c5f26052f18d08c836b4c0d61a06945` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` |
| `tests/Feature/OrderCancellationLoyaltyTest.php` | `9adc612e987ed4be5061a12397ec349ad84f6d89` | `02b2e75c3b74a02d46b6399bc6fc2fc3cebcc675` |
| `tests/Feature/OutboxTest.php` | `9136a8ba09ff70d64ca6c3c73999abfde60f2546` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` |
| `tests/Feature/PosOrderRequestNullableTotalTest.php` | `2881e0fdf9b01a74853fb5bebc72171278deedea` | `b0b78a30c7c2e9199f151b64ed2d046f426b33f8` |
| `tests/Feature/Requests/ItemRequestTest.php` | `12459b93c1a45653fe2131195b5c09672cdf5a85` | `4091de487005150386f19982206e83a30ace9858` |
| `tests/Feature/Services/Menu/MenuProjectionServiceTest.php` | `94113bad854fe7c889c38a2bd44bd4066b8f8283` | `5fe1459871824ade11b070f93f9e8c367c73ae45` |
| `tests/Feature/Services/Pricing/PricingServiceTest.php` | `d639de5df2f82125ef312305e0987867b0d2d732` | `4c1df410662a3ab1df63aafa8e5c6ae3eebce55b` |
| `tests/TestCase.php` | `209359d0b38e8fa56f94e77f4cf48c02ca6e21bd` | `658ea316cb8d9dd2a5957e7ca088888458ce77eb` |
| `tests/Unit/Domain/Order/OrderStateMachineTest.php` | `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` | `4756581ea8fd6ec2e849690f9932288970e2d28d` |
| `tests/Unit/Security/RateLimiterConfigTest.php` | `a5e6b1a66bd354c7d5eaeea53f9d3c5079e9b453` | `512c7d34abdd76ec6f5a15781c809d8e4da7177d` |
| `tests/e2e/01-auth-refresh.spec.js` | `86b250be700a1be557c718acd20b9c2a4ad6e8e7` | `a8df8b60ddc4a957df077f6c195394bfa7f66046` |
| `tests/e2e/02-pos-cash.spec.js` | `463f4681619dacbb87514d7296cde863864a6daa` | `40757705e0c7e12bdced56de81ccb18e4e6a5b2d` |
| `tests/e2e/03-kiosk-wizard.spec.js` | `639faead35da59237aeaf1c92b6ad58f38f2adaa` | `354f625b1df2973f417c2098bd4ff25c258f5a3d` |
| `tests/e2e/04-kds-status.spec.js` | `45ec3cc2f758f9a45b788950cd55d0bc4d839f82` | `3dd4fa6d3123abef4537a3e798142f6ed84c25d3` |
| `tests/e2e/05-pos-card.spec.js` | `5eb99bdaaf65cf536fac00cad5c49da712ddebd7` | `8882187361d7a0ab63152c23ea688e1565c50268` |
| `tests/e2e/06-staff-only-routing.spec.js` | `abb041ff14c2b523da65c21411aba2c1501d01d0` | `78dbe113d06561862af6a18c718d8da7cdea833d` |
| `tests/e2e/helpers/login.js` | `0f312a169d22e9e55c3424e52f7d74223a293916` | `ee421ef8f2f1ccdfddf1a50cf2805e5e4956f37f` |
| `tests/js/KioskWizard.spec.js` | `b08cdeadb28b3b7536364ca6a6da5df8e3abd15e` | `9cd91e154e06589e1190f4717c50aebe84459abb` |
| `tests/js/kioskPricingPreview.spec.js` | `ade901e9a5580512699c9ca893993022f7432ebf` | `9d3d65f2e28306d8e2e6efc99b351fa239b4d6b9` |
| `tests/js/kioskSauceCatalog.spec.js` | `b6b4a3807481ff629036dad742d51c27f91dafbb` | `d53974f1e49a9aa94225112ceaefc4f2516ec78f` |
| `tests/js/kioskViandeCatalog.spec.js` | `ea9589aebfa83197e56f8c843cbb19c6fb7542a5` | `318c7599851a1a3e4847b00176992490442e4246` |
| `tests/js/posNewOrderNotify.spec.js` | `abec232e6e9b298b224c520b435b5dfd3d5df44b` | `7039b9244570846db03ef0f7d8b76273788ec6ce` |

## Fichiers critiques (verdict + diff ≤ 5 lignes)

| Chemin | A | B | Verdict (≤5 lignes) |
|--------|---|---|---------------------|
| `app/Console/Kernel.php` | présent, non vide | présent mais **fichier disque 0 o** ; `git status` **M** (index ≠ WT) | **Divergence WT** : côté B le contenu Laravel a été effacé localement ; l’index contient encore ~51 lignes côté A vs blob vide côté B sur disque. Restaurer depuis `git checkout --` ou depuis A. |
| `resources/js/observability/sentry.js` | **absent** (pas de répertoire) | fichier **0 o** présent, **non suivi** (`??`) | Pas dans l’intersection « communs suivis » : création locale vide / WIP ; pas comparable via `ls-files`. |
| `resources/js/helpers/kioskPerf.js` | absent | **0 o**, **non suivi** (`??`) | Idem ; les specs B importent ce module (ex. `kioskK5PerfInstrumentation.spec.js`) — risque CI si fichier vide. |
| `app/Jobs/DispatchDomainEventsJob.php` | suit | suit | **Divergence** : A garde un contournement explicite `connection('pusher')` + skip si clé vide ; B utilise `BroadcastManager::connection()` + `broadcast()` pour respecter `broadcasting.default` (tests/CI). Refactor intentionnel côté B. |
| `app/Services/FrontendOrderService.php` | suit | suit | **Divergence** : B ajoute garde dispo branche (K-2), libère le lock idempotence avant early return (K-3), simplifie la clé d’idempotence (sans préfixe branche explicite dans un chemin) ; A garde hydratation allergènes / AvailabilityService différents. |
| `app/Services/PricingService.php` | **n’existe pas** (SSOT : `App\Services\Pricing\PricingService`) | idem | Verdict : chemin legacy demandé inexistant ; comparer `app/Services/Pricing/PricingService.php` (voir ligne tableau communs divergents). |
| `app/Services/Pricing/PricingService.php` | suit | suit | **Divergence** : ajustements de logique / garde-fous pricing (hashes `fead8d04…` vs `771c04a5…`) — à relire en fusion (P1). |
| `app/Http/Resources/NormalItemResource.php` | suit | suit | **Identiques** (même hash blob). |
| `database/seeders/AllergensSeeder.php` | suit | suit | **Identiques**. |
| `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md` | suit | suit | **Divergence** (hashes différents) — contenu de plan / statut de phase mis à jour différemment. |
| `phpunit.xml` | suit | suit | **Index** : contenus HEAD distincts (~2834 o vs ~1713 o). **Disque** : **0 o** dans les deux worktrees (`M`), donc configuration PHPUnit effacée localement des deux côtés — **P0** à restaurer depuis `git show HEAD:phpunit.xml`. |
| `app/Http/Controllers/Frontend/ItemController.php` | suit, non vide | suit ; **disque 0 o**, `git status` **M** | Pas de commit `--diff-filter=D` sur B : l’entrée d’index pointe encore vers le blob complet ; l’effacement est **uniquement copie de travail** sur B. |
| `tests/js/networkStatus.spec.js` | **absent** (non suivi, fichier inexistant) | absent (non suivi) | Aucun `networkStatus.spec.js` dans les deux arbres ; la couverture réseau est ailleurs (ex. `kioskK5Cleanup.spec.js`, imports `resources/js/services/networkStatus.js`). Pas de commit de suppression trouvé. |

## Fichiers cités introuvables (K_TRACKER / rapports)

| Chemin attendu sous B | Statut |
|-----------------------|--------|
| `testttt-kiosk-p93/reports/execution/VERIFY_K10_ACCEPTANCE_2026-04-19.md` | **Présent** |
| `testttt-kiosk-p93/reports/execution/HANDOFF_K10_ACCEPTANCE_FINAL_2026-04-18.md` | **Présent** |

## Hypothèses suppressions majeures

| Sujet | Évidence git | Message / lecture | Hypothèse |
|-------|--------------|-------------------|-----------|
| `ItemController.php` « supprimé » sur B | `git log --diff-filter=D -- app/Http/Controllers/Frontend/ItemController.php` → **vide** ; `ls-files` + `status` → **M**, blob index intact | n/a | **Régression locale / fichier vidé**, pas une suppression versionnée. |
| `tests/js/networkStatus.spec.js` | Jamais dans `git ls-files` (A ou B) ; `log -D` vide | n/a | **Jamais commit** ou renommé ; docs obsolètes vs arborescence actuelle. |
| Bloc **A ∖ B** (107 chemins) | Fichiers absents de l’index B | Les ajouts récents côté A (fiscal, migrations, tâches phase 9 POS, etc.) n’apparaissent pas sur la branche/checkout **B** | **Migration de branches / backlog de merge** (pas une « suppression » dans l’historique de B). |
| `resources/js/services/networkStatus.js` (B) | `git status` → `??` (non suivi) | n/a | **Travail kiosk non commit** sur B ; dépendances (`kioskCart.js`, etc.) peuvent pointer vers un module absent du dépôt distant. |
| Historique `git log --diff-filter=D` global sur B | Sortie volumineuse (artefacts historiques divers) | Ex. `f6cc88a2f` *cleanup: remove accidental ssh-keygen artifact files* | Nettoyages ponctuels d’artefacts ; **non lié** aux chemins métier de cette comparaison. |

## Priorité réconciliation

| Priorité | Path / thème | Raison |
|----------|----------------|--------|
| **P0** | `phpunit.xml` (WT vide A+B), `app/Console/Kernel.php` et `ItemController.php` (WT vide B) | Casse tests et bootstrap Laravel tant que la copie de travail est vide ; restaurer avant toute CI. |
| **P0** | `resources/js/services/networkStatus.js`, `kioskPerf.js`, `sentry.js` (non suivis / vides) | Imports kiosk et specs Vitest : risque runtime et tests rouges ; committer ou supprimer les stubs vides de façon cohérente. |
| **P1** | `app/Services/FrontendOrderService.php`, `app/Services/Pricing/PricingService.php`, `app/Jobs/DispatchDomainEventsJob.php` | Divergences fonctionnelles (idempotence, dispo, broadcast) — fusion guidée par règles métier / invariants FoodKing. |
| **P1** | Ensemble **A ∖ B** (fiscal, migrations, tests associés) | Porter les livrables POS/fiscal de A vers B ou documenter l’exclusion volontaire. |
| **P2** | `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md`, docs/tasks divergents | Aligner la documentation de phase ; pas bloquant runtime. |
| **P2** | `tests/js/networkStatus.spec.js` (manquant) | Mettre à jour les rapports d’acceptation qui citent ce fichier ou ajouter le spec si toujours requis. |

## Notes résiduelles (validateur / planner)

- Les hashes `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391` correspondent au **blob vide** : nombreuses entrées du tableau « communs divergents » reflètent des **fichiers vidés sur disque** (dont plusieurs sous `resources/js/…` côté A et B), pas seulement des diffs sémantiques entre commits.
- HEAD **A** et **B** ne pointent pas vers le même commit ; l’écart est attendu entre `feat/ton-sujet` et `feat/kiosk-phase-9-3`.
