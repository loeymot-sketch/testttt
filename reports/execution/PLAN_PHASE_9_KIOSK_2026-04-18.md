# Plan Phase 9 — Kiosk FoodKing — 2026-04-18

**Source.** `reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md` (4 audits parallèles ≈ 2 500 lignes de findings).

**Invariants non-négociables** (hérités Master Prompt) :
- Backend SSOT pricing strict (aucun prix client dans `POST /api/frontend/order`).
- `branch_id` serveur, jamais du payload, validation Broadcast channels.
- `OrderStateMachine::apply()` pour toute transition, `DB::afterCommit()` pour tout dispatch.
- EventContract V1 strict (`version, type, aggregate_id, aggregate_type, branch_id, correlation_id, occurred_at, payload`).
- Aucune statistique client (pas de "78% des clients", "bestseller"…). Seul `is_chef_pick` statique.
- RGPD : consent explicite opt-in, logs anonymisés.
- WCAG 2.2 AA par défaut + AAA + PMR + audio/TTS combinables.
- DS maison uniquement, zero dépendance UI lourde.

**Gate commun.** Chaque vague passe ces 3 gates AVANT merge :
1. Vitest complet vert + nouveaux tests dédiés verts.
2. PHPUnit complet vert + nouveaux tests dédiés verts (sauf 3 tests `FrontendSurfaceFilteringTest` si non-couverts par la vague).
3. `npm run production` compile sans erreur, bundle size kiosk stable (±5%).

**Commit style.** Un commit atomique par sous-phase. Message : `feat(kiosk/phase-9.X.Y): <résumé>` ou `fix(kiosk/phase-9.X.Y): <résumé>`.

---

## SUBSYSTEMS_TOUCHED (gouvernance EXECUTE)

Périmètre autorisé pour les implémentations P9.1 → P9.10. Tout fichier hors de ces zones nécessite une mise à jour explicite de cette section avant édition.

