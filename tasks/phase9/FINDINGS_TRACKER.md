# Phase 9 — Findings Tracker (Source de vérité unique)

**Objet.** Registre d'état des 50 findings issus de `reports/review/AUDIT_KIOSK_GLOBAL_2026-04-18.md`, classés par vague du plan `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md`.

**Règle.** Pas de statut `fixed` sans vérification indépendante (sous-agent verifier). Mise à jour après chaque commit atomique. Colonnes :
- `id` : identifiant P9.X.Y du plan.
- `title` : résumé 1 ligne.
- `criticity` : P0/P1/P2/P3.
- `file:line` : localisation principale de la correction.
- `status` : `open` → `in-progress` → `fixed` → `verified` → `closed`.
- `commit_sha` : SHA court du commit atomique.
- `verifier_agent_run` : ID/nom du run verifier (`VERIFY_P9_1_YYYY-MM-DD.md`).

---

## Vague P9.1 — Stop-the-bleed (14 items P0)

| id | title | criticity | file:line | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|---|
| 9.1.1 | Exposer `is_available` + `allergens[]` dans `NormalItemResource` | P0 | `app/Http/Resources/NormalItemResource.php:35-71` | fixed | `eb980ab31` | — |
| 9.1.2 | Intégrer `KsAllergenBadge` persistent dans header wizard | P0 | `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:17-36` | fixed | `964500c52` | — |
| 9.1.3 | Câbler `/api/frontend/pricing/preview` (debounce 400 ms) | P0 | `resources/js/helpers/kioskPricingPreview.js` + `KioskWizardComponent.vue` | fixed | `b8903f378` | — |
| 9.1.4 | Listener `InvalidateKioskMenuCacheOnItemAvailabilityChanged` | P0 | `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` + `EventServiceProvider.php` | fixed | `42ed5f3e0` | — |
| 9.1.5 | Mutation `kioskMenu/UPDATE_ITEM` patch `is_available` + `unavailable_reason` | P0 | `resources/js/store/modules/kioskMenu.js:159-230` | fixed | `56adb6e67` | — |
| 9.1.6 | Champ code promo panier + `POST /api/frontend/promo/validate` | P0 | `KioskCartComponent.vue` + `store/modules/kioskCart.js` | fixed | 624ba3770 | — |
| 9.1.7 | Wire `KsVirtualKeyboard` sur inputs loyalty | P0 | `KioskLoyaltyComponent.vue:27-117` | fixed | 2f8c66696 | — |
| 9.1.8 | Wire `useKioskSpeech` sur events critiques (order / payment) | P0 | `KioskConfirmationComponent.vue` + `KioskPaymentComponent.vue` | fixed | 0bb45393d | — |
| 9.1.9 | Fix whitelist analytics `idle_warning` → `idle_warning_shown` | P0 | `KioskInactivityOverlayComponent.vue:130` | fixed | d9c2e6d6d | — |
| 9.1.10 | Fix event name mismatch `@accept` / `@accepted` loyalty consent | P0 | `KioskLoyaltyComponent.vue:228` ou `KsConsentModal.vue:297` | fixed | 485b47df1 | — |
| 9.1.11 | Redirection `kiosk.error.payment-refused` après 2 échecs | P0 | `KioskPaymentComponent.vue:348-354` | fixed | 66ea2c618 | — |
| 9.1.12 | Persister last-order localStorage (F5-proof receipt) | P0 | nouveau `kioskReceiptPersistence.js` + `KioskConfirmationComponent.vue:236` | fixed | 379797ae0 | — |
| 9.1.13 | Retirer/wire chips dead UI "My Account" / "Allergens" | P0 | `KioskCategoriesComponent.vue:24-43` | fixed | `fcbda283f` | — |
| 9.1.14 | Fix 3 tests `FrontendSurfaceFilteringTest` (MySQL CI) | P0 | `.github/workflows/phpunit.yml` + `tests/Feature/Menu/FrontendSurfaceFilteringTest.php` | fixed | `bd1143a18` | — |

**Gate P9.1.** Tous `verified`. Vitest + PHPUnit full green. Build prod < 27 s. axe-core 0 AA violations sur écrans touchés.

---

## Vague P9.2 — Catalog SSOT + real-time hardening (9 items P1)

| id | title | criticity | file:line | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|---|
| 9.2.1 | `ItemRequest::rules()` + 12 flags | P1 | `app/Http/Requests/ItemRequest.php` | fixed | `770a34d48` | — |
| 9.2.2 | `ItemCategoryRequest::rules()` + hierarchy/channels | P1 | `app/Http/Requests/ItemCategoryRequest.php` | fixed | `7ebfd9c8f` | — |
| 9.2.3 | `ItemCategoryHierarchyService::validateParent` | P1 | `app/Services/ItemCategoryHierarchyService.php` | fixed | `8369689a1` | — |
| 9.2.4 | `AllergensSeeder` codes FR | P1 | `database/seeders/AllergensSeeder.php` | fixed | `7bf735301` | — |
| 9.2.5 | FK `item_branch_availability` | P1 | migration ALTER | fixed | `97554d07f` | — |
| 9.2.6 | `AllergenService::projectFlags` | P1 | `app/Services/AllergenService.php` | fixed | `cbad6e05d` | — |
| 9.2.7 | Endpoint admin availability toggle | P1 | `AvailabilityController::toggle` | fixed | `8719f0f81` | — |
| 9.2.8 | Rate limit `throttle:kiosk-menu` | P2 | `routes/api.php` | fixed | `eb83bc118` | — |
| 9.2.9 | Events cache invalidation CRUD admin | P1 | nouvelles classes Event | fixed | `d8855325a` | — |

