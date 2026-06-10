# W1 — DIMENSION i18n/FR — Sweep transversal CAISSE (round 1)

Date : 2026-06-11 · Worktree `release-v1-2026-06-10` · READ-ONLY code · Screenshots `tests/captures/baseline-25cb5dac1/`

## Méthode
- Greps multiples (`-E`, patterns tolérants aux espaces) sur `resources/js/components/admin/{pos,posOrders,cash,cashOverview,cashSessionReport,encaissement,orderHistory}` + `router/modules/{pos*,cash*,encaissement*,historique*}.js` : texte EN hardcodé, `confirm()/alert()`, placeholders/titles/aria, littéraux script, `toFixed/Intl/toLocale*/Datepicker`, clés `$t()` dynamiques.
- Cross-check programmatique (node) des **431 clés `$t()` uniques** utilisées par la caisse contre `resources/js/languages/fr.json` ET `en.json` (traductions FR = `resources/js/languages/fr.json`). Clés dynamiques (`label.cash_mvt_*`, `label.cash_status_*`, `pos.tracker.source_*`, `label.<status table>`, `label.encaisser_mode_*`, footer NF525 `posReceiptBuilder.js:116-122`) énumérées côté producteur (CashMovement TYPE_*, sourceOf(), etc.) et vérifiées une à une → toutes résolues sauf `label.error`.
- Analyse visuelle des 9 captures baseline (historique + pos-orders re-lus zoomés ×2 via crop sips).
- Aucune énumération déclarée exhaustive sur un seul grep (leçon mémoire whitespace).

## Constat global
La caisse est massivement FR et FR-correcte : tables Commandes Caisse / Historique / Encaissement / Rapport Caisses 100 % FR avec montants `1,00 €` (virgule, suffixe), statuts FR (`En préparation`, `Payé`, `Réconciliée`), dates ordre JJ-MM. Les montants des tickets et tables viennent du backend déjà formatés FR (`*_currency_price`). Les formateurs locaux récents utilisent `Intl.NumberFormat('fr-FR')` avec fallback. Les findings restants sont ciblés.

---

## Findings

### P1 (2)

**[P1] resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue:21,25 + cashOverview/CashOverviewComponent.vue:30,34 — Inputs date natifs affichent le format EN (mm/dd/yyyy) dans les filtres DU/AU**
- Evidence code : `<input id="cashFrom" v-model="filters.from" type="date" class="db-field-control" />` (idem :25, et cashOverview :30/:34). Le rendu d'un `<input type="date">` suit la locale du navigateur, pas l'app.
- Evidence visuelle : `cash-sessions-report.png` affiche littéralement **« mm/dd/yyyy »** en placeholder des deux champs ; `cash-overview.png` affiche **« 06/10/2026 »** pour le 10 juin (= MM/DD/YYYY, un FR attendrait 10/06/2026). Flux normal (Rapport Caisses Quotidien + Vue Caisse Unifiée).
- Recommendation : remplacer par le Datepicker maison configuré `locale: fr` + `format: dd/MM/yyyy`, ou forcer la locale du Chrome de la caisse en FR (mitigation env) — préférer le fix code pour ne pas dépendre du poste.

**[P1] resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:113 (+313) — Remise fidélité affichée avec point décimal EN (`−2.50 €`)**
- Evidence : ligne 113 template `<strong>−{{ previewDiscountEur.toFixed(2) }} €</strong>` (aperçu live de la remise) ; ligne 313 message succès `amount: \`${eur.toFixed(2)} €\``. Pas d'`Intl` ici, contrairement au reste de la caisse qui affiche `2,50 €`.
- Flux normal : modal « Appliquer une réduction fidélité » accessible depuis le POS et la fiche commande (boutons visibles sur `pos-main.png` et `pos-orders-show.png`).
- Recommendation : réutiliser le pattern `Intl.NumberFormat('fr-FR', {style:'currency'})` déjà présent dans PosCounterCollectModal.vue:432 / PosRefundModal.vue:303.

### P2 (5)

