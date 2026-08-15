# RECONNAISSANCE — Voie CAISSE (POS)

**Date** : 2026-08-15 · **HEAD** : `e2d2ca3b4` · **Mode** : READ-ONLY (aucun fichier modifié)
**Angle** : confort d'usage maximal pour le caissier + trous de preuve en test réel.

> Discipline anti-hallucination : chaque `file:line` ci-dessous a été ouvert (Read) ou
> confirmé par `grep`/`sed` avec numéro de ligne pendant cette session. Les rares points
> non re-vérifiés portent explicitement la mention `(à vérifier)`.

---

## BLOC 1 — INVENTAIRE des surfaces caisse

### 1.1 Écrans (routes front → composant → contrôleur)

| | Fonction | Route front | Composant | Backend |
|---|---|---|---|---|
| **BASE** | Caisse (prise de commande + paiement) | `/admin/pos` — `resources/js/router/modules/posRoutes.js:15` | `resources/js/components/admin/pos/PosComponent.vue` (6853 l.) | `app/Http/Controllers/Admin/PosController.php:51` (`permission:pos`, sauf `quote`) — `POST /api/admin/pos` `routes/api.php:943` |
| **BASE** | Caisse V4 (entrée Blade dédiée, bundle `pos-app.js`) | `/admin/pos-v4/{any?}` — `routes/web.php:110` | `resources/views/admin-pos-v4.blade.php` **[FROZEN]** + `resources/js/pos-app.js:90` | `app/Http/Controllers/Admin/AdminPosV4Controller.php:26` |
| **BASE** | Encaissement (file des commandes à encaisser) | `/admin/encaissement` — `resources/js/router/modules/encaissementRoutes.js:11` (`permissionUrl: "pos-orders"`) | `resources/js/components/admin/encaissement/EncaissementComponent.vue` | closure `routes/api.php:944` (`can('pos')`) + `routes/api.php:1141` confirm |
| **BASE** | Session de caisse (fond, mouvements, clôture) | modale dans `/admin/pos` — `PosComponent.vue:255` (bouton `pos-cash-session-open`), `:1574` (dialog) | `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` | `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:58/128/162/217` — routes `routes/api.php:1255-1265` |
| **BASE** | Ouverture tiroir « sans vente » | bouton dans `/admin/pos` — `PosComponent.vue:4874` `triggerNoSaleOpenDrawer` | idem | `app/Http/Controllers/Admin/Pos/CashDrawerController.php` — `POST` `routes/api.php:1252` |
| **BASE** | Ticket / reçu (client + cuisine) | modale `#receiptModal` depuis POS et tracker | `resources/js/components/admin/pos/ReceiptComponent.vue` | `PosReceiptPrintController@increment` `routes/api.php:1232` ; `@kitchen` `:1234` ; octets ESC/POS `Pos/PosTicketBytesController@show` `:1237` |
| **BASE** | Suivi des commandes (board caisse) | `/admin/pos-orders-tracker` — `posOrderRoutes.js:52` | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` (2871 l.) | `PosOrderController@index` `routes/api.php:1329` + `@changeStatus` `:1338` |
| **BASE** | Liste / détail commandes caisse | `/admin/pos-orders` — `posOrderRoutes.js:25` ; `/admin/pos-orders/show/:id` — `:36` | `posOrders/PosOrderListComponent.vue`, `posOrders/PosOrderShowComponent.vue` | `PosOrderController@index/@show` `routes/api.php:1329-1330` |
| **BASE** | Clôture Z (fiscal) | `/admin/settings/...` — `resources/js/router/modules/settingRoutes.js` (hors voie caisse stricte) | `admin/settings/Fiscal/ZReportListComponent.vue`, `admin/dashboard/LastZReportWidget.vue` | `Admin\Fiscal\ZReportController` — `routes/api.php:1664-1671` |
| SECONDAIRE | Commandes garées (park/recall) | tiroir dans `/admin/pos` | `pos/ParkedOrdersComponent.vue` | `Pos/ParkedOrderController` — `routes/api.php:1241-1244` |
| SECONDAIRE | Plan de salle | `/admin/pos/floorplan` — `posRoutes.js:25` | `pos/FloorplanComponent.vue` | `Pos/FloorplanController` — `routes/api.php:1247-1250` |
| SECONDAIRE | Fidélité (identifier / créditer / dépenser) | modales dans POS + fiche commande | `pos/PosLoyaltyIdentifyModal.vue`, `pos/PosLoyaltyRedeemModal.vue` | `Admin\PosLoyaltyController` — `routes/api.php:1357` (redeem), `:1365` (attach), `:1538-1565` (lookup/history/credit/deduct) |
| SECONDAIRE | Remboursement NF525 (écriture miroir) | modale fiche commande | `pos/PosRefundModal.vue` | `PosOrderController@refundWithCounterEntry` — `routes/api.php:1350` |
| SECONDAIRE | Sortie de stock (repas personnel / perte) | modale caisse | `pos/PosStockOutflowModal.vue` | `Admin\PosStockOutflowController` — `routes/api.php:1135-1139` |
| SECONDAIRE | Santé système caisse | pastille dans le tracker | `pos/PosSystemHealthPill.vue` | `Admin\PosSystemHealthController` — `routes/api.php:1129` |
| SECONDAIRE | Ticket Uber par photo | `/admin/uber-photo` — `uberPhotoRoutes.js:13` | `uber/UberPhotoCaptureComponent.vue` | `Admin\UberPhotoCaptureController` — `routes/api.php:424-435` |
| SECONDAIRE | Vue d'ensemble encaissements | `/admin/cash-overview` — `cashOverviewRoutes.js:11` | `cashOverview/CashOverviewComponent.vue` | `Admin\CashOverviewController` — `routes/api.php:1492` |
| SECONDAIRE | Rapport sessions de caisse | `/admin/cash-sessions-report` — `cashSessionReportRoutes.js:10` | `cashSessionReport/CashSessionReportListComponent.vue` | `Admin\CashSessionReportController` — `routes/api.php:1481` |
| SECONDAIRE | Caisse livreur | `/admin/delivery-boy-cash-sessions` — `deliveryBoyCashSessionRoutes.js:18` | `deliveryBoyCashSession/*.vue` | `Admin\DeliveryBoyCashSessionController` — `routes/api.php:786-798` |
| SECONDAIRE | Lecture NFC client | appelé depuis POS | — | `Pos/CustomerNfcLookupController@lookup` — `routes/api.php:1269` |
| SECONDAIRE | Afficheur client (SAGA) | poussé depuis POS | — | `Pos/PosCustomerDisplayController@update` — `routes/api.php:1239` |
| SECONDAIRE | File de tickets cuisine réclamée par le poste | sondage arrière-plan | — | `Pos/KitchenTicketQueueController@pending/@acknowledge` — `routes/api.php:1116/1120` |
| SECONDAIRE | Navigation secondaire caisse | bandeau partagé | `pos/CaisseSecondaryNav.vue` | — |

### 1.2 Zones gelées touchées par la voie
- `public/js/pos-wizard.js` (323 357 o.), `public/css/pos-wizard.css` (47 763 o.), `resources/views/admin-pos-v4.blade.php` — **STRICT no-touch**.
- `resources/js/components/admin/pos/PaymentComponent.vue` (1496 l.), `pos/v5/PosV5TrancheRow.vue` (352 l.) — frozen, LOCK+gate requis.

### 1.3 Tests `tests/Feature/Pos/` — 59 fichiers + `Traits/`
```
CashDrawerSessionOwnershipTest · CounterCollectQueueRobustTest · CounterCollectSplitPaymentTest
DestroyReleasesAvailabilityTest · DiningTableReleaseAfterPosOrderTest · FloorplanControllerTest
FritesKidsSauceNoProfileSealTest · FritesWizardComposerTest · KitchenTicketClaimsMigrationTest
KitchenTicketQueueTest · PhoneOrderCancelLoyaltyTest · PhoneOrderDeferredTest
PosCardDeclarativeNoNoteTest · PosCardSaleWithoutTerminalTest · PosCashTrailTest
PosCustomerCreateTest · PosCustomerCreateWelcomeMailTest · PosCustomerDisplayControllerTest
PosCustomerLookupTest · PosDeliveryChargeServerAuthoritativeTest · PosErrorLeakAndPiiTest
PosFeaturedCategoriesEndpointTest · PosFreeDeliveryQuoteSealTest · PosLoyaltyAttachTest
PosLoyaltyFloorAnnonceEgaleAppliqueTest · PosLoyaltyLookupEndpointTest · PosLoyaltyManualCreditTest
PosLoyaltyManualDeductTest · PosLoyaltyRedeemFloorTest · PosLoyaltyRedeemTest
PosMenuDrinkChoiceTest · PosMenuRuntimeAccessTest · PosOperatorWebOrderPermissionTest
PosOrderListLeanPaginationTest · PosOrderRequestDeliveryChargeGuardTest
PosOrderRequestNoClientTotalsTest · PosPurgeParkedScheduleTest · PosQuoteVariationConstraintTest
PosReceiptPrintFlowTest · PosScheduledOrderIntakeTest · PosSimulationHardware4ScenariosTest
PosStockOutflowTest · PosSystemHealthTest · PosTicketBytesEndpointTest · PosWalkInCustomerApiTest
PosWalkinDeferredCreateTest · PrintQueuePollRateLimitTest · QuoteBindingTest
RefundBypassGuardTest · RefundBypassTwinRoutesGuardTest · RefundCounterTwinPathsTest
SetupReceiptPrinterCommandTest · SimpleOrderResourceTrackerContractTest · SplitPaymentEndToEndTest
TerminalIdWireInTest · WebAcceptPreparationTimeTest · WebOrderInlineAcceptTest
WebOrdersPaidEndpointTest · WebOrdersPendingEndpointTest
```
Sœurs hors dossier mais dans la voie : `tests/Feature/Cash/` (15 fichiers, dont
`CashDrawerCloseVarianceTest`, `CashVarianceGateTest`, `CashDrawerEndpointsTest`,
`ManagerGateRoutineCloseTest`, `CashMovementsDeleteForbiddenTest`).

---

## BLOC 2 — FRICTIONS DE CONFORT

### [P0] `resources/js/services/CashDrawerService.js:127-149` + `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:244` — une clôture qui échoue à l'étape 2 rend la caisse INFINISSABLE (aucune UI ne peut la rattraper)
```
friction: le caissier compte son tiroir, tape le montant, clique « Clôturer ». Le serveur
  passe la session en CLOSED (étape 1, réussie), puis REFUSE la réconciliation (étape 2)
  parce que l'écart dépasse 2 € et que le POS Operator n'a PAS la permission
  `cash.reconcile.variance.override`. Un bandeau rouge s'affiche. Le caissier réessaie :
  `GET /current` ne renvoie QUE les sessions OPEN — la sienne est CLOSED — donc l'écran lui
  propose « Ouvrir la caisse ». Sa session reste CLOSED-jamais-réconciliée, écart JAMAIS
  calculé, et AUCUN écran de l'application ne peut la terminer. C'est exactement le motif
  du sinistre « Z bloqué 17 jours / 3 344,80 € non scellés » de la mémoire projet.
evidence: `CashDrawerService.js:132` POST /close puis `:142` POST /reconcile — deux appels
  séquentiels sans compensation ; `cashDrawer.js:143-149` le catch se contente de poser
  `error` et de relancer (le state garde une session déjà close côté serveur).
  `CashDrawerSessionController.php:244` → `$this->service->findOpenSessionForUser(...)` et
  `app/Services/Cash/CashDrawerService.php:477-481` filtre `->where('status', STATUS_OPEN)`.
  `app/Services/Cash/CashDrawerService.php:297-300` lève l'exception d'approbation.
  `database/seeders/RolePermissionTableSeeder.php:81` accorde `cash.reconcile.variance.override`
  au Branch Manager UNIQUEMENT ; la liste POS Operator (`:100-121`) ne la contient pas.
  Aucun appelant front de /reconcile en dehors de la chaîne : `grep -rn "\.reconcile(" resources/js`
  → 0 résultat (la fonction `CashDrawerService.js:156 export async function reconcile` est morte).
fix-suggéré: exposer la réconciliation seule dans le dialog quand `/current` ne renvoie rien
  mais qu'une session CLOSED-non-réconciliée existe pour l'utilisateur (nouvelle lecture
  serveur + bouton « Terminer la clôture »), et faire remonter le 422 en message FR actionnable
  (« demande à un responsable de valider l'écart de X € »).
```

### [P1] `resources/js/components/admin/encaissement/EncaissementComponent.vue:135-137` — une file d'encaissement en PANNE s'affiche comme « ✅ tout est encaissé »
```
friction: si `GET counter-collect/pending` échoue (403 d'un rôle sans `pos`, 429, 500, coupure
  réseau), le caissier voit un grand ✅ vert et « File vide ». Il croit qu'il n'y a rien à
  encaisser alors que des clients attendent de payer. Zéro signal d'erreur, zéro « dernière
  mise à jour à HH:MM ». Le risque de rôle est réel : la ROUTE FRONT exige `pos-orders`
  (`encaissementRoutes.js:16`) alors que l'ENDPOINT exige `pos` (`routes/api.php:945`).
evidence: `EncaissementComponent.vue:135-137`
    `}).catch(() => { this.loading.isActive = false; });`  ← aucun état d'erreur posé
  et `:27-30` `v-if="orders.length === 0"` → icône `✅` + `label.encaisser_queue_empty`.
  Le POS principal fait mieux sur le MÊME endpoint : `PosComponent.vue:4156-4158` garde la
  dernière liste connue (« pas de faux vide ») et horodate le succès (`:4150 lastCashRefresh`).
fix-suggéré: distinguer « vide » de « pas pu charger » (drapeau `loadFailed`, bandeau rouge +
  heure de dernier succès), et aligner `permissionUrl` de la route sur `pos`.
```

### [P1] `resources/js/components/admin/pos/PosComponent.vue:4142-4145` vs `PosOrdersTrackerComponent.vue:1477-1487` — l'écran de caisse rappelle toutes les 5 s l'endpoint que son voisin juge trop lourd pour être sondé
```
friction: en production il n'y a AUCUN socket (`_kioskPollingInterval` renvoie 5000 ms dès que
  `_wsService.isConnected()` est faux) : l'écran de caisse redemande donc `counter-collect/pending`
  — une resource lourde, jusqu'à 200 commandes avec 9 relations chacune — 12 fois par minute,
  en plus de `web-orders/pending` et `web-orders/paid`. Le caissier le vit en à-coups pendant
  la saisie. Le tracker, lui, a explicitement mesuré ce même endpoint et l'a bridé à 5 minutes.
evidence: `PosComponent.vue:3605` `return window._wsService?.isConnected() ? 60000 : 5000;`
  `PosComponent.vue:3620-3634` le tick appelle loadKioskCashOrders + loadWebOrders + loadPaidWebOrders
  `PosComponent.vue:4142-4145` `async loadKioskCashOrders()` → `axios.get('admin/pos/counter-collect/pending')`
    (aucun garde-fou de cadence)
  `PosOrdersTrackerComponent.vue:1477-1482` « Throttlé à 5 min car l'endpoint renvoie une
    resource lourde (~1,3 s) — il ne doit JAMAIS suivre la cadence du poll dégradé (8 s). »
  `routes/api.php:1014` `->limit(200)` avec `with([...9 relations])` `routes/api.php:958`.
fix-suggéré: appliquer au POS le même garde-fou de cadence que le tracker, ou servir une
  projection légère (id, n°, total, origine) pour le panneau « à encaisser ».
```

### [P1] `resources/js/components/admin/pos/ReceiptComponent.vue:629` + `:703` — « Ticket envoyé ✓ » s'affiche AVANT de savoir si l'imprimante répond
```
friction: le caissier clique « Imprimer », lit tout de suite un toast VERT « Ticket envoyé »,
  tend la main au client suivant… et une ou deux secondes plus tard un toast rouge « pont
  hors ligne » passe, souvent hors de son regard. Résultat : il croit le ticket sorti alors
  qu'aucun papier n'est parti. Même motif dans deux autres surfaces.
evidence: `ReceiptComponent.vue:628-629`
    `this.printingClient = true;`
    `alertService.success(this.$t('pos.ticket_sent'));`   ← succès annoncé AVANT le pipeline
  puis `:703` `alertService.error(this.$t('pos.print_bridge_offline'));`
  Doublons du motif : `pos/PosCounterCollectModal.vue:555` (succès) → `:568` (échec pont) ;
  `pos/ReceiptComponent.vue:769` (ticket cuisine) → `:800`.
fix-suggéré: remplacer le toast optimiste par un état « envoi… » sur le bouton, et ne toaster
  qu'une seule fois, au verdict réel du pont.
```

### [P1] `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue:38` + `app/Services/Cash/CashDrawerService.php:129/169/173/240/244` — les messages d'erreur de la caisse sont en ANGLAIS technique (ADR-007 = FR)
```
friction: au moment le plus sensible de la journée (la clôture), le caissier lit dans le
  bandeau rouge : « Cannot reconcile an open session — close it first », « Cash drawer session
  not found », « closing_amount must be >= 0 », ou « Cash variance -40.00€ exceeds threshold
  2.00€ — variance_reason required ». Il ne sait ni ce qu'on lui demande ni quoi faire.
evidence: `PosCashDrawerSessionDialog.vue:32-39` le bandeau affiche `{{ errorMessage }}` brut ;
  `:578-583 _extractError` renvoie `err.response.data.message` tel quel.
  Messages sources anglais : `CashDrawerService.php:129`, `:169`, `:173`, `:240`, `:244`
  et `:279-283` (`sprintf('Cash variance %.2f€ exceeds threshold %.2f€ — variance_reason required')`).
  Même famille côté encaissement : `app/Services/PaymentService.php:1021`
  `throw new \InvalidArgumentException('This order is not a pending counter payment.', 422)`,
  renvoyé verbatim par `routes/api.php:1194-1195` (`catch (\Exception $exception) { ... $exception->getMessage() }`)
  puis affiché tel quel par `pos/PosCounterCollectModal.vue:767-768`.
fix-suggéré: mapper ces exceptions sur des clés FR (`error_code` déjà présent côté variance)
  et n'afficher `data.message` qu'en dernier recours.
```

### [P1] `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue:402-404` — 1 centime d'écart oblige à rédiger une justification
```
friction: le seuil serveur toléré est 2,00 €, mais l'écran exige un motif d'au moins
  3 caractères dès 0,005 € d'écart. Chaque fin de service, pour un centime d'arrondi, le
  caissier doit taper du texte au clavier tactile sinon le bouton « Confirmer la clôture »
  reste grisé — sans lui dire pourquoi.
evidence: `PosCashDrawerSessionDialog.vue:402-404`
    `varianceRequiresReason() { return this.mode === 'close' && Math.abs(this.liveVariance) > 0.005; }`
  `:405-409 varianceReasonMissing` exige `trimmed.length >= 3`
  `:256` `:disabled="loading || varianceReasonMissing"` (bouton grisé)
  vs `config/cash.php:31` `'variance_threshold_eur' => (float) env('CASH_VARIANCE_THRESHOLD_EUR', 2.00)`
  et `app/Services/Cash/CashDrawerService.php:266,276` `if (abs($variance) > $threshold)`.
fix-suggéré: lire le seuil serveur (déjà exposable) et n'exiger le motif qu'au-delà, comme le backend.
```

### [P2] `resources/js/components/admin/pos/v5/PosV5Numpad.vue:48-67` — aucun bouton « billet » à l'encaissement, alors que le motif existe déjà ailleurs dans le code
```
friction: le client tend un billet de 20 € sur un total de 13,40 €. Le caissier doit taper
  « 2 » puis « 0 » sur le pavé au lieu d'un seul appui. Geste répété des centaines de fois
  par service. Le dialog d'ouverture de caisse, lui, propose déjà +5 / +10 / +20 / +50.
evidence: `PosV5Numpad.vue:49-66` la liste `keys` ne contient que 0-9, `00`, séparateur, ⌫, C.
  `PosCounterCollectModal.vue:152-158` monte ce pavé sans chips complémentaires.
  Le motif existe : `PosCashDrawerSessionDialog.vue:370` `increments: [5, 10, 20, 50]`
  rendus en boutons `:61-70`. `PaymentComponent.vue` **[FROZEN]** n'a pas de chips non plus
  (seul un « Suggérer les rendus monnaie » arrondi au 5 € supérieur, `:269-271`).
fix-suggéré: ajouter une rangée de chips billets (5/10/20/50 + « Appoint ») au-dessus du pavé
  dans `PosCounterCollectModal.vue` (hors zone gelée).
```

### [P2] `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue:292` et `:294` — le tableau des mouvements affiche des valeurs de base brutes, en anglais
```
friction: le caissier ouvre « Voir les mouvements » pour comprendre son écart et lit
  « order_payment », « drawer_open », « adjustment », « ↑ IN » / « ↓ OUT ». Ce sont les
  constantes de la base, pas du français.
evidence: `PosCashDrawerSessionDialog.vue:292` `<td>{{ movement.type }}</td>`
  `:294` `{{ movement.direction === 'in' ? '↑ IN' : '↓ OUT' }}`
  Valeurs sources : `app/Models/CashMovement.php:25-29` (`order_payment`, `cashback`,
  `drawer_open`, `drawer_close`, `adjustment`) et `:31-32` (`in`/`out`).
fix-suggéré: table de libellés FR (« Encaissement », « Rendu monnaie », « Ouverture tiroir »,
  « Clôture », « Ajustement ») + « Entrée / Sortie ».
```

### [P2] `resources/js/components/admin/pos/PosStockOutflowModal.vue:113-118` — le bouton « Enregistrer » reste grisé sans dire pourquoi
```
friction: pour déclarer un repas personnel, le caissier tape « frite » dans le champ produit.
  Le bouton reste gris. Rien ne lui dit qu'il doit choisir une entrée EXACTE de la liste
  déroulante (`<datalist>` n'affiche pas toujours de suggestion sur écran tactile Windows).
evidence: `PosStockOutflowModal.vue:113-116`
    `selectedItem() { ... return this.items.find((it) => (it.name||'').toLowerCase() === q) || null; }`
  → égalité STRICTE de chaîne ; `:117-118 canSubmit` exige `!!this.selectedItem` ;
  `:63` `:disabled="!canSubmit || submitting"` sans message associé.
fix-suggéré: liste de résultats cliquable (au lieu de `datalist`) ou message
  « Choisis un produit dans la liste » sous le champ quand `search` est rempli mais non résolu.
```

### [P2] `resources/js/components/admin/pos/ParkedOrdersComponent.vue:219-222` — restaurer une commande garée est refusé, sans proposer la sortie
```
friction: le caissier a commencé une commande, un client garé revient. Il ouvre le tiroir
  « En attente », clique « Restaurer » → toast « Videz ou parquez le panier courant ». Il doit
  fermer le tiroir, garer/vider à la main, ré-ouvrir le tiroir, re-cliquer. 4 gestes pour
  ce qui devrait en être un.
evidence: `ParkedOrdersComponent.vue:219-222`
    `if ((this.$store.getters['posCart/lists'] || []).length > 0) {
       alertService.info(this.$t('pos.park_restore_requires_empty_cart')); return; }`
  Aucun bouton d'action dans ce chemin.
fix-suggéré: proposer sur place « Garer le panier courant puis restaurer » (l'action `park`
  existe déjà côté store `posParked`).
```

### [P2] `resources/js/components/admin/pos/PosComponent.vue:3190-3208` — le rappel « ouvre ta caisse » ne se fait qu'UNE fois et se ferme d'un clic à côté
```
friction: au chargement, si aucune session n'est ouverte, une modale s'affiche. Un clic sur
  le fond la ferme (`onBackdrop` → `emitClose`) et le drapeau `_cashSessionAutoChecked` empêche
  toute nouvelle proposition. Le caissier peut encaisser en espèces toute la journée hors
  session : chaque vente déclenche alors un avertissement « mouvement non enregistré »
  … sans bouton pour ouvrir la caisse depuis cet avertissement.
evidence: `PosComponent.vue:3191-3192` `if (this._cashSessionAutoChecked) return; this._cashSessionAutoChecked = true;`
  `PosCashDrawerSessionDialog.vue:484-486` `onBackdrop() { this.emitClose(); }`
  Avertissement par vente : `pos/PosCounterCollectModal.vue:702-710`
  (`cash_movement_skipped` → « Aucune session caisse ouverte — mouvement non enregistré »).
fix-suggéré: dans le toast d'avertissement, un bouton « Ouvrir la caisse » ; et re-proposer la
  modale au premier encaissement espèces sans session.
```

### [P2] `resources/js/helpers/posBarcode.js:13-44` — le détecteur de code-barres écoute AUSSI ce qu'on tape dans les champs de saisie
```
friction: le détecteur est posé sur `window` en phase capture et ne regarde jamais la cible.
  Dès que 6 caractères s'enchaînent vite, la touche Entrée est confisquée (`preventDefault`)
  et transformée en recherche de code-barres — y compris dans le champ de recherche produit
  ou un champ client. Le caissier voit « code-barres introuvable » au lieu de sa recherche.
  Le helper voisin, lui, exclut correctement les champs.
evidence: `posBarcode.js:42` `window.addEventListener('keydown', handler, true);` (capture)
  `:17-40 handler` — aucun test sur `event.target`
  `:22-27` `if (event.key === ENTER_KEY) { if (buffer.length >= BARCODE_MIN_LENGTH) { ... event.preventDefault(); } }`
  À comparer à `:63-71` dans `createFKeyShortcuts` qui ignore `INPUT`/`TEXTAREA`/`isContentEditable`.
fix-suggéré: appliquer au détecteur de code-barres la même exclusion de cible que les touches F.
```

### [P3] `resources/js/components/admin/pos/PosComponent.vue:4731-4740` + `resources/js/helpers/posBarcode.js:54-84` — les raccourcis F1…F12 existent mais rien ne le dit au caissier
```
friction: F1 à F12 basculent de catégorie. Aucune pastille, aucune légende, aucun écran d'aide.
  Un raccourci que personne ne connaît n'existe pas.
evidence: `PosComponent.vue:2966-2969` installe `createFKeyShortcuts((idx) => this.onFKeyShortcut(idx), ...)`
  `PosComponent.vue:4731-4740` `onFKeyShortcut(idx)` → `this.categories?.[idx - 1]`
  `grep -rn "onFKeyShortcut\|fkey" resources/js` → aucune occurrence dans un template.
  Le composant de recherche sait pourtant afficher une touche : `pos/v5/PosV5SearchInput.vue:18`
  `<kbd v-if="showKbd">{{ kbd }}</kbd>` (prop non utilisée ici, à vérifier côté template POS).
fix-suggéré: afficher « F1 »… sur les pastilles de catégorie (ou au survol) — coût nul, gain quotidien.
```

### [P3] `resources/js/components/admin/pos/PosComponent.vue:258` — libellé FR écrit en dur hors i18n
```
friction: cosmétique — le message d'alerte « caisse ouverte depuis X jours » échappe au
  système de traduction, donc à toute relecture centralisée du ton.
evidence: `PosComponent.vue:257-259`
    `:title="cashSessionStale ? 'Cette caisse est ouverte depuis ' + cashSessionDays + ' jours — elle n\'a jamais été comptée. Clôture-la pour connaître ton écart.' : $t('label.cash_session_dialog_title')"`
  Cas assumé documenté ailleurs dans le même fichier : `PosComponent.vue:1052`
  « FR hardcodé — précédent assumé dans ce fichier ». `PosStockOutflowModal.vue` est
  intégralement en FR littéral (`:10`, `:18`, `:33`, `:63`…).
fix-suggéré: clé i18n paramétrée, cohérente avec les voisines.
```

### [P3] `app/Services/PaymentService.php:429` — message d'erreur français sans accents
```
friction: « Le montant recu est inferieur au total a encaisser. » — visible tel quel par le
  caissier via la remontée `data.message` de `PosCounterCollectModal.vue:767`.
evidence: `app/Services/PaymentService.php:429` `'received' => 'Le montant recu est inferieur au total a encaisser.'`
fix-suggéré: accentuer (« reçu », « inférieur », « à »).
```

### [P3] `resources/views/admin-pos-v4.blade.php:35` et `:136` **[FROZEN]** — les gros assets caisse sont re-téléchargés à CHAQUE chargement
```
friction: `?v=…-{{ time() }}` rend l'URL différente à chaque requête : le navigateur du poste
  de caisse ne peut jamais mettre en cache les 323 Ko du wizard ni les 47 Ko de CSS. Démarrage
  et rechargement plus lents qu'ils ne devraient l'être.
evidence: `resources/views/admin-pos-v4.blade.php:35`
    `<link rel="stylesheet" href="{{ asset('css/pos-wizard.css') }}?v=2-{{ time() }}">`
  `:136` `<script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>`
  Tailles mesurées : `public/js/pos-wizard.js` = 323 357 o., `public/css/pos-wizard.css` = 47 763 o.
fix-suggéré: **[FROZEN — nécessite LOCK]** remplacer `time()` par le hash du fichier (`filemtime`
  ou `md5_file`) pour garder l'invalidation sans casser le cache.
```

---

## BLOC 3 — TROU DE PREUVE EN TEST RÉEL

> `TEST-UNIT` = PHPUnit/Vitest vérifié par `ls` · `TEST-E2E` = spec Playwright vérifiée ·
> `TEST-VACUOUS` = le test existe mais ne prouve pas le comportement.

| Fonction BASE | Preuve |
|---|---|
| **Ouvrir la session de caisse (fond)** | `TEST-UNIT` `tests/Feature/Cash/CashDrawerEndpointsTest.php:53,262,289,324` · `tests/js/PosCashDrawerSessionDialog.spec.js` — `TEST-E2E` `tests/e2e/verif-globale-fiscal-cash-2026-08-14.spec.js:465-477` (vrai dialog, vraie requête). **Couvert.** |
| **Clôturer + réconcilier la caisse** | `TEST-UNIT` `tests/Feature/Cash/CashDrawerCloseVarianceTest.php:75-166` · `CashVarianceGateTest.php:75-218` · `tests/js/cashDrawerServiceReconcileReason.spec.js` — `TEST-E2E` `verif-globale-fiscal-cash-2026-08-14.spec.js:449-543`. **MAIS le seul e2e tourne en Branch Manager** : `tests/e2e/verif-globale-fiscal-cash-2026-08-14.spec.js:225` `$u->assignRole('Branch Manager');`. → **AUCUNE PREUVE RÉELLE** que le rôle réellement porté par le caissier (POS Operator) peut clôturer sa propre caisse avec un écart. |
| **Rattraper une session CLOSED-non-réconciliée** | **AUCUNE PREUVE RÉELLE — et aucune UI.** Le test `verif-globale-fiscal-cash-2026-08-14.spec.js:440-441` laisse au contraire cet état comme résultat attendu (`expect(row.status).toBe('closed'); expect(row.variance).toBeNull();`) et ne vérifie jamais qu'on peut le terminer. Voir P0 du BLOC 2. |
| **Prendre une commande + encaisser en espèces (caisse directe)** | `TEST-E2E` fort : `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js` (PAID + `fiscal_sequence_no > 0` + `domain_events` + stock) et `tests/e2e/test-e2e-abuse-B-pos.spec.js:339-345` (total modale === total panier, rendu === reçu − total). — **`TEST-VACUOUS` sur le chemin nominal** : `tests/e2e/02-pos-cash.spec.js:170-174` conclut sur `hasTicket || hasEmptyCart` (regex de texte de page) — un panier vidé par un ÉCHEC passe le test ; et `:136` `test.fixme(true, …)` abandonne le cycle avant le paiement si le catalogue de l'environnement est vide. |
| **Encaisser par CARTE (TPE)** | **AUCUNE PREUVE RÉELLE du chemin matériel** : `tests/e2e/05-pos-card.spec.js:138` `test.fixme('TPE hardware simulation — full card payment with terminal callback', …)` = test **désactivé en permanence** ; `:105` `test.fixme(true, …)` abandonne le sous-flux. Reste `tests/Feature/Pos/PosCardSaleWithoutTerminalTest.php`, `TerminalIdWireInTest.php`, `PosCardDeclarativeNoNoteTest.php` (`TEST-UNIT`, niveau service). |
| **File d'encaissement (counter-collect)** | `TEST-UNIT` `tests/Feature/Pos/CounterCollectQueueRobustTest.php`, `CounterCollectSplitPaymentTest.php`, `WebOrdersPendingEndpointTest.php`, `WebOrdersPaidEndpointTest.php` · `tests/js/posCounterCollectPrint.spec.js`, `posCounterCollectSplit.spec.js` — `TEST-E2E` `tests/e2e/s2-v2-encaissement-cash-2026-07-29.spec.js:83` (la file décroît d'exactement 1). **Couvert sur le chemin heureux.** |
| **Échec de chargement de la file d'encaissement** | **AUCUNE PREUVE RÉELLE.** Aucun test n'injecte un 403/429/500 sur `counter-collect/pending` pour vérifier ce que voit le caissier. C'est précisément le trou du P1 « ✅ trompeur ». |
| **Ticket client / reçu (impression)** | `TEST-UNIT` `tests/Feature/Pos/PosReceiptPrintFlowTest.php`, `PosTicketBytesEndpointTest.php` · `tests/js/posReceiptPrintFlow.spec.js`, `receiptComponentBridge.spec.js`, `receiptAutoPrint.spec.js`, `receiptNf525FiscalRender.spec.js`, `pos/receiptNonBlockingPrint.spec.js` — `TEST-E2E` `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts:306` (bloc `NF525|SIRET|TVA` sur le reçu). **Couvert.** Non couvert : le cas « pont hors ligne » côté ressenti caissier (double toast contradictoire). |
| **Tiroir-caisse « sans vente »** | `TEST-UNIT` `tests/js/posComponentNoSaleTrace.spec.js`, `tests/js/posCashDrawerOpen.spec.js`, `tests/js/posDrawerBridgeFallback.spec.js` · `tests/Feature/EscPosOpenDrawerTest.php` — `TEST-E2E` : **AUCUNE PREUVE RÉELLE** (seule mention dans la famille `audit-pos-cycle*`, qui est tautologique, cf. ci-dessous). |
| **Suivi des commandes / passage à LIVRÉ** | `TEST-UNIT` `tests/js/PosOrdersTrackerComponent.spec.js`, `posTrackerCashPendingAllStatuses.spec.js`, `posTrackerStaleness.spec.js`, `posOrdersTrackerWebVisibility.spec.js` — `TEST-E2E` `tests/e2e/test-e2e-abuse-B-pos.spec.js:424` (montant de la ligne tracker === total payé). **Couvert.** |
| **Clôture Z** | `TEST-E2E` `tests/e2e/test-e2e-abuse-H-fiscal-z.spec.js:169-183` (séquence Z distincte, croissante, sans trou) + `tests/e2e/zone1-fiscal-convergence.spec.js:106`. **Couvert côté séquence.** Le geste « clôturer le Z depuis l'écran » est en revanche **`TEST-VACUOUS`** : `tests/e2e/max-test-t2-pos-2026-05-28.spec.js` déclare un test « Z report close + PDF » avec **0 `expect(`** dans tout le fichier (23 `test(`, 0 `expect(` — vérifié). |

### Familles de tests qui ne prouvent rien (vérifié par comptage)
| Fichier | `test(` | `expect(` |
|---|---|---|
| `tests/e2e/max-test-t2-pos-2026-05-28.spec.js` | 23 | **0** |
| `tests/e2e/goal-functional-pos-2026-05-28.spec.js` | 8 | **0** |
| `tests/Playwright/zz-audit-caissier-s4-2026-08-02.spec.js` (« remboursement + tiroir ») | 5 | **0** |
| `tests/Playwright/zz-audit-caissier-s10-2026-08-02.spec.js` (« split espèces + carte CONFIRMÉ (ticket + DB) ») | 3 | **0** |
| `tests/e2e/wave-m2-cash-recon-2026-05-21.spec.js` | 4 | 3 — et les 3 sont `expect(fs.existsSync(...png)).toBe(true)` (`:477-478`) |

La famille `tests/e2e/audit-pos-cycle*.spec.js` + `audit-pos-wave6-2026-05-08.spec.js` (38 blocs)
est **tautologique** : l'unique assertion est `expect(findings.length).toBeGreaterThan(0)`
(`audit-pos-cycle-2026-05-06.spec.js:346`, `audit-pos-cycle5-2026-05-06.spec.js:380`,
`audit-pos-wave6-2026-05-08.spec.js:639`) — un constat `P0` compte autant qu'un `OK`, donc ces
fichiers passent au vert tout en enregistrant des bugs bloquants. Ils couvrent nominalement
« Print receipt DUPLICATA », « Open cash drawer (no-sale) », « Payment modal ».
*(Comptages `test(`/`expect(` re-vérifiés par `grep -c` sur les 5 fichiers du tableau ; les
totaux de la famille `audit-pos-cycle*` proviennent du relevé du sous-agent, les 3 `file:line`
d'assertion cités ont été retenus tels quels — à re-confirmer ligne à ligne avant tout goal.)*

---

## BLOC 4 — CASSÉ / INACHEVÉ CONNU

1. **`resources/js/services/CashDrawerService.js:156-165` — code mort.** `export async function reconcile(sessionId)` n'a **aucun appelant** (`grep -rn "\.reconcile(" resources/js` → 0). C'est la fonction qui aurait précisément permis de rattraper une clôture bloquée (cf. P0).

2. **Multi-tranche non implémenté à l'encaissement (3 sites, même dette).**
   `pos/PosCounterCollectModal.vue:18-27` : « SCOPE CHANGE … multi-tranche split deferred to
   V1.0.2 … A naive loop over tranches would silently lose tranches 2..N » ;
   `PosComponent.vue:1891` et `:4300` répètent le report. En pratique la modale simule un
   MIXTE à **2 tranches exactement** (`PosCounterCollectModal.vue:640-652`).

3. **`app/Services/Payments/SplitPaymentService.php:280` — « UI Stage B not yet shipped ».**
   Le `terminal_id` par tranche est accepté côté service mais l'écran qui le fournit n'est pas
   livré ; la ventilation TPE du rapport Z retombe sur un seul seau « Sans TPE » pour ces appels.

4. **`app/Http/Controllers/Admin/PosController.php:91-96` — la garde « caisse ouverte » est
   court-circuitée en simulation matérielle.**
   ```php
   if (config('pos.simulation_hardware') === true) { return; }
   ```
   dans `assertCashDrawerSessionOpenIfCashInvolved()`. Conforme au cadre CLAUDE.md §8
   (`POS_SIMULATION_HARDWARE=true` accepté en dev, refusé au boot en prod par
   `AppServiceProvider`), donc **pas un défaut de production** — mais cela signifie que tout
   test/observation faite en simulation ne prouve RIEN sur la contrainte de session de caisse.

5. **`app/Services/PaymentService.php:247` — une vente espèces peut être PAID sans mouvement de
   tiroir.** Commentaire dans le code : « Un encaissement cash hors session
   (recordCashOrderMovement best-effort → skip) pose PAID sans IN ». Le garde-fou
   `hasRecordedCashIn($order)` (`:249`) évite la sortie fantôme au remboursement, mais l'entrée
   manquante reste. Surfacé au caissier par un avertissement seulement
   (`PosCounterCollectModal.vue:702-710`).

6. **`resources/js/components/admin/pos/ParkedOrdersComponent.vue:236-244` — repli i18n devenu
   inutile.** Le commentaire dit « Retire le fallback quand les clés seront ajoutées » ; les clés
   `pos.park_discard_confirm` et `pos.park_discard_confirm_aria` **existent désormais**
   (`resources/js/languages/fr.json:246-247`). La fonction `tf()` et ses 2 appels (`:100`, `:105`)
   sont du code de transition à retirer.

7. **`resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:1848-1851` — couleur en dur.**
   « Hardcoded blue (not V5 token) — V5 palette has no info/blue role » (`--pos-tracker-blue: #1d4ed8`).

8. **Dette d'architecture route.** Six endpoints caisse à fort trafic sont des **closures dans
   `routes/api.php`** au lieu de contrôleurs : `counter-collect/pending` (`:944-1016`),
   `web-orders/pending` (`:1025-1054`), `web-orders/paid` (`:1082-1103`),
   `counter-collect/{order}/confirm` (`:1141-1196`), `counter-collect/{order}/cancel` (`:1197-1216`),
   `collect-kiosk-cash/{order}` (`:1217-1227`) — ~280 lignes de logique métier et de validation
   hors couche contrôleur, donc hors de portée des sentinelles FormRequest.

9. **Aucun marqueur `TODO`/`FIXME`/`HACK` dans le périmètre PHP ni Vue de la caisse** (sweep
   `grep -rnwiE` sur les 20 chemins du périmètre → 0). Les dettes ci-dessus sont documentées en
   prose, donc **invisibles à un grep TODO standard** — point à retenir pour tout futur audit.

### Non-constats (vérifiés puis écartés, pour éviter qu'on les re-signale)
- `resources/js/components/admin/pos/CaisseSecondaryNav.vue:118` utilise `axios` sans `import` :
  **fonctionne**, `window.axios` est global (`resources/js/bootstrap.js:14`). Fragile, pas cassé.
- `GET orders/{order}/escpos` et `POST customer-display` **sont** gardés :
  `Pos/PosTicketBytesController.php:37-41` et `Pos/PosCustomerDisplayController.php:29`.
- Pas de saturation du seau `pos-order-update` : cadence mesurée ≈ 36 req/min par poste
  (3 endpoints × 12/min) contre un plafond de 120/min
  (`app/Providers/RouteServiceProvider.php:276-279`, `config/pos.php:64`).
