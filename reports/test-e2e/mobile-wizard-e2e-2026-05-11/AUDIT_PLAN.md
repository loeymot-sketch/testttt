# AUDIT_PLAN — Mobile wizard E2E `/test-e2e` skill 2026-05-11

**Mission** : valider raisonnement (state machine wizard) + affichage (visual) + logique (pricing + flow) sur l'app mobile Le Cayenne post-refactor multi-page.

**Cible** : `http://127.0.0.1:8081/index.html` (mobile preview, PHP -S, déjà up HTTP 200).
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10` HEAD `00be6bd4`.
**Viewport** : iPhone 14 390×844, deviceScaleFactor 2, isMobile=true, hasTouch=true.

## Auth bootstrap (per spec)

```js
await ctx.addInitScript(() => {
  localStorage.setItem('lecayenne.onboarding_seen', 'true');
  localStorage.setItem('lecayenne.auth', JSON.stringify({
    token: 'mock-v0', phone: '+33642799884', user_id: 12345
  }));
});
```

## Surfaces

Mobile app mono-spa unique sur `:8081` — pas de surfaces parallèles (cf. POS/Kiosk/KDS qui sont sur :8000 et hors scope mobile-only). Mais on couvre TOUTES les surfaces internes de l'app : splash / onb / login / OTP / home / menu / item-wizard / cart / pay-modals / confirm / orders / orderDetail / profile / loyalty / wallet.

## Wave structure (5 waves parallèles)

### Wave A — Onboarding + Home + TabBar nav
**Spec** : `tests/e2e/mobile-wizard-e2e-2026-05-11/wave-A-onboarding.spec.js`
**Screenshots dir** : `tests/e2e/__screenshots__/test-e2e-mobile-wave-A/`
**States** :
- 01-splash-fresh (sans auth → splash auto-advance)
- 02-onb1, 03-onb2, 04-onb3, 05-onb4
- 06-login-empty
- 07-otp-empty
- 08-otp-typed-1234
- 09-home-authed (post-OTP)
- 10-home-bonjour-or-bonsoir-greeting (variable selon heure)
- 11-home-featured-card-tacos-xxl
- 12-home-categories-grid-13
- 13-tab-menu (clic tab MENU)
- 14-tab-commandes (clic tab COMMANDES)
- 15-tab-profil (clic tab PROFIL)
**Cross-screen invariants** : tab bar persistance, greeting i18n FR-only, featured card slug = `tacos-xxl`, 13 catégories visibles.

### Wave B — Menu + 13 catégories + ScreenItem entry
**Spec** : `tests/e2e/mobile-wizard-e2e-2026-05-11/wave-B-menu-cats.spec.js`
**Screenshots dir** : `tests/e2e/__screenshots__/test-e2e-mobile-wave-B/`
**States** :
- 01-menu-all-items
- 02-cat-tacos-list (filter chip tacos)
- 03-cat-sandwichs-list
- 04-cat-burgers-list
- 05-cat-assiettes-list
- 06-cat-ojja-list
- 07-cat-omelettes-list
- 08-cat-salades-list
- 09-cat-wings-list
- 10-cat-menus-enfants-list
- 11-cat-frites-list
- 12-cat-desserts-list
- 13-cat-boissons-list
- 14-cat-supplements-list
- 15-item-tacos-xxl-entry (click sur item → wizard step 1)
**Cross-screen invariants** : 60 items rendus, filter chips fonctionnels, click item → wizard rendered.

### Wave C — Wizard flows P0 (Tacos / Sandwichs / Burgers)
**Spec** : `tests/e2e/mobile-wizard-e2e-2026-05-11/wave-C-wizard-p0.spec.js`
**Screenshots dir** : `tests/e2e/__screenshots__/test-e2e-mobile-wave-C/`
**Scenarios** :
1. **Tacos XXL** flow complet 9-step :
   - 01-viandes (0/4)
   - 02-viandes (4/4 picked)
   - 03-sauce (Ketchup + Mayo)
   - 04-crudites (Oignon retiré)
   - 05-supplements (Œuf)
   - 06-menu (full picked)
   - 07-drink (Coca)
   - 08-fritesStyle (Cheddar fondu)
   - 09-fritesSauce (BBQ)
   - 10-recap (composition complète + total 18€)
   - 11-cart-after-add (1 ligne, total exact)
2. **Sandwich Le Terminator** flow 6-step (viandes=2) :
   - 01-viandes (2 picked)
   - 02-sauce (Algérienne)
   - 03-crudites (default ON)
   - 04-supplements (none)
   - 05-menu (Sans formule)
   - 06-recap (total 9€)
3. **Cheese Burger** flow 5-step (pas de viandes) :
   - 01-sauce (Burger)
   - 02-crudites
   - 03-supplements
   - 04-menu (none)
   - 05-recap (total 6€)
**Pricing assertions** :
- Tacos XXL full combo = 18,00 € (12,50 + 0,50 sauce + 1,00 Œuf + 3,00 Menu + 1,00 Cheddar fondu + 0 BBQ first free)
- Terminator no formule = 9,00 €
- Cheese Burger no formule = 6,00 €
**Validation** :
- CTA Suivant disabled jusqu'à validation step (viandes 4/4, sauce 1+, menu picked, drink picked, fritesStyle picked)
- canAdvance respect mirror KW.vue
- Sans Sauce exclusif (clic deselect autres sauces)

### Wave D — Wizard flows P1 (Assiettes / Ojja / Omelettes / Salades / Wings / Menus Enfants / Frites / Direct adds)
**Spec** : `tests/e2e/mobile-wizard-e2e-2026-05-11/wave-D-wizard-p1.spec.js`
**Screenshots dir** : `tests/e2e/__screenshots__/test-e2e-mobile-wave-D/`
**Scenarios** (12 items représentatifs, 1 par cat) :
- `assiette-poulet` : sauce → supplements → recap (3 steps, 12,50€)
- `ojja-merguez` : sauce → supplements → recap (omelette template V3.8, 13,50€)
- `omelette-fromage` : sauce → supplements → recap (omelette template, 8,50€)
- `salade-royale` : sauce → supplements → recap (D1 simplified, 7,50€)
- `wings-12` : sauce → supplements → recap (snacking, 10,50€)
- `menu-cheese-enfant` : sauce → recap (D2 has_sauce=true, omelette template, 6,00€)
- `frites-grande` : fritesStyle → recap (F-03, 4,00€)
- `tiramisu` : direct-add (3,80€)
- `coca` : direct-add (1,50€)
**Validation** :
- Template kiosk-aligned vérifié par item
- 0 step ajoutée incorrectement (e.g. ojja ne montre PAS menu/frites_style V3.8)
- menus enfants offre bien sauce step (D2)

### Wave E — Cart + Pay + Modals + Confirm
**Spec** : `tests/e2e/mobile-wizard-e2e-2026-05-11/wave-E-cart-pay.spec.js`
**Screenshots dir** : `tests/e2e/__screenshots__/test-e2e-mobile-wave-E/`
**States** :
- 01-cart-1-line (après wizard Tacos XXL recap → cart)
- 02-cart-qty-plus (qty=2)
- 03-cart-qty-minus (qty=1, clamp >=1)
- 04-cart-multi-lines (ajouter Coca puis revenir cart)
- 05-cart-trash-line (delete one)
- 06-cart-empty-state
- 07-modal-pay-choice (clic Payer)
- 08-modal-pay-counter-flow (caisse → confirm)
- 09-confirm-screen (success)
- 10-modal-points-gain (+25 confetti)
- 11-modal-pay-card-flow (clic Stripe)
- 12-stripe-placeholder
- 13-back-to-cart (back from stripe)
**Cross-screen invariants** :
- cart total === recap total === confirm total
- qty min=1 clamp
- empty state copy + CTA
- modal aria-modal=true + ESC close

### Wave F — Orders + Profile + Loyalty + Modals (Redeem / CardLink)
**Spec** : `tests/e2e/mobile-wizard-e2e-2026-05-11/wave-F-orders-loyalty.spec.js`
**Screenshots dir** : `tests/e2e/__screenshots__/test-e2e-mobile-wave-F/`
**States** :
- 01-orders-active-tab
- 02-orders-historique-tab
- 03-order-detail-active (C-1234)
- 04-order-detail-history (C-1212)
- 05-profile-screen
- 06-profile-modifier-toast
- 07-loyalty-screen-points
- 08-loyalty-qr-default
- 09-loyalty-qr-countdown-tick
- 10-loyalty-barcode-toggle
- 11-loyalty-rewards-tab
- 12-modal-redeem-step-1 (clic redeem)
- 13-modal-redeem-step-2-confirm
- 14-modal-redeem-step-3-success
- 15-modal-card-link
- 16-loyalty-history-tab
- 17-modal-opt-out-rgpd
**Cross-screen invariants** :
- Loyalty points (mock 347) consistent loyalty + profile preview
- QR persist via storage (refresh keeps QR)
- WizardRedeem 3-step idempotency (re-trigger same reward = no double-debit)
- RGPD opt-out fonctionnel (clear consent)

## Cross-wave invariants

1. **0 raw label** (regex `Label\.[A-Za-z0-9_.]+|kiosk\.[a-z_.]+|^0undefined$|NaN\s*€`) sur tous DOM
2. **0 white-on-white** (alpha-blending PNG sweep <95% pixels >240/240/240)
3. **0 console error** (hors 404 `/.image-slots.state.json` debug noise pré-existant)
4. **0 page error** (React unhandled exception, Babel parse fail)
5. **A11y baseline** : role/tabindex/onKeyDown sur ChoiceCard ; focus management sur step transitions ; aria-live counter+total ; aria-disabled CTA
6. **Visual quality** : pas de truncation sans tooltip, pas de button overlap, pas de palette drift hors palette mobile (--orange #FF5A1F / --yellow #FFD93D / --ink #0A0A0A / --paper #FFFFFF / --cream #FAF7F2)

## Out-of-scope

- Pas de comparaison pixel-diff vs kiosk borne (viewport différent, done in qualitative-only via diff/ folder)
- Pas de wireup réseau réel (V0 standalone, Supabase/backend Phase 6 hors scope)
- Pas de natif iOS/Android (Phase 11 hors scope)
- Pas de cross-surface cascade POS↔KDS↔OSS (mobile app ne sync pas en V0, Phase 6 ouvert)

## Convergence criteria

- Two consecutive rounds with `verdict: GREEN` across all 5 waves
- Set-equality on findings (same finding IDs, same statuses)
- 0 open P0 + 0 open P1
- Cross-wave invariants (raw labels, white-on-white, console, page errors) all GREEN

## Out-of-band

Adversarial supervisor is NOT a Playwright spec — c'est un Agent invocation per wave qui lit les artifacts (PNG vision + DOM + console + network) et émet JSON findings.