**[P2] resources/js/components/admin/orderHistory/HistoriqueListComponent.vue:77 + posOrders/PosOrderListComponent.vue:63 — `@vuepic/vue-datepicker` sans `locale` ni `format` → calendrier EN + valeur MM/DD/YYYY**
- Evidence : `<Datepicker uid="searchStartDate" ... range :preset-ranges="presetRanges">` — aucun prop `locale`/`format` (grep `locale` sur les 2 fichiers : 0 hit). Défaut vue-datepicker = mois/jours en anglais (« June », « Mo Tu… ») et input `MM/DD/YYYY` une fois une plage choisie. Les preset-ranges, eux, sont FR (`Aujourd'hui`, `Ce mois`… :233-239).
- Secondaire : panneau filtre replié par défaut (non visible sur `historique.png`/`pos-orders.png`).
- Recommendation : passer `:locale="'fr'"` + `format="dd/MM/yyyy"` (+ `:day-names`) aux deux Datepicker.

**[P2] app/Http/Requests/PosOrderRequest.php:173-177,197,234,250,266-272 — Messages de validation backend hardcodés en anglais, surfacés au caissier sur 422**
- Evidence (re-grep confirmé) : `'The received amount can not be less than the total amount.'` (:197), `'A valid payment terminal is required for every CARD tranche.'` (:234), `'The selected payment terminal is not available for this branch.'` (:250), `'This order type is disabled now you can try another order type…'` (:173/175/177), `'Last 4 digits of card is required'` etc. (:266-272). Affichés tels quels par les toasts d'erreur du POS.
- Chemins d'erreur (rares) → P2. Recommendation : passer par `__()`/lang FR ou libellés FR inline (backend hors scope d'édition de ce sweep, mais finding réel user-facing caisse).

**[P2] resources/js/components/admin/posOrders/PosOrderShowComponent.vue:777,781 — Fallback `$t('label.error')` : clé absente de fr.json ET en.json → toast affiche la clé brute « label.error »**
- Evidence : `alertService.error(err?.response?.data?.message || this.$t('label.error'));` ; vérif node : `fr.label.error === undefined`, `en.label.error === undefined` (vue-i18n rend alors la clé brute). Déclenché si l'API échoue sans `message` (changement statut/paiement sur la fiche commande).
- Recommendation : ajouter `"error": "Une erreur est survenue"` à `fr.json` (label) ou utiliser `message.something_went_wrong` existant.

**[P2] resources/js/components/admin/pos/ReceiptComponent.vue:520 — Prix des addons composer sur ticket client formaté `toFixed` point décimal (incohérent avec le reste du ticket en virgule)**
- Evidence : `const formatted = n.toFixed(...)` puis `position === 0 ? \`${symbol}${formatted}\` : \`${formatted}${symbol}\`` → `0.50€` alors que tous les autres montants du ticket sont backend-formatés FR (`*_currency_price`, virgule). Seed `site_currency_position = RIGHT` (SiteTableSeeder.php:32) donc pas de préfixe `€0.50`, mais le point reste. Visible sur ticket imprimé d'une commande borne « menu formule » avec addon payant.
- Recommendation : formatter via `Intl.NumberFormat('fr-FR')` ou remplacer le point par une virgule comme CashSessionReportListComponent.vue:254.

**[P2] [FROZEN-GATE] resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:210 — `formatEur` paiement multi-tranches : `12.50 €` point décimal**
- Evidence : `return \`${Number(value).toFixed(2)} ${sym}\`;` — affiché dans les lignes de tranche du paiement éclaté (PaymentComponent, frozen lui aussi). Fichier **frozen §7** → aucune édition proposée ; à consigner pour un futur LOCK/gate owner si le polish multi-tender est priorisé.

### P3 (5)

**[P3] resources/js/router/modules/posRoutes.js:32 — `breadcrumb: "floorplan"` sans clé `menu.floorplan` (absente fr.json + en.json)**
- BreadcrumbComponent.vue:12 rend `$t('menu.'+breadcrumb)` → « menu.floorplan » brut si rendu. Jamais rendu aujourd'hui : la route floorplan est une surface POS plein écran sans breadcrumb (`pos-floorplan.png` le confirme) + dine-in désactivé V1. Latent only.