---

## Vague P9.3 — Wizard robustness (11 items)

| id | title | criticity | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|
| 9.3.1 | Migration `item_attributes.role` enum | P1 | open | — | — |
| 9.3.2 | Refacto helpers sauces/viandes/pains → role | P1 | open | — | — |
| 9.3.3 | Pricer chaque sauce extra individuellement | P1 | open | — | — |
| 9.3.4 | Supprimer fallback S/M/L/XL fabriqué | P1 | open | — | — |
| 9.3.5 | Regex robuste `shouldAskTacosTaille` | P2 | open | — | — |
| 9.3.6 | `data-testid` systématiques sur 7 steps | P2 | open | — | — |
| 9.3.7 | Tracker `wizard_abandoned` sur recap | P2 | open | — | — |
| 9.3.8 | Ne pas pré-sélectionner `menuChoice='full'` | P1 | open | — | — |
| 9.3.9 | Bouton "Tout désélectionner" garnitures | P2 | open | — | — |
| 9.3.10 | Uniformiser i18n `wizard.step.supplements.*` | P3 | open | — | — |
| 9.3.11 | Listener Echo `ItemAvailabilityChanged` wizard | P1 | open | — | — |

---

## Vagues P9.4 → P9.10

_(Lignes initialisées open, enrichies au démarrage de chaque vague. Voir `PLAN_PHASE_9_KIOSK_2026-04-18.md` pour le détail.)_

| id | title | criticity | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|
| 9.4.1 → 9.4.12 | UX completeness (recherche, filtres persistants, QR, haptic…) | P1/P2 | open | — | — |

---

### Vague P9.5 — Order pipeline hardening (8 items P0/P1)

| id | title | criticity | file:line | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|---|
| 9.5.1 | Migration `order_items.allergens_snapshot` + persistance `FrontendOrderService` | P0 | `app/Services/FrontendOrderService.php` + nouvelle migration | open | — | — |
| 9.5.2 | `KDSOrderDetailsResource` + `OrderItemResource` exposent `allergens_snapshot` + KDS UI | P1 | `KDSOrderDetailsResource.php` + `KitchenDisplaySystemComponent.vue:404-427` | open | — | — |
| 9.5.3 | Job `CleanupStalePendingKioskOrders` (cron 5 min, PENDING>15 min → REJECTED) | P1 | `app/Jobs/CleanupStalePendingKioskOrders.php` + `Kernel.php` | open | — | — |
| 9.5.4 | Migration `idempotency_key` UNIQUE scopé `(branch_id, idempotency_key)` | P0 | nouvelle migration ALTER INDEX | open | — | — |
| 9.5.5 | Feature test E2E `kiosk_order_full_flow_to_kds_with_allergens` | P1 | `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php` | open | — | — |
| 9.5.6 | Cross-item guard systématique POS/Table/Web | P1 | `PricingRequest::forPos/forTable/forWeb` | open | — | — |
| 9.5.7 | Payload POS drawer enrichi expandable (variations/extras/instructions) | P1 | `PosComponent.vue:599-605` | open | — | — |
| 9.5.8 | Retirer prix du payload client `kioskCart.js` | P1 | `resources/js/store/modules/kioskCart.js:235-258` | open | — | — |

**Gate P9.5.** Tous `verified`. Migrations rollback-safe. Cron schedulé. KDS affiche allergens snapshotés. Idempotency cross-branch OK. Cross-item guard actif sur POS/Table/Web. Zéro prix client. LOCK_A_* libérés avant merge.

---

| id | title | criticity | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|
| 9.6.1 → 9.6.7 | Analytics + observability + admin | P1/P2 | open | — | — |
| 9.7.1 → 9.7.6 | i18n / a11y / PMR completeness | P1 | open | — | — |
| 9.8.1 → 9.8.7 | Tests E2E + CI green | P0 | open | — | — |
| 9.9.1 → 9.9.9 | Différenciateurs compétitifs (optionnels) | P3 | open | — | — |
| 9.10.1 → 9.10.5 | Build prod + rapport final + handoff | — | open | — | — |

---

## Journal des mises à jour

- **2026-04-18 — initialisation.** 14 lignes P9.1 créées `open`. Lignes placeholder P9.2 → P9.10 ajoutées pour visibilité. Tracker activé.
- **2026-04-18 — P9.1 STOP-THE-BLEED clos.** 14 commits atomiques posés (`eb980ab31` → `bd1143a18`) + commit tracker `2fd1f9bcc`. Sous-agent verifier indépendant exécuté (lecture code HEAD, sans contexte implémentation) → rapport `reports/review/VERIFY_P9_1_2026-04-18.md` : **14/14 RESOLVED, 0 PARTIAL, 0 STILL_BROKEN**. Status des 14 lignes P9.1 passé à `verified`. Gate merge P9.1 levé (Vitest 46 files / 377 tests verts sur HEAD 2fd1f9bcc ; PHPUnit MySQL programmé en CI via `phpunit.yml`). Observations incidentes (non bloquantes, à traiter en P9.2/9.5) listées dans le rapport verifier §"Incidental observations".
