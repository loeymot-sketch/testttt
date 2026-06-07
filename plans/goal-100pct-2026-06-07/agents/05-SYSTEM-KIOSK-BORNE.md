# AGENT 05 — SYSTÈME KIOSK / BORNE (vue CLIENT)
> Ton : client capricieux qui teste TOUT, surtout le WIZARD (jamais testé !).

## Scope / Anchors (vérifiés)
- `Frontend/MenuController.php`, `Frontend/RootController.php`
- 48 composants `resources/js/components/frontend/kiosk/*.vue` : KioskIdleScreen, KioskCategories, **KioskWizardComponent (FROZEN)**, **KioskPosWizardComponent**, KioskCartComponent, KioskUpsellComponent (FROZEN), KioskPaymentComponent, KioskConfirmationComponent, KioskOrderSummaryComponent, écrans erreur
- Auto-login : `.env.e2e KIOSK_AUTO_LOGIN_TRUSTED_IPS=127.0.0.1,::1` (déjà set sur clone)
- FROZEN : KioskWizardComponent/AppComponent/UpsellComponent — piloter, JAMAIS modifier.

## Checklist abusif (cible §4 : WIZARD = priorité)
- **B1 Parcours simple** ✅ déjà (Eau Plate→cart→counter-pay→confirm).
- **B1 WIZARD COMPOSEUR (jamais testé — cœur de la borne)** : Sandwich Cayenne, Tacos, **Bols Gourmands** (3-step), Burger — chaque étape : choix sauces, options, suppléments, qté ; validation étapes requises ; prix ratio-adjusté correct ; addToCart ; composition_snapshot intègre.
- **B2 ÉCRANS ERREUR** : `/kiosk/error/network`, `/payment-refused`, `/product-removed`, `/menu-unavailable` — tous capturés, message FR clair, reprise.
- **B Panier** : modifier qté (+/-), supprimer, vider, code promo, "Avez-vous carte fidélité".
- **Upsell** : "ET POUR TERMINER ?" → ajouter / skip (transition lente connue).
- **Paiement Plan B** : route-to-counter (PENDING_COUNTER) → confirmation → retour idle.
- **LOYALTY borne** : `/kiosk/loyalty` consulter (non-frozen).
- **C** (agent 03) capture chaque écran, vue CLIENT, light mode, palette mobile noir/orange/jaune.
- **D** Fluidité tactile, pas de freeze, feedback à chaque tap.
- **10 COMMANDES borne** variées (avec wizard, multi-items) → toutes créées, fiscal correct après encaissement.
- **i18n** : FR verrouillé (ADR-007), 0 raw label.

## Méthode
E2E :8766 loginAsKiosk (auto-login OK). Items sans options = add direct ; items avec options = wizard.
`openProduct` ouvre wizard si `hasOptions`. Frozen = piloter only.

## PASS bar
WIZARD complet (4 templates) + écrans erreur + 10 commandes + chaque écran capturé+analysé. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/05-kiosk.json`
