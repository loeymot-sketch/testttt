# 01 — ARCHITECT — Borne (Kiosk) layer cohesion + frozen zones

**Verdict local** : ✅ **GO** (0 V1-blocking issues)
**Methodology** : Read-only validation (not discovery), file:line citations required, max 5 findings ranked.

---

## Findings (5)

### [P0] [V1] Backend price SSOT maintained ✅ (validation positive)

Kiosk frontend envoie strictement `item_id, quantity, item_variations, item_extras` à `/frontend/order` ;
serveur recompute prices via `PricingService.calculateOrder()`. **Invariant NF525 SSOT respecté.**
Aucun calcul price client-side ne touche order submission.

- `resources/js/store/modules/kioskCart.js:91-105` (sanitize before submit)
- `app/Services/Kiosk/PricingPreviewService.php:59-79` (server recompute)

### [P1] [V1] Kiosk promo carousel integrity ✅ (validation positive)

`KioskPromoCarouselComponent` lit metadata server-driven uniquement. Promo validation déférée
à `POST /frontend/promo/validate` (`kioskCart.js:519-555`). SSOT préservée. **Pas de régression
`kiosk.promo` collapsed sur HEAD courant.**

- `resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue:1-80`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue:48` (mount point)

### [P2] [V1] Layer boundary intact ✅ (validation positive)

Zéro `axios` direct dans frozen zones (KioskWizard, KioskApp, KioskUpsell). Tout HTTP passe
par Vuex store actions (`kioskCart.submitOrder`, `quoteOrder`, `validatePromo`). Pas de
localStorage price caching dans surface kiosk.

⚠️ **CAVEAT post-réconciliation** : cette validation s'appliquait à la *cohérence applicative* ;
elle n'a PAS vérifié `git diff main -- frozen-files` qui aurait révélé le drift gouvernance
P0-15 du POS audit (2,597 insertions / 419 deletions sur 5/6 frozen files). Méthodologie gap
reconnu.

- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` (no axios imports detected)

### [P1] [V1.0.1] Dead code — `kioskAnalyticsPlugin` underutilized

Le plugin est instantié dans le store mais aucun composant kiosk ne dispatch `track()`
pour session lifecycle (login, logout, idle-timeout). Module wired mais sous-utilisé.
**Non-bloquant.** Recommandation : documenter usage prévu dans PHASE 9.2+ OU retirer
si abandonné.

- `resources/js/store/plugins/kioskAnalyticsPlugin.js` (define-time, no callers in `kiosk/**/*.vue`)

### [P2] [V1.x backlog] Composable duplication — `useKioskSpeech`

Utilisé séparément par `KioskConfirmationComponent` et `KioskPaymentComponent`. Pourrait être
hoisted au root `KioskAppComponent` pour unifier lifecycle. Pas un blocker, refactor candidate
post-V1.

- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`

---

## Méta-result Architect

- **0 V1-blocking issues** sur la cohérence applicative Borne
- **NF525 invariant respecté** sur l'ensemble du flow Kiosk → Backend
- **Gap méthodologique reconnu** : pas de `git diff main` sur frozen files (le POS audit l'a fait, P0-15)
- **iter15 meta-lesson honored** : "11 amendments → 1 applied. Evidence over speculation."
