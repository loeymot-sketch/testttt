# HEAL — Borne : bloc PROMO/fidélité masqué (rabais jamais appliqué)

**Système** : Borne (kiosk) · **Round** : r1 · **Date** : 2026-06-26 · **Lentille** : client (paie le plein tarif alors que le panier promet « −X € »)

## Défaut (W2 audit, prouvé)
`KioskCartComponent.vue` affichait un bloc code-promo (l.275) + bouton fidélité (l.328)
gardés par `v-if="discountsEnabled"`. Ce flag (`window.foodkingConfig.discountsEnabled`
= `config('pos.manual_discount_enabled')`) est **TRUE** et **PARTAGÉ** avec la remise
manuelle POS + `CheckoutComponent.vue` (web) → impossible de le mettre à false.
La borne envoie `kiosk_promo_code` (métadonnée), **jamais `coupon_id`** → backend
n'applique rien : panier menteur, client encaissé plein tarif.

## Heal (kiosk-spécifique, fail-safe, réversible, NON-frozen)
Flag DÉDIÉ borne, défaut FALSE (caché), sans toucher au flag partagé :
1. `config/kiosk.php` — `$promoEnabled = filter_var(env('KIOSK_PROMO_ENABLED', false), FILTER_VALIDATE_BOOLEAN)` + clé `'promo_enabled'` ajoutée dans **les 2 branches de return** (requireForm + défaut prod ; leçon RED-08 : un flag absent d'une branche casse l'override env).
2. `resources/views/master.blade.php` — `kioskPromoEnabled: @json((bool) config('kiosk.promo_enabled', false)),` après `discountsEnabled:`.
3. `KioskCartComponent.vue` — computed `kioskPromoEnabled()` (strict `=== true`, défaut FALSE si config absente) ; les 2 `v-if` → `v-if="discountsEnabled && kioskPromoEnabled"` (l.275 promo, l.328 fidélité).

## Fichiers touchés
- `config/kiosk.php` (+20)
- `resources/views/master.blade.php` (+7)
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` (+24)
- `tests/js/kioskCartPromoGate.spec.js` (NEW)
- `tests/js/KioskCartRestyle.spec.js` (+6, test-only : son `beforeEach` ajoute `kioskPromoEnabled:true` pour que ses assertions de STRUCTURE restent vertes — sinon la « loyalty button shown » cassait, gate l.328 nouvellement appliqué)

## TDD rouge → vert
`tests/js/kioskCartPromoGate.spec.js` :
- computed isolé : FALSE si config absente / clé absente / `false` / truthy non-strict (`1`,`"true"`) ; TRUE seulement `=== true`.
- condition combinée : partagé ON + gate OFF → **caché** (le bug) ; les deux ON → **visible**.
- composant MONTÉ (@vue/test-utils, pattern KioskCartRestyle) : `kiosk-cart-promo` + `kiosk-cart-loyalty-btn` absents quand gate OFF/clé absente, présents quand les deux ON.
- Rouge attendu sans le heal (v-if non gardé → bloc présent même gate OFF) → vert après.

## Gates (evidence)
- `npx vitest run kioskCartPromoGate kioskCartPromo KioskCartRestyle` → **23/23 PASS** (nouveau gate + store intact + régression réparée).
- `studioFrontendI18nParity` + `labelKeyParityFrontend` → **9/9 PASS** (aucune nouvelle clé i18n, refs existantes uniquement).
- `git diff --stat` frozen (pos-wizard.js/css, admin-pos-v4, PaymentComponent, PosV5TrancheRow, KioskWizard/App/Upsell, PricingService, Fiscal*) → **0 ligne**.
- `php -l config/kiosk.php` → no syntax errors.
- **POS + checkout intacts** : `pos.manual_discount_enabled` / `discountsEnabled` non modifiés ; `config/pos.php` et `CheckoutComponent.vue` HORS diff. Le gate ajoute une condition AND borne-only, ne retire rien aux autres surfaces.

## Statut
GREEN — bloc promo/fidélité borne caché par défaut (KIOSK_PROMO_ENABLED absent/false). Réversible (flip env). Non committé (rebuild bundle = superviseur).
