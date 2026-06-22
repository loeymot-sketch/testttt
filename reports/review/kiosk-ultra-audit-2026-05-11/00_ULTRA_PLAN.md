# Kiosk Ultra Audit 2026-05-11 — Plan Maître 20 agents parallèles

> Orchestrateur : Claude Code (Opus 4.7 1M).
> Branche : `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `245e8ab57`.
> Mode : 20 sub-agents parallèles read-only, audit + plan + E2E proposition par
> domaine fonctionnel. Pas de modification code, livrables = rapports MD.

## §1 Mission

Audit massif et profond du **Kiosk Borne client** (surface
`/kiosk/idle` Vue 3 + backend Laravel) avec couverture maximale par
fonctionnalité, en parallèle pour gain wall-clock.

Chaque agent livre :
1. **AUDIT** — issues code/sécurité/UX/NF525 avec `file:line` citations
2. **PLAN** — remédiation priorisée P0/P1/P2/P3
3. **E2E** — inventaire tests existants + 3-5 nouveaux cas proposés

## §2 Context FoodKing kiosk

- Vue 3 SPA monté sur `/kiosk/idle` (route web) — backend Laravel API
- Auth : Sanctum `kiosk:order` ability single-scope, TTL 480min,
  KioskMachine login via PIN
- Pricing : SSOT backend `PricingService::calculateOrder`, frontend envoie
  `item_id + quantity + option_ids` uniquement, `composition_snapshot` frozen
  à création d'order
- Multi-tenant : `BranchScope` global sur Order/OrderItem/OrderPayment/
  KioskMachine/etc.
- Fiscal : `fiscal_sequence_no` monotonic per branch (alloc à création
  pour kiosk paid card flow), audit chain HMAC, NF525 6y retention
- i18n : FR-lock V1 (multi-locale v-if=false), AR RTL supporté, EAA 2025
  allergens mid-wizard
- Sync : Outbox + Pusher + polling 5s fallback vers KDS/OSS

## §3 Frozen zones — audit OK, modification interdite (owner gate requis)

| Fichier | Status |
|---|---|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | FROZEN — audit autorisé, fix code interdit ; tests autorisés |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | FROZEN — idem |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | FROZEN — idem |
| `public/js/pos-wizard.js` | POS frozen — hors scope kiosk mais flag si touché |
| `app/Services/Pricing/PricingService.php` | NF525 frozen — audit OK |
| `app/Services/Fiscal/*` | NF525 frozen — audit OK |
| `app/Models/Scopes/BranchScope.php` | frozen — audit OK |

## §4 Matrice 20 agents

### Frontend UI (12 agents)

| ID | Domaine | Fichiers cibles (verifiés présents) |
|---|---|---|
| **K01** | App root + Routing + Bootstrap | `KioskAppComponent.vue` (frozen) + `router/modules/kioskRoutes.js` (12.9KB) + `bootstrap-kiosk.js` |
| **K02** | Idle/Welcome screen | `KioskIdleScreenComponent.vue` (design refresh target light mode) |
| **K03** | Login + Inactivity | `KioskLoginComponent.vue` + `KioskInactivityOverlayComponent.vue` |
| **K04** | Categories + Promo Carousel | `KioskCategoriesComponent.vue` + `KioskPromoCarouselComponent.vue` |
| **K05** | Wizard core | `KioskWizardComponent.vue` (frozen) + `KioskPosWizardComponent.vue` |
| **K06** | Wizard steps (9) | `steps/KioskStepViande.vue` + Sauce + Garnitures + Pain + Taille + FritesStyle + Supplements + Menu + GenericChoices |
| **K07** | Cart + bottom-sheet | `KioskCartComponent.vue` + `ds/KsCartBottomSheet.vue` |
| **K08** | Order Summary + Loyalty | `KioskOrderSummaryComponent.vue` + `KioskLoyaltyComponent.vue` |
| **K09** | Upsell + Toasts | `KioskUpsellComponent.vue` (frozen) + `KioskToastComponent.vue` + `CatalogChangeToastComponent.vue` |
| **K10** | Payment + Cash instruction | `KioskPaymentComponent.vue` + `KioskCashInstructionComponent.vue` |
| **K11** | Confirmation + Waiting | `KioskConfirmationComponent.vue` + `KioskWaitingComponent.vue` |
| **K12** | Error states + Offline conflict | 4 `KioskError*.vue` + `KioskOfflineConflictModalComponent.vue` |

### Frontend logic (3 agents)

| ID | Domaine | Fichiers cibles |
|---|---|---|
| **K13** | Pricing/composition helpers | `helpers/kioskPricing.js` + `kioskPricingPreview.js` + `kioskFormatPrice.js` + `kioskExtrasPartition.js` + `kioskMenuBundledExtras.js` + `kioskTacosSize.js` + `kioskSandwichSplit.js` |
| **K14** | Catalog/RTL/allergens helpers | `kioskFilters.js` + `kioskItemDisplayOrder.js` + `kioskCategoryOrder.js` + `kioskDisplayText.js` + `kioskViandeCatalog.js` + `kioskSauceCatalog.js` + `kioskDrinkAddons.js` + AllergenMerge spec |
| **K15** | Hardware/Offline/Speech | `services/kioskHardware.js` + `helpers/kioskHardware.js` (config) + `kioskPrinter.js` + `kioskReceiptPersistence.js` + `kioskOfflineQueue.js` + `kioskOfflineQueueDb.js` + `kioskAnalytics.js` + `composables/useKioskSpeech.js` + `useKioskA11y.js` + `useKioskTheme.js` |

### Backend (4 agents)

| ID | Domaine | Fichiers cibles |
|---|---|---|
| **K16** | Auth kiosk machine + Sanctum | `app/Http/Controllers/Auth/KioskMachineLoginController.php` + `app/Models/KioskMachine.php` + `app/Services/KioskMachineService.php` + `app/Http/Middleware/ValidateKioskLocale.php` + Sanctum abilities + P0-07 RefreshTokenController + P0-08 abilities:kiosk:order |
| **K17** | Menu API + cache + locale | `app/Http/Controllers/Frontend/MenuController.php` (méthode `kiosk`) + `app/Services/Kiosk/KioskMenuService.php` + `app/Listeners/InvalidateKioskMenuCacheOn*.php` + locale middleware |
| **K18** | Order creation + payment + idempotency | `FrontendOrderController` + `IdempotencyKeyMiddleware` + `FiscalSequenceService` alloc kiosk paid + `PendingPaymentConfirmation` + route `/api/frontend/order` + `/api/admin/collect-kiosk-cash` |
| **K19** | Admin Setup + Promo + Cleanup | `KioskMachineController` + `KioskSetupController` + `KioskPromoService` + `KioskSetupService` + `Jobs/CleanupStalePendingKioskOrders.php` + routes `/api/admin/kiosk-machine` + `/api/admin/kiosk-setup` |

### Cross-cutting (1 agent)

| ID | Domaine | Scope |
|---|---|---|
| **K20** | NF525 + Branch isolation + A11y/i18n cross-surface | Pricing SSOT contract frontend↔backend (composition_snapshot intact, jamais overwritten) + BranchScope sur les models touchés kiosk + Sanctum kiosk:order ability scope strict + FR-lock V1 + AR RTL support + WCAG 2.1 AA + EAA 2025 allergens mid-wizard + Frozen-zone drift detection (5 fichiers) |

## §5 Output convention

Chaque agent écrit son rapport à :
```
reports/review/kiosk-ultra-audit-2026-05-11/<NN>_<DOMAIN_SLUG>.md
```

Format du rapport (template strict) :
```
# K<NN> — <Domain title>

## Files audited
- path:line_count + (FROZEN) tag si applicable

## Findings
### P0 (blocker pre-merge V1)
- K<NN>-P0-XX: <one-line title>
  - File: path:line
  - Issue: <précis, 2-3 lignes>
  - Evidence: <code snippet ou comportement observable>
  - Suggested fix: <action concrète>

### P1 (high) / P2 (medium) / P3 (low)
- même format

## Existing E2E coverage
- tests/js/<spec> — couvre <quoi>

## Proposed new E2E tests
- T-K<NN>-01: <scénario>
  - Steps: <gherkin ou playwright snippet>
  - Assertions: <ce qu'il faut vérifier>

## Risks & open questions
- <pour owner gate si applicable>
```

## §6 Constraints absolues (tous agents)

1. **READ-ONLY** — ne JAMAIS modifier code ni tests
2. **NE PAS exécuter Playwright** — main thread orchestre l'exécution live après synthèse
3. **Citations file:line obligatoires** — vérifier en lisant le fichier réel, ne pas inventer
4. **Frozen-zones** — audit OK, propositions de fix marquées `[OWNER GATE REQUIRED]`
5. **Pas de hallucination** — si une fonction n'existe pas, dire "not found" plutôt que d'inventer
6. **Output sous 1500 mots** par rapport
7. **Severity discipline** : P0 = block V1 merge, P1 = V1.0.1 sprint, P2 = backlog priorisé, P3 = nice-to-have

## §7 Synthesis (main thread après agents)

Quand tous les rapports rentrent :
1. Lire les 20 fichiers
2. Cross-validation : flags confirmés par 2+ agents promus en haute confiance
3. Matrice consolidée findings (P0/P1/P2/P3 globale)
4. Verdict GO/NO-GO V1 sur le kiosk
5. Top 5 actions prioritaires
6. Push épisode Graphiti `foodking` group
7. Update PROJECT_BRAIN.md §2/§3/§8

## §8 Méthodologie evidence-driven (cf. memory feedback)

- ✅ Cross-validation 2+ agents indépendants → confiance haute
- ✅ Adversarial framing (chaque agent cherche ce qui ne marche pas, pas
  ce qui marche)
- ✅ Citations primaires obligatoires (code lu, pas remembered)
- ❌ Pas de "Box Familiale/Solo/Nashville" hallucinés — Le Cayenne SSOT
  vérifiable
- ❌ Pas de palette "Cayenne red" inventée — palette officielle =
  noir/rouge/jaune/blanc (cf. project_kiosk_design_refresh_2026-05-10.md)
