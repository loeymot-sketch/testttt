# AUDIT MANIFEST — Borne (Kiosk) System
**Date** : 2026-05-09
**Scope** : Système Borne complet — kiosk wizard, paiement card terminal, fiscal alloc finalize, FR-lock
**Branch** : `review/audit-borne-kiosk`
**Cible** : `/ultrareview <this-PR>` doit auditer tout le périmètre listé ci-dessous

---

## §1 — Files in scope (paths absolus)

### Backend Controllers + Services
- `app/Http/Controllers/Auth/KioskMachineLoginController.php`
- `app/Http/Controllers/Frontend/OrderController.php` (focus kiosk paths)
- `app/Http/Controllers/Frontend/MenuController.php`
- `app/Http/Controllers/Frontend/UpsellController.php`
- `app/Http/Controllers/Frontend/UpsellRecommendationController.php`
- `app/Http/Controllers/Frontend/LoyaltyController.php`
- `app/Http/Controllers/Frontend/KioskEventController.php`
- `app/Http/Controllers/Frontend/OrderRatingController.php`
- `app/Services/FrontendOrderService.php` (focus `myOrderStore`, `finalizePaidKioskOrder`)
- `app/Services/Recommendation/Strategies/RuleBasedStrategy.php`
- `app/Services/Recommendation/Strategies/MlPlaceholderStrategy.php`
- `app/Services/KioskSpeechService.php` (si existe)

### Backend Requests + Models
- `app/Http/Requests/OrderRequest.php` (focus kiosk path)
- `app/Http/Requests/Kiosk/PricingPreviewRequest.php`
- `app/Http/Requests/Kiosk/PromoValidateRequest.php`
- `app/Http/Requests/OrderStatusRequest.php` (focus kiosk:order ability)
- `app/Models/KioskMachine.php` (post-iter12 BranchScope)
- `app/Models/FrontendOrder.php`
- `app/Models/OrderRating.php`

### Middleware
- `app/Http/Middleware/ValidateKioskLocale.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` (focus kiosk:order branch resolve)

