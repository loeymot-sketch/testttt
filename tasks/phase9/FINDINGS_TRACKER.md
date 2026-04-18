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
| 9.1.7 | Wire `KsVirtualKeyboard` sur inputs loyalty | P0 | `KioskLoyaltyComponent.vue:27-117` | fixed | (pending) | — |
| 9.1.8 | Wire `useKioskSpeech` sur events critiques (order / payment) | P0 | `KioskConfirmationComponent.vue` + `KioskPaymentComponent.vue` | open | — | — |
| 9.1.9 | Fix whitelist analytics `idle_warning` → `idle_warning_shown` | P0 | `KioskInactivityOverlayComponent.vue:130` | open | — | — |
| 9.1.10 | Fix event name mismatch `@accept` / `@accepted` loyalty consent | P0 | `KioskLoyaltyComponent.vue:228` ou `KsConsentModal.vue:297` | open | — | — |
| 9.1.11 | Redirection `kiosk.error.payment-refused` après 2 échecs | P0 | `KioskPaymentComponent.vue:348-354` | open | — | — |
| 9.1.12 | Persister last-order localStorage (F5-proof receipt) | P0 | nouveau `kioskReceiptPersistence.js` + `KioskConfirmationComponent.vue:236` | open | — | — |
| 9.1.13 | Retirer/wire chips dead UI "My Account" / "Allergens" | P0 | `KioskCategoriesComponent.vue:24-43` | open | — | — |
| 9.1.14 | Fix 3 tests `FrontendSurfaceFilteringTest` (MySQL CI) | P0 | `.github/workflows/ci.yml` + `phpunit.xml` | open | — | — |

**Gate P9.1.** Tous `verified`. Vitest + PHPUnit full green. Build prod < 27 s. axe-core 0 AA violations sur écrans touchés.

---

## Vague P9.2 — Catalog SSOT + real-time hardening (9 items P1)

| id | title | criticity | file:line | status | commit_sha | verifier_agent_run |
|---|---|---|---|---|---|---|
| 9.2.1 | `ItemRequest::rules()` + 12 flags | P1 | `app/Http/Requests/ItemRequest.php` | open | — | — |
| 9.2.2 | `ItemCategoryRequest::rules()` + hierarchy/channels | P1 | `app/Http/Requests/ItemCategoryRequest.php` | open | — | — |
| 9.2.3 | `ItemCategoryHierarchyService::validateParent` | P1 | `app/Services/ItemCategoryHierarchyService.php` | open | — | — |
| 9.2.4 | `AllergensSeeder` codes FR | P1 | `database/seeders/AllergensSeeder.php` | open | — | — |
| 9.2.5 | FK `item_branch_availability` | P1 | migration ALTER | open | — | — |
| 9.2.6 | `AllergenService::projectFlags` | P1 | `app/Services/AllergenService.php` | open | — | — |
| 9.2.7 | Endpoint admin availability toggle | P1 | `AvailabilityController::toggle` | open | — | — |
| 9.2.8 | Rate limit `throttle:kiosk-menu` | P2 | `routes/api.php` | open | — | — |
| 9.2.9 | Events cache invalidation CRUD admin | P1 | nouvelles classes Event | open | — | — |

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
| 9.5.1 → 9.5.8 | Order pipeline hardening (allergens snapshot, stale cleanup…) | P0/P1 | open | — | — |
| 9.6.1 → 9.6.7 | Analytics + observability + admin | P1/P2 | open | — | — |
| 9.7.1 → 9.7.6 | i18n / a11y / PMR completeness | P1 | open | — | — |
| 9.8.1 → 9.8.7 | Tests E2E + CI green | P0 | open | — | — |
| 9.9.1 → 9.9.9 | Différenciateurs compétitifs (optionnels) | P3 | open | — | — |
| 9.10.1 → 9.10.5 | Build prod + rapport final + handoff | — | open | — | — |

---

## Journal des mises à jour

- **2026-04-18 — initialisation.** 14 lignes P9.1 créées `open`. Lignes placeholder P9.2 → P9.10 ajoutées pour visibilité. Tracker activé.
