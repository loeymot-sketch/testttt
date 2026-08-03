# HEAL — Format monétaire FR sur `appService.currencyFormat` (ADR-007)

**Statut : RÉSOLU (non committé). Vitest VERT. Frozen-diff 0.**

## Le bug
`resources/js/services/appService.js:71` `currencyFormat(amount, decimal, currency, position)`
faisait `parseFloat(amount).toFixed(decimal) + currency` → rendait **« 0.00€ »**
(point décimal US + symbole collé, aucun espace). Mandat FR ADR-007 = **« 0,00 € »**
(virgule, NBSP avant le symbole, séparateur milliers NBSP). Vu LIVE sur le panier POS
(Sous-total/Total). De plus : `null`/`undefined`/`''` rendaient « NaN€ » (aucun garde).

## Consumers trouvés (12 fichiers, tous DISPLAY — aucun parse/calc)
admin/pos/{PosComponent, PaymentComponent, ItemComponent}.vue ;
frontend/checkout/{CheckoutComponent, CouponComponent}.vue ;
frontend/components/ItemComponent.vue ;
layouts/frontend/{FrontendCartComponent, FrontendNavBarComponent}.vue ;
layouts/table/{TableCartComponent, TableNavBarComponent}.vue ;
table/checkout/CheckoutComponent.vue ; table/components/ItemComponent.vue.
→ 100 % rendu template `{{ currencyFormat(...) }}`. Aucun usage en parse/comparaison.
**Le format compact n'est jamais VOULU** → guardrail « >8 specs / usage-calcul » non déclenché.

## Format canonique aligné (PAS inventé)
Référence codebase = `resources/js/helpers/posFormatCents.js` + sa spec
`tests/js/posFormatCents.spec.js` (`1${NBSP}234,56 €`, `NBSP= `, virgule décimale).
Implémentation manuelle (pas Intl) → déterministe, indépendante des locale-data du runtime,
et thousands = NBSP U+00A0 (Intl utiliserait U+202F narrow-NBSP, qui violerait l'assertion).
NBSP (U+00A0) avant le symbole comme demandé. Signature INCHANGÉE (amount, decimal, currency, position).
LEFT → `€ 1 234,50` ; RIGHT → `1 234,50 €`. Rounding `toFixed` préservé à l'identique.

## Fichiers touchés
- `resources/js/services/appService.js` (NON-frozen) — `currencyFormat` réécrit (+24/-3).
- `tests/js/appServiceCurrencyFormatFr.spec.js` — **NOUVEAU** (8 assertions FR : 0 / 1234.5 /
  7.9 / 1234567.89 / LEFT / précision / coercition null-NaN / string « 19.00 »).

## Specs Vitest mises à jour
**AUCUNE mise à jour requise.** Les 4 specs citées **MOCKENT** `currencyFormat`
(`PosComponent.spec.js:71` & `posComponentA11y.spec.js:69` = `vi.fn(() => '0 EUR')` ;
`paymentComponent401Retry.spec.js:11` = `vi.fn((amount) => String(amount))` ;
`quickwins/discountReasonBindingTest.spec.js:100` = `vi.fn(() => '10 EUR')`) → la vraie
fonction est remplacée, donc insensibles au fix. **Aucune spec n'assertait l'ancien
« X.XX€ »** sur la sortie réelle (les fixtures `currency_price:'6.50€'`/`'10.00 EUR'` dans
posCart/posVariationMultiQty sont des données backend simulées, pas des assertions de format).

## Vérification (compte exact)
- Nouvelle spec : **8/8 VERT**.
- Régression nommée (`PosComponent` + `posComponentA11y` + `paymentComponent401Retry` +
  `discountReasonBindingTest` + `posFormatCents` + nouvelle) : **41/41 VERT**.
- Suite Vitest COMPLÈTE : **1973 passed / 3 skipped / 1 failed (1977)**. L'unique échec =
  `mobileDataAntiFiction.spec.js:66` (sentinelle données mock MOBILE `orders.js`↔`menu.js`,
  **0 référence** à currencyFormat/appService) → **PRÉ-EXISTANT** : prouvé en stashant mon
  edit (échoue toujours sans mon changement). Hors-scope de ce heal.
- **Frozen-diff = 0 ligne** (`git diff --stat` sur pos-wizard.js/.css, admin-pos-v4.blade.php,
  PaymentComponent.vue, PosV5TrancheRow.vue, 3 kiosk = VIDE).

## Reste (non fait, hors mandat)
- Visual re-capture LIVE du panier POS « 0,00 € » non effectuée (mandat = heal money-fr ;
  preuve = Vitest 8/8 sur la signature exacte + 41/41 régression). À tirer par QA-Visual.
- Non committé (consigne).
