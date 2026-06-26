# SWEEP-CLASSE money-FR — round3 (2026-06-26)

Mission READ-ONLY : trouver tous les rendus monétaires user-facing non-FR résiduels
(ADR-007 : « 1 234,56 € » — virgule décimale, NBSP avant €). 44 occurrences
`toFixed|toLocaleString|number_format|Intl.NumberFormat` triées dans
`resources/js/components/` + sweep glued-€ + blade `number_format`.

Déjà healés cette mission (hors scope) : `appService.currencyFormat`,
`PosLoyaltyRedeemModal.vue:113`.

## DÉFAUTS RÉELS (5)

### [P2] resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue:247-249
`formatMoney(v){ return Number(v||0).toFixed(2); }` — PRIMAIRE (aucun Intl), aucun
symbole €, point décimal US. Rendu user-facing aux lignes 62/65/97/99/102
(opening_total, closing_total, opening/closing/variance). Surface = rapport de
session caisse (réconciliation manager/opérateur) = active marchand.
Reco : router via `Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'})`
(comme PosCashDrawerSessionDialog) ou `.replace('.',',')` + ` €`.

### [P2] resources/js/components/admin/pos/ReceiptComponent.vue:521-523
`formatReceiptAddonPrice`: `n.toFixed(decimal)` puis `${symbol}${formatted}` /
`${formatted}${symbol}` — point décimal US + symbole collé (pas de NBSP). Rendu
sur le ticket CLIENT lignes 134 (extra `line_total`) et 147 (addon `line_total`),
condition `line_total > 0`. Surface = ticket client = active. NON frozen.
Reco : `.replace('.',',')` + NBSP entre montant et symbole.

### [P2] resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:313
`amount: \`${eur.toFixed(2)} €\`` — message succès `pos.loyalty.redeem.success`,
point décimal US. Jumeau de la l.113 DÉJÀ healée (`.replace('.',',')`) restée non
traitée. Surface = POS active (toast transitoire).
Reco : `${eur.toFixed(2).replace('.',',')} €` (aligner sur l.113).

### [P3] resources/js/components/admin/settings/LoyaltySetup/LoyaltySetupComponent.vue:71
`{{ (...).toFixed(2) }}€ de réduction` — preview live réglages fidélité, point
décimal US + € collé. Surface = settings admin rare/config.
Reco : `.replace('.',',')` + NBSP avant €.

### [P3] resources/js/components/admin/settings/PaymentTerminals/PaymentTerminalsComponent.vue:37
`{{ Number(terminal.fee_fixed).toFixed(2) }}` — colonne `label.fee_fixed` (form
label « (€) ») = montant euro, point décimal US, pas de € inline. Surface =
config terminaux paiement rare. Reco : `.replace('.',',')` ou `currencyFormat`.

## FROZEN (notés, AUCUN heal proposé)
- `admin/pos/v5/PosV5TrancheRow.vue:210` — `${Number(value).toFixed(2)} ${sym}` (US décimal, FROZEN §7).
- `frontend/kiosk/KioskWizardComponent.vue:1986/2002/2003` — FROZEN + calcul interne (pas display).

## FAUX-POSITIFS ÉCARTÉS (avec preuve) — 23
- **Intl('fr-FR') PRIMAIRE, toFixed = fallback catch** : CashOverviewComponent:567/574,
  PosCashDrawerSessionDialog:543/550, PosRefundModal:303/308, PosCounterCollectModal:389/391,
  ParkedOrdersComponent:219, PosComponent:3456, DeliveryBoyCashSessionList:231/238,
  DeliveryBoyCashSessionShow:216/223, KsPriceLine:82/88. → FR.
- **Kiosk `formatPrice` (mixin `helpers/kioskFormatPrice.js`)** : FR (`.replace('.',',')`
  symbole + Intl fr-FR primaire + fr fallback). Couvre KioskStepViande:286/290/45,
  KioskOrderSummary, KioskCart, etc. → FR.
- **Calcul interne (assigne form.total/discount, pas un display)** : frontend/checkout
  CheckoutComponent:855, table/checkout CheckoutComponent:225, PosComponent:3823/3832/3944.
  Le display utilise `currencyFormat` (FR). → non user-facing.
- **Date (pas money)** : KioskConfirmation:256, OutboxOverview:339/396, LastZReportWidget:58,
  PosCashDrawerSessionDialog:416/565, DeliveryBoyCashSession*:271/256.
- **Pourcentage (pas money)** : KioskPromoCarousel:82, PaymentTerminals:36 (fee_percent %).
- **Dénominations entières** : PosCashDrawerSessionDialog:69/184 `+{{ inc }} €`
  (`increments:[5,10,20,50]`, espace + €, pas de décimale) → FR-acceptable.
- **Backend currency_amount primaire, brut fallback** : ReceiptComponent:239
  (`line.currency_amount != null ? : line.amount`).
- **Blade déjà FR** : `resources/views/pdf/eod_synthesis.blade.php:51`
  `number_format($n,2,',',' ').' €'` → FR conforme.

## VERDICT
CLASSE money-FR : **5 résiduels NOUVEAUX** hors des 2 déjà healés (3×P2 actifs :
cash-session-report, ticket-addon, pos-loyalty-success ; 2×P3 settings rares).
23 faux-positifs écartés avec preuve. 2 FROZEN notés (PosV5TrancheRow, KioskWizard).
