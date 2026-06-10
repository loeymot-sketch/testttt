# W-A BORNE — BREAKDOWN code-first (2026-06-10)

Source : `resources/js/router/modules/kioskRoutes.js` + `resources/js/components/frontend/kiosk/*.vue` (HEAD spine b4389d34e). Serveur cible : clone jetable `foodking_e2e` http://127.0.0.1:8766.

## Routes kiosk (kioskRoutes.js)
| Route | Composant | Guard |
|---|---|---|
| /kiosk/login | KioskLoginComponent (écran retry/diagnostic, pas de formulaire) | kioskLoginRouteGuard |
| /kiosk/idle | KioskIdleScreenComponent | requireKioskAuth (auto-login si kioskAutoLogin) |
| /kiosk/categories?cat=N | KioskCategoriesComponent | requireKioskAuth |
| /kiosk/products/:categoryId | redirect legacy → categories | — |
| /kiosk/wizard/:itemId | KioskWizardComponent (KioskPosWizardComponent = wrapper délégant si KIOSK_USE_POS_WIZARD) | requireKioskAuth |
| /kiosk/cart | KioskCartComponent | requireKioskAuth |
| /kiosk/loyalty | KioskLoyaltyComponent | requireCart (panier non vide) |
| /kiosk/upsell | KioskUpsellComponent (FROZEN) | requireCart |
| /kiosk/payment | KioskPaymentComponent | requireCart |
| /kiosk/waiting/:orderId | KioskWaitingComponent | requireOrderRef |
| /kiosk/confirmation | KioskConfirmationComponent | requireConfirmationContext |
| /kiosk/cash-instruction | KioskCashInstructionComponent | — |
| /kiosk/error/network, /menu-unavailable, /product-removed, /payment-refused | KioskError*Component | — |

## Écrans / états / boutons (testids majeurs)
- **A1 Idle** : kiosk-idle-root, -logo, -brand, -title, -touch-btn, -lang-selector + kiosk-idle-lang-{fr,...}, -a11y-btn ; chooser : kiosk-order-type-chooser, kiosk-order-type-takeaway, kiosk-order-type-dine-in (v-if dineInEnabled — `pos_dine_in_enabled` default FALSE V1 ⇒ tuile ABSENTE) ; kiosk-promo-carousel, kiosk-theme-toggle, kiosk-rush-banner.
- **A2 Catégories** : kiosk-categories-root, -sidebar + sidebar-item-<catId> (image par cat), -zone-title, -zone-count, -products + kiosk-product-card-<id> (badge kiosk-product-badge-<id> : Épuisé `pos.item_86_d` / Nouveau / Composer ; prix kiosk-product-price-<id> ; allergens ; bouton + kiosk-product-add-<id> disabled si indisponible), -bottom-bar (-cart-indicator, -cart-total, -pay, -abandon, -breadcrumb), -empty/-loading/-retry/-cache-banner, top-account.
- **A3 Wizard (FROZEN, observation)** : .kiosk-wizard, header (titre + kiosk-wizard-header-allergens + bouton × .kiosk-wizard-close), step visuals (.kiosk-step-visual active/done), choix .kiosk-viande-card/.kiosk-option-card/.kiosk-generic-choice/.kiosk-menu-card, prev .kiosk-btn-prev (disabled à l'étape 0), next .kiosk-btn-next (:disabled=!canAdvance ⇒ min_select), dernier pas .kiosk-btn-next--cart, abandon modal .kiosk-wizard-abandon-modal (-yes/-no), live composition kiosk-wizard-live-composition + chips.
- **A4 Panier** : kiosk-cart-root, -title, -count, -items + par ligne kiosk-cart-item-<idx> (-name, -qty, -qty-plus [clamp MAX_ITEM_QTY=20 kioskCart.js:24], -qty-minus, -remove, -edit, -total, -options, -allergens, -note), -subtotal, -total, -checkout, -clear (+ modal -clear-modal/-clear-yes/-clear-no), état vide kiosk-cart-empty + -empty-cta, -add-more, -back, -order-type (dinein/takeaway), promo (kiosk-cart-promo-*), loyalty (kiosk-cart-loyalty-btn v-if discountsEnabled, -loyalty-discount), -quote-error, bottom-sheet.
- **A5 Upsell (FROZEN)** : kiosk-upsell-root, -title, -grid, -loading, cartes kiosk-upsell-card-<id> (toggle, -name, -price), kiosk-upsell-add-continue (si sélection), kiosk-upsell-skip (+ timer), -autoskip-bar.
- **A6 Loyalty** : étapes input/register : .kiosk-loyalty-input (+ numpad .kiosk-numpad-btn), bouton vérifier .kiosk-btn-primary.full (disabled sans code), erreur .kiosk-loyalty-error, skip .kiosk-loyalty-skip, register (kiosk-loyalty-register-name/-phone/-email).
- **A7 Paiement** : kiosk-payment-root, -title, -total ; route comptoir (Plan B `kiosk.payment_route_all_to_counter`) : kiosk-payment-counter-route, -counter-title, -counter-sub, -counter-total, -counter-confirm, -counter-error ; sinon méthodes -method-card/-cash/-tr + -confirm, -processing, -tpe-overlay/-tpe-cancel, -offline-*, -back. Confirmation : kiosk-confirmation-root/-number/-total/-cta-home/-cta-print ; cash-instruction : kiosk-cash-order-number/-amount/-cta-understood.
- **A8 Rupture** : badge Épuisé via isProductUnavailable → getProductBadge (KioskCategoriesComponent.vue:718), add disabled, card aria-disabled.
- **Transverses** : kiosk-inactivity-overlay (-stay/-leave/-countdown), kiosk-catalog-change-toast, kiosk-offline-conflict-modal, KioskToast.

## Data (foodking_e2e)
- Catégories actives : 1 Sandwich Cayenne, 2 Galette, 3 Sandwich Classique, 4 Burgers, 5 Tacos, 6 Bols Gourmands, 7 Frites, 8 Suppléments, 9 Desserts, 10 Boissons, 11 Menu enfant (+ pollution test 13/14/15 `E2E-CAT-*`).
- Wizards : profils par catégorie publiés v2 (sandwich : cats 1,2,3,4,6 ; tacos : cat 5) + customs items 33,34,41-48. Items wizard testés : 22, 36, 26, 38, 41, 24, 25.
- Items simples : 49-51 desserts, 52-59 boissons, 33/34 frites.
- PENDING_COUNTER = payment_status 15 (app/Enums/PaymentStatus.php:9) ; fiscal_sequence_no alloué à l'encaissement caisse uniquement.