### Frontend Vue 3 — Kiosk
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (FROZEN)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (FROZEN)
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` (FROZEN)
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (owner-gate cleared iter6)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (owner-gate cleared iter6)
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (FR-lock ADR-007)
- `resources/js/components/frontend/kiosk/KioskMicConsentDialog.vue` (iter1 a11y focus restoration)
- `resources/js/components/frontend/kiosk/KioskVoiceOrderingDialog.vue` (iter1)
- `resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue`
- `resources/js/components/frontend/kiosk/builder/KioskBurgerBuilder.vue`
- `resources/js/services/kioskVoiceOrdering.js`
- `resources/js/i18n.js` (KIOSK_LOCALE FR-lock policy)
- `resources/js/locales/fr.json`, `en.json`, `ar.json` (kiosk.* namespace)

### Routes + Tests
- `routes/api.php` (focus kiosk-prefix routes)
- `tests/Feature/KioskSecurityTest.php`
- `tests/Feature/KioskSecurity/KioskEventAbilityTest.php`
- `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php`
- `tests/Feature/KioskScopeIsolationTest.php`
- `tests/Feature/KioskTokenExpirationTest.php`
- `tests/Feature/KioskPhase1/*Test.php`
- `tests/Feature/KioskPhase5/*Test.php`
- `tests/Feature/KioskPhase7/*Test.php`
- `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php`
- `tests/Feature/Frontend/OrderRatingTest.php`
- `tests/Feature/Recommendation/UpsellRecommendationTest.php`
- `tests/e2e/03-kiosk-wizard.spec.js`

---

## §2 — Invariants à vérifier

1. **Sanctum kiosk:order single-ability** — token créé avec `['kiosk:order']` UNIQUEMENT, jamais d'autres abilities mixed
2. **Pre-auth username lookup** — `withoutGlobalScope(BranchScope)` explicit (iter12) — pas d'enumeration cross-tenant
3. **Branch isolation** — KioskMachine + FrontendOrder + OrderRating tous BranchScope (iter11+12)
4. **Idempotency** — POST kiosk order via `X-Idempotency-Key` + branch resolve KioskMachine.branch_id (pas request payload trustable)
5. **Pricing SSOT** — kiosk envoie `item_id, quantity, options` UNIQUEMENT, backend calcule prix
6. **`composition_snapshot` frozen** — NEVER overwritten post-create
7. **Fiscal alloc finalize** — `finalizePaidKioskOrder` GATE-FZH-ALLOC iter14 (catch fail → flag fiscal_alloc_error_at + retry cron)
8. **FR-lock policy ADR-007** — `KIOSK_LOCALE='fr'` immutable, sélecteur langue masqué `v-if="false"`, voiceLang='fr-FR' constant
9. **Cross-branch payment block** — payment confirm avec different branch_id que order → 403 (KioskSecurityTest)
10. **Auto-rupture cascade** — `ItemAvailabilityChanged` event broadcast à kiosk → menu cache invalidate
11. **Frozen zones** — KioskWizard + KioskApp + KioskUpsell : 0 lignes diff vs main (4 fichiers protégés)

---

## §3 — Questions critiques

### Architecture
- Le wizard kiosk Vue 3 est-il vraiment frozen sur les 3 fichiers protégés ? `git diff main -- resources/js/components/frontend/kiosk/KioskWizardComponent.vue` = 0 lignes ?
- `KioskWizardComponent.vue` 1659 LOC : structure cohérente ? Composables bien découpés ?
- Owner-gate cleared iter6 : KioskCart + KioskPayment ont des modifs spacing/aria — propres ?
- `KioskBurgerBuilder.vue` post-iter1 : `role="application"` removed, swapLayer happy-dom guard. OK ?
- Voice ordering V2-4 : Phase A (button + service) — Phase B (intent parsing wizard) deferred. Cohérent V1 scope ?

### Payment flow kiosk
- Tap kiosk → wizard → cart → payment screen → TPE terminal → confirm amount → fiscal alloc → KDS broadcast. Tracé complètement ?
- Si TPE timeout : kiosk doit fallback "Payer en caisse" (cf KioskPaymentRefuse design) ?
- Idempotency replay : même `X-Idempotency-Key` 2× → 1 seul order créé ?
- Race condition : 2 tabs kiosk avec même machine_id → block ? (KioskSecurityTest test_kiosk_login_succeeds_when_machine_already_marked_logged_in revoke old token)

### i18n + a11y
- 95% kiosk i18n coverage (cf VISUAL-UI audit iter13) — les 5% restants ?
- WCAG 2.1.1 keyboard alt : burger builder swap reachable au clavier ?
- WCAG 2.4.3 focus restoration : KioskMicConsentDialog + KioskVoiceOrderingDialog (iter1 fixed) — toujours OK ?
- ar.json gap 59 keys (ADR-007 backlog Phase 2) — pas de raw labels affichés en runtime FR-lock ?

### Sécurité
- Pre-auth username enumeration (iter12 fix) : KioskMachineLoginController:48 utilise `withoutGlobalScope` explicit ?
- Cross-branch token spoofing : KioskEventBranchSpoofingTest passe ?
- Token TTL 480 min : adapté kiosk public space (RGPD) ?
- Promo code validation : pas de bypass via payload manipulation ?

---

## §4 — Acceptance criteria

CLEAN si :
- ✅ Aucun cross-tenant leak (KioskMachine + FrontendOrder + OrderRating BranchScope active)
- ✅ Sanctum kiosk:order strict (no other abilities, old token revoked on relogin)
- ✅ Pricing SSOT inviolable (no frontend price override)
- ✅ Fiscal alloc finalize avec retry cron (no orphan paid+seq=NULL)
- ✅ FR-lock cohérent (no setLocale calls, no raw labels visible)
- ✅ 4 frozen zones intactes (KioskWizard + KioskApp + KioskUpsell + KioskCartComponent post-7adeaaa9c only)
- ✅ Tests `KioskScopeIsolation` + `KioskSecurity` + `KioskPhase*` verts
- ✅ E2E `03-kiosk-wizard` 5/5 PASS

HEAL si :
- ⚠️ P1 raw labels Vue warn $t (cosmétique, BACKLOG)
- ⚠️ ar.json gap 59 keys (ADR-007 Phase 2)
- ⚠️ Voice ordering V2-4 Phase B deferred (V1 scope OK)

BLOCK si :
- ❌ Pre-auth enumeration possible (iter12 regression)
- ❌ Cross-branch order create
- ❌ Fiscal orphan paid+seq=NULL non recovery

---

## §5 — Out of scope

- POS Caisse — cf `AUDIT_CAISSE_POS_2026-05-09.md`
- KDS / OSS — cf manifests dédiés
- Synchronisation events cross-surface — cf `AUDIT_SYNC_EVENTS_2026-05-09.md`
- Admin dashboards
- Stock cross-system — cf `AUDIT_TRACKING_STOCK_2026-05-09.md`

---

## §6 — Reference

CLAUDE.md §7 (Frozen Zones), §8 (NF525), §9 (Multi-Tenant + Auth)
PROJECT_BRAIN.md §1, §6 DECISIONS LOG (Q1=A FR-lock, Q2=B archive)

— *Manifest pour `/ultrareview review/audit-borne-kiosk`. Audit ciblé Borne système.*