### P9.1 (clos / mergé)
- `app/Http/Resources/NormalItemResource.php`
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`
- `app/Providers/EventServiceProvider.php`
- `app/Http/Controllers/Frontend/{PricingPreview,Promo,KioskEvent}Controller.php`
- `app/Http/Requests/{PricingPreview,Promo}Request.php`
- `routes/api.php`
- `resources/js/components/frontend/kiosk/**`
- `resources/js/store/modules/kiosk{Cart,Menu}.js`
- `resources/js/helpers/{kioskPricingPreview,kioskReceiptPersistence,kioskAnalytics}.js`
- `resources/js/composables/useKioskSpeech.js`
- `resources/js/languages/{fr,en,ar}.json`
- `tests/js/**`
- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`
- `.github/workflows/phpunit.yml`
- `docs/TESTING.md`

### P9.2 (catalog SSOT + real-time hardening — actif)
- `app/Http/Requests/ItemRequest.php`
- `app/Http/Requests/ItemCategoryRequest.php`
- `app/Http/Requests/Admin/AvailabilityToggleRequest.php` (nouveau)
- `app/Services/ItemService.php`
- `app/Services/ItemCategoryService.php`
- `app/Services/ItemCategoryHierarchyService.php` (nouveau)
- `app/Services/AllergenService.php` (nouveau)
- `app/Observers/ItemObserver.php` (nouveau ou existant)
- `app/Events/{ItemCreated,ItemDeleted,CategoryCreated,CategoryUpdated,CategoryDeleted}.php` (nouveaux)
- `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php` (nouveau, frère du listener P9.1.4)
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` (extension OK, pas de remplacement — cf. HANDOFF_P9_2 §1.2)
- `app/Http/Controllers/Admin/AvailabilityController.php` (nouveau)
- `app/Models/Item.php` (relation `allergens()` à ajouter si manquante, observer binding)
- `app/Models/Allergen.php` (relation `items()` réciproque si manquante)
- `app/Models/ItemCategory.php` (relation `parent()`/`children()` si nécessaire pour hierarchy)
- `app/Providers/{App,Event,Route}ServiceProvider.php`
- `routes/api.php` (et `routes/admin.php` si présent)
- `database/migrations/<TS>_add_fks_to_item_branch_availability.php` (nouveau)
- `database/migrations/<TS>_rename_allergen_codes_to_fr.php` (nouveau)
- `database/migrations/<TS>_add_hierarchy_channels_to_item_categories.php` (nouveau, si colonnes manquantes)
- `database/migrations/<TS>_add_kiosk_flags_to_items.php` (nouveau, si colonnes manquantes)
- `database/seeders/AllergensSeeder.php`
- `tests/Feature/Database/{ItemBranchAvailabilityFk,AllergensSeeder}Test.php`
- `tests/Feature/Requests/{ItemRequest,ItemCategoryRequest}Test.php`
- `tests/Feature/Services/ItemCategoryHierarchyTest.php`
- `tests/Feature/Admin/AvailabilityControllerTest.php`
- `tests/Feature/Cache/CacheInvalidationTest.php`
- `tests/Feature/Routes/MenuControllerRateLimitTest.php`
- `tests/Unit/Services/AllergenServiceTest.php`
- `tasks/phase9/FINDINGS_TRACKER.md`

### P9.5 (order pipeline hardening — actif)

**Gate clearance humaine explicite (message utilisateur 2026-04-18).** Les fichiers `FrontendOrderService.php`, `OrderService.php`, `PricingService.php`, `OrderItem.php` + migrations associées sont temporairement dégelés pour P9.5 sous LOCK_A maximal (voir `tasks/phase9-sync/LOCK_A_*_P9_5_*_2026-04-18.md`). Les locks sont libérés à la fin de chaque commit qui touche la zone concernée, et le fichier retourne en frozen dès la fermeture du lock.

- `database/migrations/<TS>_add_allergens_snapshot_to_order_items.php` (nouveau — 9.5.1)
- `database/migrations/<TS>_scope_idempotency_key_to_branch.php` (nouveau — 9.5.4)
- `app/Services/FrontendOrderService.php` **(LOCK_A — persistance `allergens_snapshot` depuis pivot)**
- `app/Services/PricingService.php` **(LOCK_A — cross-item guard 9.5.6, pas de modif cœur SSOT)**
- `app/Services/OrderService.php` **(LOCK_A — uniquement si nécessaire, sinon noop)**
- `app/Models/OrderItem.php` **(LOCK_A — cast `allergens_snapshot` JSON)**
- `app/Http/Resources/KDSOrderDetailsResource.php`
- `app/Http/Resources/OrderItemResource.php`
- `app/Http/Requests/{PricingRequest,PosPricingRequest,TablePricingRequest,WebPricingRequest}.php` (9.5.6 — cross-item guard systématique)
- `resources/js/components/backend/frontend/KitchenDisplaySystemComponent.vue:404-427` (affichage allergens snapshotés — 9.5.2)
- `resources/js/components/backend/frontend/PosComponent.vue:599-605` (drawer expandable — 9.5.7)
- `resources/js/store/modules/kioskCart.js:235-258` (retirer prix payload client — 9.5.8)
- `app/Http/Requests/OrderRequest.php` **(scope extension 2026-04-18 — additif uniquement : `total` et montants dérivés passent en `nullable`/`sometimes`, le serveur recompute via PricingService SSOT — même pattern que POS-9.1.8 sur `PosOrderRequest`. Unblock `P9_5_BLOCKER_9.5.8_order_request_validation.md`.)**
- `app/Jobs/CleanupStalePendingKioskOrders.php` (nouveau — 9.5.3)
- `app/Console/Kernel.php` (schedule 5 min — 9.5.3)
- `tests/Feature/Orders/{OrderAllergenSnapshotTest,CleanupStalePendingOrdersTest,IdempotencyBranchScopedTest,KDSAllergenVisibilityTest,CrossItemGuardTest}.php` (nouveaux)
- `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php` (nouveau — 9.5.5)
- `tests/js/PosComponent.spec.js` (extension — 9.5.7)
- `tasks/phase9/FINDINGS_TRACKER.md`
- `tasks/phase9-sync/CROSS_TRACK_STATUS.md` (mise à jour statut P9.5 in_progress / merged)
- `tasks/phase9-sync/LOCK_A_*_P9_5_*.md` (nouveaux — posés avant édition frozen zones, retirés à la fin)
- `tasks/phase9-sync/BROADCAST_P9_5_MERGED_2026-04-18.md` (nouveau — après merge)

### Frozen zones (HALT — gate clearance requise via `.cursor/hooks/safety-check.sh`)

**Note P9.5.** Pendant P9.5, `FrontendOrderService`, `OrderService`, `PricingService` et `OrderItem.php` sont sous LOCK_A (gate cleared par message utilisateur 2026-04-18). Hors P9.5, ils restent frozen par défaut.

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/PricingService.php` (cœur SSOT — sauf gate explicite)
- `app/Services/OrderStateMachine.php` (transitions — sauf gate explicite)

Toute modification hors `SUBSYSTEMS_TOUCHED` ou dans une frozen zone DOIT être escaladée à l'humain via `tasks/phase9/P9_X_BLOCKER_<id>.md`.

---

## Vague P9.1 — Stop-the-bleed (P0 safety / tracking / RGPD)

**Objectif.** Corriger les 14 trouvailles P0 avec un effort minimal par item et un impact UX/safety maximal. Aucun changement de schéma ici — uniquement wirings, mutations Vuex, resources, props. **Bloque toutes les autres vagues.**

**Durée estimée.** 6-8 h de travail, 1 PR atomique par item.

| # | Item | Fichiers | Effort | Tests ajoutés |
|---|---|---|---|---|
| 9.1.1 | Exposer `is_available` + `allergens[]` dans `NormalItemResource` | `app/Http/Resources/NormalItemResource.php` | 15 min | Feature test `NormalItemResourceTest::test_includes_availability_and_allergens` |
| 9.1.2 | Intégrer `KsAllergenBadge` persistent dans header wizard | `KioskWizardComponent.vue:17-29` | 20 min | Vitest `KioskWizard.spec.js` nouveau cas `displays_allergen_badges_for_customer_collision` |
| 9.1.3 | Câbler `/api/frontend/pricing/preview` dans wizard avec debounce 400 ms | nouveau `kioskPricing.js::fetchServerPreview`, watch selections dans `KioskWizardComponent.vue` | 1 h | Vitest `kioskPricing.spec.js::fetchServerPreview_debounced_called_on_selection_change` |
| 9.1.4 | Listener `InvalidateKioskMenuCacheOnItemAvailability` → `Cache::forget("kiosk.menu.branch.{id}")` | nouveau `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` + binding `EventServiceProvider.php:101-104` | 30 min | Feature test `InvalidateKioskMenuCacheTest::test_cache_is_purged_on_availability_change` |
| 9.1.5 | Mutation `kioskMenu/UPDATE_ITEM` patch `is_available` + `unavailable_reason` | `resources/js/store/modules/kioskMenu.js:159-173` | 15 min | Vitest `kioskMenu.spec.js::update_item_patches_availability` |
| 9.1.6 | Champ code promo panier + appel `POST /api/frontend/promo/validate` | `KioskCartComponent.vue` (ajouter section promo collapsed) + action `kioskCart/applyPromoCode` | 1h30 | Vitest `KioskCart.spec.js::apply_valid_promo_code`, `reject_invalid_code` |
| 9.1.7 | Wire `KsVirtualKeyboard` sur inputs loyalty | `KioskLoyaltyComponent.vue` L27-117 | 1 h | Vitest a11y `KsVirtualKeyboard.spec.js::renders_in_loyalty_inputs` |
| 9.1.8 | Wire `useKioskSpeech` sur events critiques (order_completed, payment_accepted) | `KioskConfirmationComponent.vue` + `KioskPaymentComponent.vue` | 45 min | Vitest a11y `useKioskSpeech.spec.js::speak_called_on_order_completed` |
| 9.1.9 | Fix whitelist analytics `idle_warning` → `idle_warning_shown` (émettre le bon nom) | `KioskInactivityOverlayComponent.vue:130` | 5 min | Vitest `KioskInactivityOverlay.spec.js::emits_idle_warning_shown_not_idle_warning` |
| 9.1.10 | Fix event name mismatch `@accept`/`@accepted` dans loyalty consent | `KioskLoyaltyComponent.vue:228` ou `KsConsentModal.vue:297` (aligner sur `@accept`) | 10 min | Vitest `KioskLoyalty.spec.js::completes_register_after_consent_accept` |
| 9.1.11 | Redirection systématique `kiosk.error.payment-refused` après 2 échecs | `KioskPaymentComponent.vue:348-354` + router push | 30 min | Vitest `KioskPayment.spec.js::redirects_to_error_after_two_failures` |
| 9.1.12 | Persister last-order localStorage pour F5-proof receipt | nouveau `kioskReceiptPersistence.js` + `KioskConfirmationComponent.vue:236` | 45 min | Vitest `KioskConfirmation.spec.js::restores_receipt_on_reload` |
| 9.1.13 | Retirer chips dead UI "My Account" / "Allergens" ou les wire sur drawer A11y allergens | `KioskCategoriesComponent.vue:24-43` | 30 min | — |
| 9.1.14 | Fix 3 tests `FrontendSurfaceFilteringTest` (fallback `whereJsonContains` SQLite OU force CI MySQL) | `app/Services/ItemService.php:130-143` + `app/Services/ItemCategoryService.php:60-61` | 45 min | `FrontendSurfaceFilteringTest` full green |

**Gate P9.1.** Tous les P0 couverts. CI complète verte (sauf gaps documentés). Build prod < 27 s. Rapport `reports/execution/RUN_P9_1_KIOSK_YYYY-MM-DD.md` avec diff + evidence.

---

## Vague P9.2 — Catalog SSOT + real-time hardening (backend)

**Objectif.** Fermer la désync catalogue côté backend (ItemRequest/CategoryRequest complets, hierarchy service, allergens FR, FK, route toggle).

**Durée estimée.** 1 journée.

| # | Item | Fichiers | Effort | Tests |
|---|---|---|---|---|
| 9.2.1 | Étendre `ItemRequest::rules()` avec 12 flags (`is_chef_pick, is_new, is_available, is_spicy, is_vegetarian, is_pork_free, is_halal, is_gluten_free, chef_pick_order, channels, allergen_flags, kiosk_emoji`) | `app/Http/Requests/ItemRequest.php:28-49` | 30 min | `ItemRequestTest::test_accepts_all_kiosk_flags` |
| 9.2.2 | Étendre `ItemCategoryRequest::rules()` avec `parent_id, channels, kiosk_sort, pos_sort, kiosk_label` | `app/Http/Requests/ItemCategoryRequest.php:25-43` | 20 min | `ItemCategoryRequestTest::test_accepts_hierarchy_and_channels` |
| 9.2.3 | Créer `ItemCategoryHierarchyService::validateParent(parent, child)` + câblage `ItemCategoryService::store/update` | nouveau `app/Services/ItemCategoryHierarchyService.php` + call dans `ItemCategoryService` | 45 min | `ItemCategoryHierarchyTest::test_depth_2_enforced` |
| 9.2.4 | Aligner `AllergensSeeder` sur codes FR (migration rename + seeder idempotent via `updateOrCreate`) | `database/seeders/AllergensSeeder.php:22-35` + nouvelle migration `rename_allergen_codes_to_fr` | 30 min | `AllergensSeederTest::test_fr_codes_present` |
| 9.2.5 | FK `item_branch_availability.item_id → items`, `.branch_id → branches` | nouvelle migration ALTER + cascadeOnDelete | 20 min | `ItemBranchAvailabilityFkTest::test_cascade_on_item_delete` |
| 9.2.6 | Créer `AllergenService::projectFlags($item)` synchronisant JSON legacy ↔ pivot | nouveau `app/Services/AllergenService.php` + observer `ItemSaving` | 1 h | `AllergenServiceTest::test_pivot_sync_with_legacy_json` |
| 9.2.7 | Admin endpoint `POST /api/admin/menu/availability/toggle` | `app/Http/Controllers/Admin/AvailabilityController.php::toggle` + route + FormRequest | 1 h | `AvailabilityControllerTest::test_staff_can_toggle_item_availability` |
| 9.2.8 | Rate limit `throttle:kiosk-menu` 60/min sur `GET /menu` | `routes/api.php:929-932` + `RouteServiceProvider.php` | 10 min | `MenuControllerRateLimitTest::test_limit_exceeded_returns_429` |
| 9.2.9 | Events `ItemCreated/Deleted, CategoryCreated/Updated/Deleted` + listeners invalidate cache | nouvelles classes Event + bindings | 1 h | `CacheInvalidationTest::test_create_purges_menu_cache` |

**Gate P9.2.** Tous endpoints admin acceptent les nouveaux champs. Cache purge sur chaque CRUD. Codes allergènes FR.

---

## Vague P9.3 — Wizard robustness

**Objectif.** Rendre le wizard admin-independent (supprimer les substring FR), prévisible (pricing SSOT live), et testable (data-testid systematiques).

**Durée estimée.** 2 jours.

| # | Item | Fichiers | Effort | Tests |
|---|---|---|---|---|
| 9.3.1 | Migration `item_attributes.role` enum (`bread, meat, sauce, size, topping, drink, condiment`) + seeder rétrocompatible | nouvelle migration + seeder | 1 h | `ItemAttributeRoleTest::test_role_enum_values` |
| 9.3.2 | Refacto `kioskSauceCatalog.js`, `kioskViandeCatalog.js`, `kioskPainCatalog.js` pour utiliser `role` au lieu de substring | 3 helpers | 1 h | Vitest existants à adapter |
| 9.3.3 | Pricer chaque sauce extra individuellement (plus de first-priced) | `KioskWizardComponent.vue:578-586` + `kioskPricing.js` | 45 min | Vitest `kioskPricing.spec.js::sauces_with_heterogeneous_prices` |
| 9.3.4 | Supprimer fallback S/M/L/XL fabriqué → si pas d'attribut taille en DB, skip step | `KioskStepTailleComponent.vue` | 30 min | Vitest `KioskStepTaille.spec.js::no_fallback_when_db_empty` |
| 9.3.5 | Regex robuste `shouldAskTacosTaille` (`\\b(tacos)\\s+(m\|l\|xl)\\b`) OU exposer `items.kiosk_size_preset` | `KioskWizardComponent.vue:466-498` | 30 min | Vitest `KioskWizard.spec.js::no_false_positive_tacos_lroyal` |
| 9.3.6 | `data-testid` systématiques sur 7 steps (~40 IDs) | 7 step components | 1 h 30 | — |
| 9.3.7 | Tracker `wizard_abandoned` aussi sur recap | `KioskWizardComponent.vue:1060` | 10 min | Vitest `KioskWizard.spec.js::tracks_abandon_on_recap` |
| 9.3.8 | Ne pas pré-sélectionner `menuChoice='full'` (badge "Recommandé" à la place) | `KioskStepMenuComponent.vue` | 20 min | Vitest `KioskStepMenu.spec.js::no_auto_select_default` |
| 9.3.9 | Bouton "Tout désélectionner" sur garnitures + hint clair | `KioskStepGarnituresComponent.vue` | 30 min | Vitest nouveau cas |
| 9.3.10 | Uniformiser i18n `wizard.step.supplements.*` avec pattern autres steps | `fr.json, en.json, ar.json, KioskStepSupplementsComponent.vue` | 20 min | — |
| 9.3.11 | Listener Echo `ItemAvailabilityChanged` dans wizard → overlay `KioskErrorProductRemoved` si item en cours devient unavailable | `KioskWizardComponent.vue` mounted + beforeDestroy | 45 min | Vitest `KioskWizard.spec.js::shows_removed_overlay_on_echo` |

**Gate P9.3.** Admin renomme un attribut en EN/AR → wizard continue à fonctionner. Prix identiques client/serveur à ±0,01 €. E2E Playwright happy-path passe.

---

## Vague P9.4 — UX completeness (hors-wizard)

**Objectif.** Terminer les promesses UX : catalog search, filtres persistants, video idle reducedMotion, pricing hardening, confirmation QR.

**Durée estimée.** 1,5 jour.

| # | Item | Fichiers | Effort |
|---|---|---|---|
| 9.4.1 | Champ recherche catalog avec fuzzy match (`fuzzysort` ou équivalent léger <5 KB) + virtual keyboard | `KioskCategoriesComponent.vue` + `kioskAnalytics.js` (émettre `search_performed`) | 2 h |
| 9.4.2 | Persistance `activeFilters` via `kioskSettings/setCatalogFilters` | `kioskSettings.js` + `KioskCategoriesComponent.vue:664-687` | 30 min |
| 9.4.3 | CTA "Réessayer connexion" sur banderole cache offline | `KioskCategoriesComponent.vue:51-62` | 20 min |
| 9.4.4 | Auto-skip upsell pausé sur scroll (+3 s rolling extend) | `KioskUpsellComponent.vue:92-109` | 30 min |
| 9.4.5 | QR code sur receipt (lib `qrcode` légère <10 KB) | `KioskConfirmationComponent.vue` | 1 h |
| 9.4.6 | Scan NFC/QR loyalty from cart CTA (wire `kioskHardware.scanQR()`, `readNFC()`) | `KioskCartComponent.vue` + `KioskLoyaltyComponent.vue` | 1 h |
| 9.4.7 | `haptic('tap')` sur startOrder, add-to-cart, confirm | `kioskHardware.js` consumers à multiplier | 30 min |
| 9.4.8 | Video idle : `reducedMotion=true` → poster image au lieu d'autoplay | `KioskIdleScreenComponent.vue:60` | 20 min |
| 9.4.9 | Langue : supprimer `window.location.reload()`, updater `i18n.locale` + HTML attrs only | `KioskIdleScreenComponent.vue:189` | 30 min |
| 9.4.10 | URL analytics unifiée `frontend/kiosk-event` avec wrapper payload consistent | écrans d'erreur + cash-instruction + controller tolérance | 1 h |
| 9.4.11 | Healthcheck debounce (3 échecs avant `critical`) | `kioskHardware.js:287-304` | 45 min |
| 9.4.12 | Fix `beforeUnmount` dupliqué admin + file locale pour `_logAdminOverride` offline | `KioskAdminComponent.vue:400-408 + 761-766` | 30 min |

**Gate P9.4.** Client retrouve ses filtres entre navigations, peut appliquer un code promo, saisir sa carte loyalty one-tap, voir un QR sur ticket.

---

## Vague P9.5 — Order pipeline hardening

**Objectif.** Robustesse finale du pipeline commande (allergens snapshot, cleanup stale, idempotency scoped).

**Durée estimée.** 1 jour.

| # | Item | Fichiers | Effort | Tests |
|---|---|---|---|---|
| 9.5.1 | Migration `order_items.allergens_snapshot` JSON + persistance depuis `FrontendOrderService` | nouvelle migration + service | 1 h | `OrderAllergenSnapshotTest::test_kiosk_order_stores_allergens` |
| 9.5.2 | `KDSOrderDetailsResource` + `OrderItemResource` exposent `allergens_snapshot` + affichage KDS | resources + `KitchenDisplaySystemComponent.vue:404-427` | 45 min | Feature test `KDSAllergenVisibilityTest` |
| 9.5.3 | Job `CleanupStalePendingKioskOrders` (cron 5 min, transition PENDING→REJECTED si >15 min) | nouveau job + `app/Console/Kernel.php` | 1 h | `CleanupStalePendingOrdersTest::test_cancels_stale_orders` |
| 9.5.4 | Migration `idempotency_key` UNIQUE scopé `(branch_id, idempotency_key)` | nouvelle migration ALTER INDEX | 20 min | `IdempotencyBranchScopedTest::test_same_key_different_branches_ok` |
| 9.5.5 | E2E test `kiosk_order_full_flow_to_kds_with_variations_extras_instructions_allergens` | nouveau test Feature | 1 h | — |
| 9.5.6 | Cross-item guard activé systématiquement (pas uniquement kiosk) | `PricingRequest::forPos/forTable/forWeb` | 30 min | `CrossItemGuardTest::test_pos_also_enforces` |
| 9.5.7 | Payload POS drawer enrichi (variations/extras/instructions expandable) | `PosComponent.vue:599-605` | 1 h | Vitest `PosComponent.spec.js::drawer_expandable_details` |
| 9.5.8 | Retirer prix du payload client `kioskCart.js:235-258` (nettoyage) | `kioskCart.js` | 15 min | — |

**Gate P9.5.** KDS affiche allergens snapshotés. Pas de commande PENDING orpheline >15 min. Drawer POS complet.

---

## Vague P9.6 — Analytics + observability + admin

**Objectif.** Tracking exhaustif (funnel entier visible), observability proactive, admin panel productivité.

**Durée estimée.** 1 jour.

| # | Item | Fichiers |
|---|---|---|
| 9.6.1 | Split analytics ops (legitimate interest) vs marketing (opt-in) → `track()` accepte flag `category` | `kioskAnalytics.js:222-239` + revue légale |
| 9.6.2 | Events manquants : `cart_viewed`, `search_performed`, `filter_reset`, `promo_applied_success/failure`, `loyalty_scanned` | whitelist + émissions |
| 9.6.3 | Export logs admin (download JSON des 200 derniers events) | `KioskAdminComponent.vue` + endpoint backend |
| 9.6.4 | Sparkline uptime 24h healthcheck dans admin panel | IDB circulaire + lib chart légère |
| 9.6.5 | Toggle reset borne + clear caches depuis admin | `KioskAdminComponent.vue` |
| 9.6.6 | Notification Echo staff sur `critical` healthcheck (channel `private-branch-alerts.{id}`) | nouveau channel + event `HardwareStatusDegraded` |
| 9.6.7 | Dédoublonnage `payment_method_selected` (n'émet que si change réel) | `KioskPaymentComponent.vue` |

**Gate P9.6.** Dashboard admin FoodKing voit uptime borne + peut exporter les logs. Tracking funnel 100% des étapes.

---

## Vague P9.7 — i18n / a11y / PMR completeness

**Objectif.** Conformité EAA 2025 complète. PMR élargi. RTL correct.

**Durée estimée.** 0,5 jour.

| # | Item | Fichiers |
|---|---|---|
| 9.7.1 | PMR selector `tokens-pmr.css:73-80` inclut `[role=radio], [role=checkbox], [role=option], [role=tab]` | `tokens-pmr.css` |
| 9.7.2 | Élargir `.kiosk-wizard-close`, `.kiosk-progress-arrow` à 48×48 min (AA) / 64×64 PMR | `KioskWizardComponent.vue` styles |
| 9.7.3 | Règles `[dir="rtl"]` pour chevrons/icônes dans steps | `kiosk-wizard.css` + composants |
| 9.7.4 | Icônes emojis remplacées par SVG inline (fallback font-indépendant) | écrans d'erreur + carousel |
| 9.7.5 | Audit tokens-aaa ratio contrast 7:1 via stylelint-a11y | `tokens-aaa.css` + CI lint |
| 9.7.6 | Marquee promo-carousel `transform` RTL-safe | `KioskPromoCarouselComponent.vue:115-117` |

**Gate P9.7.** axe-core 0 violations AA sur tous les écrans. Tests RTL screenshots validés.

---

## Vague P9.8 — Tests E2E + CI green

**Objectif.** Boucler la boucle CI/CD : Playwright happy-path, coverage gaps, 3 tests rouges fixés.

**Durée estimée.** 1 jour.

| # | Item | Fichiers |
|---|---|---|
| 9.8.1 | Playwright test : flow complet idle → catalog → wizard (tacos) → cart → promo → upsell → payment card → waiting → confirmation | `tests/e2e/kiosk-happy-path.spec.ts` |
| 9.8.2 | Playwright test : allergen alert wizard + produit removed mid-session | `tests/e2e/kiosk-safety.spec.ts` |
| 9.8.3 | Feature test `test_replayed_order_same_idempotency_key_returns_existing` | `tests/Feature/` |
| 9.8.4 | Feature test `test_dispatch_domain_events_job_retries_on_envelope_mismatch` | `tests/Feature/` |
| 9.8.5 | Feature test `test_cross_branch_events_never_leak_on_private_channel` | `tests/Feature/` |
| 9.8.6 | Coverage gate : exiger `kiosk-related` files ≥ 80% lines coverage | `phpunit.xml` + `vitest.config.js` |
| 9.8.7 | CI MySQL (pas SQLite) pour tests JSON natif | `.github/workflows/ci.yml` |

**Gate P9.8.** 100% tests verts. Coverage kiosk ≥ 80%. 0 flaky sur 5 runs consécutifs.

---

## Vague P9.9 — Différenciateurs compétitifs (optionnels, value-add)

**Objectif.** Dépasser McDonald's / Burger King / Five Guys 2026. Aucun n'est bloquant ; chacun peut être scheduled selon priorisation business.

**Durée estimée.** 2-3 jours (sélectif selon budget).

| # | Item | Concurrent dépassé | Effort |
|---|---|---|---|
| 9.9.1 | Apple Pay / Google Pay via bridge TPE (param `method='APPLE_PAY'`) | McDonald's (no) | 1 jour |
| 9.9.2 | Mode Turbo : post-idle screen "Express" avec 3 combos bestseller → skip wizard → direct payment | KFC Express lane | 1 jour |
| 9.9.3 | Pairing app mobile FoodKing (scan QR client → pre-fill loyalty + préférences) | Burger King royal perks | 2 jours |
| 9.9.4 | Estimation temps de retrait dynamique (fetch branch queue depth) | Five Guys (no) | 0,5 jour |
| 9.9.5 | Promo "Plus que X min" countdown dynamique (ends_at DB) | (tous no) | 0,5 jour |
| 9.9.6 | Split payment cash+card multi-tender | Quick (no) | 1 jour |
| 9.9.7 | Écran Allergènes pré-catalogue obligatoire pour anonymes | EAA 2025 over-compliance | 0,5 jour |
| 9.9.8 | Re-print bouton avec fallback email si printer KO | (tous no) | 0,5 jour |
| 9.9.9 | Mode maintenance graceful (banderole + CTAs bloqués) | (tous no) | 0,5 jour |

**Gate P9.9.** Chaque diff compétitif livré avec screenshot + démo ~30 s (mp4 pour revue produit).

---

## Vague P9.10 — Build prod + rapport final + handoff

**Objectif.** Livrable production-ready documenté.

**Durée estimée.** 0,5 jour.

| # | Item | Livrable |
|---|---|---|
| 9.10.1 | `npm run production` build | Bundle stable, sizes documentées |
| 9.10.2 | `php artisan optimize:clear && php artisan config:cache && php artisan route:cache` | Cache warmed prod-like |
| 9.10.3 | Rapport `reports/execution/KIOSK_PHASE_9_FINAL_YYYY-MM-DD.md` | Phases cochées, evidence (screenshots Playwright, axe, Vitest/PHPUnit output), risques résiduels, diff lines-of-code |
| 9.10.4 | Mise à jour `CLAUDE.md` + `AGENTS.md` + `docs/ORDER_FLOW.md` avec nouveaux flows | — |
| 9.10.5 | `tasks/handoff/KIOSK_V2_HANDOFF.md` pour prochaine itération | — |

---

## Séquencement & dépendances

```
P9.1 (stop-the-bleed, BLOQUE tout)
 ├─→ P9.2 (backend catalog hardening)
 │    └─→ P9.3 (wizard robustness, dépend P9.2 pour role enum)
 │         └─→ P9.5 (order pipeline, dépend P9.3 pour allergens)
 ├─→ P9.4 (UX completeness, parallèle P9.2/3)
 ├─→ P9.6 (analytics/observability, parallèle)
 ├─→ P9.7 (i18n/a11y/PMR, parallèle)
 └─→ P9.8 (tests E2E, après P9.1 à P9.7)
      └─→ P9.9 (différenciateurs compétitifs, optionnel)
           └─→ P9.10 (build + rapport final)
```

**Durée totale minimum (critique).** 6 jours ouvrés pour P9.1 → P9.8 + P9.10. **12-14 jours ouvrés** si P9.9 complet.

---

## Recommandation immédiate (actions suivantes)

Je propose de démarrer **P9.1 (stop-the-bleed)** immédiatement car :
- Aucune dépendance amont.
- 14 items à effort faible-moyen (6-8 h cumulées).
- Impact UX/safety/RGPD immédiat (allergen wizard, pricing preview, virtual keyboard, consent fix, receipt persistence, 3 tests SurfaceFiltering rouges fixés).
- Débloque toutes les vagues suivantes.

**Deliverables P9.1 attendus à l'issue :**
1. PR unique `feat(kiosk/phase-9.1): stop-the-bleed P0 fixes` couvrant les 14 items.
2. `reports/execution/RUN_P9_1_KIOSK_YYYY-MM-DD.md` avec diff + evidence Vitest/PHPUnit verts + screenshots avant/après pour 3 changes UX visibles (allergen badge, virtual keyboard, promo field).
3. CI green (3 tests SurfaceFilteringTest réparés).
4. Build prod < 27 s.

Validation humaine demandée avant démarrage effectif.

## ESCALATION

- 2026-04-18 — EXECUTE P9.2 bloqué côté gouvernance: le plan ne contenait aucun bloc `SUBSYSTEMS_TOUCHED`. **Résolu** par le commit `af4139b01` qui ajoute la section `SUBSYSTEMS_TOUCHED` couvrant les périmètres P9.1 (clos) et P9.2 (actif), ainsi qu'un rappel des frozen zones et le pattern d'escalade BLOCKER. Voir aussi `tasks/phase9/P9_2_BLOCKER_SCOPE_GOVERNANCE_2026-04-18.md` qui consigne le blocker initial avant résolution.

## SYMMETRY_NOTE

- 2026-04-18 — P9.5.1 a touché `FrontendOrderService.php` de façon strictement additive pour enrichir les lignes `order_items` avec `allergens_snapshot` au moment du `insert()`. Vérifié: aucun changement sur pricing SSOT, idempotency, state machine, `branch_id` server-resolved, ni besoin de symétrie immédiate dans `OrderService.php` (hors scope P9.5 et toujours frozen).
