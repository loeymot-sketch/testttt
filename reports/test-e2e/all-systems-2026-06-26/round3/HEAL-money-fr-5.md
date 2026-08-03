# HEAL — 5 rendus monétaires non-FR résiduels (round3, 2026-06-26)

Mandat : FR strict ADR-007 « 7,90 € » (virgule + NBSP U+00A0 + €). NON-frozen.
Vérif env : `Intl('fr-FR',{style:'currency',currency:'EUR'})` produit **U+00A0** (`a0`)
avant € dans ce runtime (node check) → `helpers/formatPrice.js` est ADR-007-conforme.
`currencyPositionEnum` = `LEFT:5, RIGHT:10` (le `position===0` du ticket est une
convention LOCALE, pas l'enum — préservée pour ne rien casser).

## Les 5 fichiers (avant → après)

1. **P2** `CashSessionReportListComponent.vue:247-250` (+ import l.146)
   - avant : `formatMoney(v){ const n=Number(v||0); return n.toFixed(2); }` → « 7.90 » (point US, sans €)
   - après : `return formatPrice(v);` via `import { formatPrice } from '../../../helpers/formatPrice'` → « 7,90 € » (helper admin canonique, U+00A0). Consommé l.62/65/97/99/102 sans € adjacent → le helper inclut le symbole. Variance négative → « -5,50 € ». null déjà gardé par '—' en amont.

2. **P2** `ReceiptComponent.vue:521-523` `formatReceiptAddonPrice`
   - avant : `formatted=n.toFixed(decimal); return position===0?`${symbol}${formatted}`:`${formatted}${symbol}`` → « €7.90 » / « 7.90€ » (point, symbole collé)
   - après : `formatted=...toFixed(...).replace('.', ',')` + `const NBSP=' '` + `${formatted}${NBSP}${symbol}` (et symétrique LEFT). Convention position locale conservée. Sortie « 1,20 € » (U+00A0). Aligné sur les autres prix du ticket (backend `*_currency_price` déjà FR).

3. **P2** `PosLoyaltyRedeemModal.vue:313`
   - avant : `amount: \`${eur.toFixed(2)} €\`` → « 1.00 € »
   - après : `amount: \`${eur.toFixed(2).replace('.', ',')} €\`` → « 1,00 € ». JUMEAU de L113. Note : L113 (référence « déjà healée ») utilise un espace RÉGULIER U+0020 avant € (vérifié byte) ; j'ai matché le twin exactement (virgule, espace régulier conservé) comme demandé par la reco #3 « applique le MÊME `.replace` ». Upgrade NBSP des deux lignes = candidat futur (hors-scope des 5).

4. **P3** `LoyaltySetupComponent.vue:71`
   - avant : `...).toFixed(2) }}€ de réduction` → « 2.50€ » (point, € collé)
   - après : `...).toFixed(2).replace('.', ',') }}&nbsp;€ de réduction` → « 2,50 € ». `&nbsp;` = convention NBSP des templates du repo (KDS l'utilise 15×).

5. **P3** `PaymentTerminalsComponent.vue:37`
   - avant : `{{ Number(terminal.fee_fixed).toFixed(2) }}` → « 0.05 » (point, AUCUN €)
   - après : `{{ Number(terminal.fee_fixed).toFixed(2).replace('.', ',') }}&nbsp;€` → « 0,05 € ».

## Spécs (2 mises à jour — épinglaient l'ancien format US)
- `receiptAddonsRenderingSentinel.spec.js:189-192` : `/\+1\.20\s*€/` → `/\+1,20(?:&nbsp;|\s)*€/` (idem 1,80). Cause : `wrapper.html()` sérialise le NBSP en entité `&nbsp;` (≠ U+00A0 brut) → `\s*` ne matchait pas. Le rendu réel est correct : `<span>+1,20&nbsp;€</span>`.
- `posLoyaltyRedeemModal.spec.js:117` : `/2\.50/` → `/2,50/`. **Pré-existant** (prouvé par `git stash` : échouait DÉJÀ sans mes edits) — la preview L113 était healée FR mais ce companion-spec épinglait encore « 2.50 ». §6 du DISCIPLINE l'autorise (« si une spec asserte l'ancien format US, mets-la à jour »).

## Gates
- Specs : `npx vitest run` sur receiptAddonsRenderingSentinel + posLoyaltyRedeemModal + appServiceCurrencyFormatFr + posReceiptBuilder + posFormatCents + receiptNf525FiscalRender + receiptAutoPrint + posReceiptPrintFlow = **80/80 ✓**.
- Sentinels : `npx vitest run tests/js/sentinels/` = **404/404 ✓** (bundle-freshness NON régressé — mes composants hors trigger-list).
- **Frozen-diff = 0** (pos-wizard.js/css, admin-pos-v4, PaymentComponent, PosV5TrancheRow, 3 kiosk, PricingService/Fiscal/BranchScope/Idempotency : tous intacts).
- 7 fichiers, +28/-11. NON committé (rebuild bundle par le superviseur).