**[P3] resources/js/components/admin/pos/v5/PosV5QtyStepper.vue:20 — fallback aria-label EN `'quantity'`**
- `:aria-label="ariaLabel || 'quantity'"` — a11y uniquement, seulement si le parent ne passe pas de label. Recommendation : `'Quantité'`.

**[P3] resources/js/components/admin/pos/ParkedOrdersComponent.vue:45 — fallback `'Loading...'` inatteignable**
- `{{ $t('label.loading') || 'Loading...' }}` ; `fr.label.loading = "Chargement…"` existe, et vue-i18n retourne au pire la clé (truthy) → le fallback EN ne s'affiche jamais. Nettoyage cosmétique.

**[P3] resources/js/components/admin/pos/PosComponent.vue:3758,3767,3889 — `toFixed` sur valeurs de formulaire (non rendues)**
- `checkoutProps.form.discount/total` envoyés au backend (SSOT pricing recalcule de toute façon) ; l'affichage passe par `Intl 'fr-FR'` (:3372,:3402). Code-only.

**[P3] Fallbacks `catch → toFixed(2) + ' €'` inatteignables (Intl fr-FR en voie nominale)**
- PosRefundModal.vue:308, PosCounterCollectModal.vue:434, CashOverviewComponent.vue:574, cash/PosCashDrawerSessionDialog.vue:550. Atteints seulement si `Intl.NumberFormat` throw (jamais sur Chrome caisse). CashSessionReportListComponent.vue:254 fait déjà la virgule dans son fallback — pattern à copier si on harmonise.

---

## Vérifications négatives (pas de finding)
- Cross-check 431 clés `$t()` caisse vs fr.json : **0 clé manquante** hors `label.error` (les 5 « préfixes » détectés étaient des clés dynamiques, toutes résolues : `cash_mvt_{order_payment,cashback,drawer_open,drawer_close,adjustment}` = TYPE_* de CashMovement ; `cash_status_{open,closed,reconciled}` ; `source_{all,pos,kiosk,online}` = sourceOf() ; footer NF525 `fiscal_ticket_no/audit_fingerprint/legal_mentions` ; `label.{free,occupied,reserved,cleaning}` floorplan ; `label.encaisser_mode_*`).
- 9 captures lues : pos-main, pos-orders (zoom ×2), pos-orders-show, pos-orders-tracker, encaissement, cash-overview, cash-sessions-report, historique (zoom ×2), pos-floorplan → aucun label brut `xxx.yyy`, aucun texte anglais, montants `X,XX €` partout. Seuls les inputs date natifs (P1 ci-dessus) trahissent un format EN.
- `confirm()/alert()` window : aucun dans le périmètre caisse (les hits = méthodes `canConfirm/onConfirm`).
- Placeholders/titles/aria hardcodés : tous FR (`Nom du client`, `Effacer la recherche`, `Pavé numérique`…).
- alertService hardcodés : tous FR (FloorplanComponent:218,256 ; PosComponent:3865,4043-4058,4502).
- Date « 23:54, 10-06-2026 » des tables : ordre JJ-MM correct (tirets = style setting backend, pas un format EN) — non compté.
- Frozen scannés read-only : PaymentComponent.vue (1 littéral EN = commentaire citant le 422 backend, pas de rendu) ; pos-wizard.js/admin-pos-v4.blade.php non inclus dans le périmètre composant demandé, non audités ici (≈296 KB, à couvrir si un round dédié frozen est ouvert).

## Compte par sévérité
| P0 | P1 | P2 | P3 |
|----|----|----|----|
| 0  | 2  | 5  | 5  |

## Top 3
1. **[P1] Inputs date natifs EN (mm/dd/yyyy) — CashSessionReportListComponent.vue:21,25 + CashOverviewComponent.vue:30,34** — format EN littéralement visible sur les captures baseline, 2 écrans de pilotage caisse.
2. **[P1] PosLoyaltyRedeemModal.vue:113,313 — remise fidélité `−2.50 €` point décimal** — seul montant point-décimal de tout le flux caisse, fix 3 lignes par pattern existant.
3. **[P2] PosOrderRequest.php:173-272 — messages 422 backend en anglais** — toute erreur de validation encaissement/terminal parle anglais au caissier.
