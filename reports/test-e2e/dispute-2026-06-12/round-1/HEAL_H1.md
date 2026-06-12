# HEAL H1 — Backend intégrité (dispute round-1, 2026-06-12)

Healer H1. Périmètre: app/** (hors frozen), routes/**, config/**, database/**, tests/** backend, resources/js/pos-app.js uniquement.
Branche `release/v1-2026-06-10`, worktree partagé. Rapport incrémental — fix par fix.

---

## Fix 1 — A-RED-1 + A-RED-2 [P0+P1] — remise manuelle caisse → 401 « Order quote intent mismatch » → logout + panier perdu — ✅ HEALED

- **SHA**: `9b4cb6af3`
- **Root cause** (confirmée par test RED reproduisant le payload réel frontend) :
  1. Le quote POS est scellé AVEC `manual_discount` (`OrderQuoteService::canonicalPayload` :413) ;
  2. le FROZEN `PaymentComponent.vue:878` strippe `discount` du POST order (POS-A6, commit `aafa8c8f1`) ;
  3. `sealForCommit` re-canonicalise avec `manual_discount=0` → hash ≠ → `resolveReplay` 401 « intent mismatch » ;
  4. `pos-app.js:53-67` traite tout 401 comme session morte → logout → panier perdu.
- **Fix backend (3 volets, AUCUN frozen touché)** :
  1. `app/Services/OrderService.php::posOrderStore` (tête de méthode) — quand `quote_token` présent ET champ `discount` ABSENT du POST, restore la remise depuis le **quote persisté serveur** (`canonical_payload.discounts.manual_discount`), jamais depuis une valeur client. La remise restaurée repasse `assertPosManualDiscountAllowed` (permissions re-vérifiées). Anti-tamper INTACT : un POST qui CHANGE un champ liant (items, montant de remise explicite ≠ quote…) échoue toujours le hash d'intent — test dédié le prouve.
  2. `app/Services/Order/OrderQuoteService.php` — gardes d'intégrité ≠ auth : `:115` paire token/signature manquante → **422** (était 401) ; `resolveReplay` invalid/signature/intent mismatch → **409** (étaient 401) ; `:342` expired reste **410**. Aucun contrôle supprimé ni affaibli.
  3. `resources/js/pos-app.js` intercepteur — logout uniquement sur 401 **réellement auth** (message Sanctum « Unauthenticated. » ou vide) ; tout autre 401 remonte au catch appelant (toast) sans détruire la session/panier.
- **Tests (TDD — RED d'abord, reproduisant `{"status":false,"message":"Order quote intent mismatch."}`)** :
  - `tests/Feature/QuoteTamperTest.php::test_pos_commit_with_stripped_discount_field_honors_quote_discount` — quote AVEC discount 1,00 € (10%) + POST order SANS le champ (payload frontend réel) → **201**, order.discount=1.0, total=9.0, quote consommé.
  - `tests/Feature/QuoteTamperTest.php::test_pos_commit_with_stripped_discount_and_tampered_items_is_still_rejected` — même strip + items modifiés → **409**, 0 order, quote non consommé.
  - Statuts mis à jour : QuoteTamperTest ×3 (401→409), QuoteBindingTest ×2 (401→422/409), KioskQuoteIntegrityTest ×1 (401→409).
- **Runs** : QuoteTamperTest 5/5, QuoteBindingTest 4/4, KioskQuoteTokenRequiredOnCommitTest 4/4, KioskQuoteIntegrityTest 2/2, QuoteExpirationTest 2/2, QuoteReplayIdempotencyTest 3/3, QuoteDiscountAuthoritativeTest 1/1, PosDiscountTest 3/3, PosDiscountPermissionTest 8/8, PosManualDiscountAuditTest 1/1, QuoteCurrencyOriginTest 2/2, KioskSecurityTest 6/6, PosWalkInAndDeliveryFeeTest 1/1, ConcurrentOrderTest 3/3, AntiGravityTest 20/20, MultiVariationValidationTest 9/9, KioskPaymentStateMachineTest 5/5, KioskLoyaltyDoubleRedeemRefusedTest 5/5, KioskLoyaltyLedgerAtomicTest 3/3, KioskQuoteForgesBranchIdSilentlyOverriddenTest 5/5, PosPriorityApiTest 2/2 — **tous verts**.
- **Tripwire frozen** : `git diff --stat HEAD~1..HEAD -- <13 frozen>` = vide ✅
- **Repro live** : à faire en fin de session (remise 10% → espèces → 201 + receipt).

---

## Fix 2 — C-RED-01 + E-ADV-1 [P0] — promo borne affichée 0,00 € jamais facturée — ✅ HEALED

- **SHA**: `c0518cf50`
- **Heal « promo dormante » de `heal/ultra-audit-w4-2026-06-11` inspecté** (`git show 9e2d9eda6`) : c'est un GATE de dormance (`kiosk.promos_redeemable=false` qui CACHE la promo : validate+bannière+preview) — l'inverse du mandat (« la remise doit traverser quote → order »). NON cherry-pické ; réimplémentation scope-minimal de la facturation réelle.
- **Root cause** : `PricingPreviewService` applique `kiosk_promo_code` (affichage panier) mais `OrderQuoteService::calculatePricing(kiosk)` ne lisait jamais le code (canonical metadata only :416) et `FrontendOrderService::myOrderStore` ne l'appliquait pas → commande pleine, `uses_count` jamais consommé.
- **Fix** (PricingService frozen INTOUCHÉ — remise order-level au-dessus du PricingResult SSOT, même patron que la fidélité kiosk) :
  - `OrderQuoteService::withKioskPromoDiscount` (quote) — `KioskPromo::findValid` + `computeDiscount` sur `accumulatedSubtotal`, stack avec fidélité, coupon prioritaire, kill-switch V1 `pos.manual_discount_enabled` respecté (parité avec la gate order-side).
  - `OrderQuoteService::discountedKioskTotal` — total recalculé TTC-aware (l'ancienne formule loyalty re-additionnait totalTax sur des lignes TTC = double-compte latent).
  - `FrontendOrderService::applyKioskPromoDiscount` (order) — miroir exact, row `lockForUpdate` (course max_uses sérialisée), **consommation `uses_count++` DIFFÉRÉE post-`sealForCommit`** (le seal re-prix dans la même tx : incrémenter avant invalidait une promo max_uses contre sa propre consommation → faux intent mismatch).
  - `KioskPromo::isRedeemableFor` — extraction verbatim des checks de `findValid` pour re-valider sur la row verrouillée.
- **Tests** (`tests/Feature/KioskPromoBillingTest.php`, RED d'abord — reproduisait quote discount=0/commande pleine) : quote applique la promo ; commande facture + consomme (`uses_count` 0→1) ; code invalide = plein tarif ; **anti-tamper** : promo ajoutée APRÈS le quote → 409 ; promo épuisée (max_uses) → 0 remise. 5/5.
- **Régression** : KioskPromoModelTest 8/8, KioskEndpointsTest 17/17, SsotInjectionHardening 6/6, quotes kiosk 6/6, loyalty 8/8, BranchScopeCoverageSentinel 1/1.
- **Tripwire frozen** : vide ✅

---

## Fix 3 — C-RED-02 [P0] — rachat fidélité borne « −1,65 € » affiché, commande pleine, points jamais débités — ✅ HEALED (backend) + note H2

- **SHA**: `00dcbffda`
- **Root cause (2 étages)** :
  1. `withKioskLoyaltyDiscount`/`applyKioskLoyaltyDiscount` exigent `request.discount > 0`, or `buildKioskQuotePayload` (kioskCart.js:142-158) n'envoie JAMAIS de champ discount → garde court-circuitée à 100 %.
  2. Latent : à l'order-commit, `buildKioskOrderPayload` écrase `discount = quote.discount` (combiné promo+fidélité) → re-nourrir ce montant au moteur fidélité ferait diverger quote/commit dès qu'une promo stack.
- **Fix backend** :
  - Champ DÉDIÉ `loyalty_redeem_discount` (priorité, fallback `discount` legacy intact) lu par les deux moteurs (quote `OrderQuoteService` + order `FrontendOrderService`) — l'intention de rachat survit à l'écrasement frontend. **Identify-only (loyalty_code sans champ de rachat) n'est JAMAIS auto-rédimé** (un client qui cumule seulement ne brûle pas ses points) — testé.
  - **Débit points + ledger DIFFÉRÉS post-`sealForCommit`** (`consumePendingKioskLoyaltyRedemption`) : le seal re-prix dans la même tx ; débiter avant faisait échouer le check de solde (`kioskLoyaltyRedemption`) → remise zérotée → faux « intent mismatch » 409. Row user `lockForUpdate` tenue jusqu'au commit = pas de sur-tirage concurrent. La voie pending-redeem (attach sans 2e déduction) est préservée, déférée pareillement.
  - Vérifié flux complet : points débités (150→50 pour 1,00 € @ rate 100), ledger `loyalty_transactions` (type=redeem, points=-100, order_id) ✅. Wallet (`users.balance`) non touché par le redeem — correct.
- **Tests** (`tests/Feature/KioskLoyaltyBillingTest.php`, RED d'abord) : quote via champ dédié ; commit payload RÉEL frontend (discount écrasé par quote.discount) → 201, remise facturée, points débités, ledger lié ; identify-only = 0 remise 0 débit ; legacy `discount` toujours honoré ; **stacking promo+fidélité bout-en-bout** (quote 3,00 € = 2+1 ; commit 201 ; points -100 ; uses_count 1). 5/5.
- **Régression** : LoyaltyApiTest 9/9, OrderCancellationLoyaltyTest 2/2, tests/Feature/Loyalty 20/20 (sentinels clawback/whole-point/TTC), Kiosk* suites re-runs OK.
- **⚠️ NOTE ORCHESTRATEUR (part frontend → H2, fichier kioskCart.js hors de mon périmètre)** : pour activer le flux borne réel, `buildKioskQuotePayload` (resources/js/store/modules/kioskCart.js) doit ajouter `loyalty_redeem_discount: state.loyaltyDiscount || 0`. Le champ se propage mécaniquement au POST order (payload order = payload quote + extras) et le backend gère déjà tout le reste (priorité sur l'écrasement `discount`). Sans ce wire-up, la borne reste à 0 remise fidélité (mais n'affiche plus de fausse promesse si H2 synchronise l'affichage sur quote.discount).
- **Tripwire frozen** : vide ✅

---

## Fix 4 — ADV-B-07 + E-ADV-2 [P0] — Vue Caisse Unifiée + /admin/transactions aveugles aux ventes POS directes (CA −55 %) — ✅ HEALED

- **SHA**: `b824dd933`
- **Root cause** : les rows `transactions type='payment'` ne naissaient QUE dans les callbacks gateway (`PaymentService::payment`, gateway-gated) et au counter-collect (`COUNTER-*`). La vente POS directe (PAID inline à la création) n'avait AUCUN writer → `CashOverviewController` (build sur `Transaction::where(type,'payment')`) et `/admin/transactions` ignoraient tout le volume caisse direct.
- **Fix write-side (structurel, couvre les 2 surfaces + toute future surface)** : `OrderService::posOrderStore` minte la Transaction à la création inline-paid (`POS-<id>-<ts>`, slug mode réel cash/card/mobile_banking/ticket_restaurant/other, `split` si multi-tender). **Zéro double-compte** : ordres différés counter-collect exclus (leur unique Transaction naît à l'encaissement) + `firstOrCreate(order_id, type)`. NF525 intouché (fiscal seq + audit déjà écrits en amont ; ceci est le ledger de reporting). Effet bonus : `cashBack` (early-return « no prior payment ») devient fonctionnel pour les ventes POS.
- **Tests** (`tests/Feature/PosDirectSaleTransactionLedgerTest.php`, RED d'abord) : vente cash directe → row payment cash/+/total exact, 1 seule row ; différé → 0 row à la création puis exactement UNE `COUNTER-*` après confirm ; **agrégats** GET /api/admin/cash-overview → grand total + by_source.caisse + by_mode.cash incluent la vente. 3/3.
- **Régression** : Pos 86/86, Payment 30/30, Cash 101/101, Reconciliation 7/7, AutoPrepare 12/12, CashOverviewControllerTest 19/19.
- **LIVE :8768** : vente 4569 → `transactions` POS-4569 cash +3,42 ; cash-overview CAISSE 25,00/1tx → **28,42/2tx**, grand total 39,80 → 43,22. (Les ventes POS antérieures au fix restent sans row — DB jetable, pas de migration corrective mandatée.)
- **Tripwire frozen** : vide ✅

---

## Fix 5 — B-R1-15 + E-ADV-5 [P0/P1] — refund espèces affiché « Carte bancaire » (slug 'credit' en dur ×3) — ✅ HEALED

- **SHA**: `14d897928`
- **Root cause** : 3 call-sites `cashBack($locked, 'credit', 'TXN-…')` en dur (OrderService::changeStatus RETURNED + self-cancel REJECTED/CANCELED, FrontendOrderService cancel kiosk/web) ; `TransactionResource:51` mappe 'credit' → « Carte bancaire » → un refund ESPÈCES (sortie tiroir réelle) affichait « Carte bancaire » au grand livre.
- **Fix** : `OrderService::refundLedgerMethod(order)` (public static, Order + FrontendOrder) = mode RÉEL de l'encaissement d'origine (`order->transaction->payment_method` : cash→cash, counter_cash→counter_cash, stripe→stripe) ; fallback 'credit' uniquement si mode inconnu (défensif, cashBack early-return sans payment de toute façon). Appliqué aux 3 call-sites.
- **Tests** (`tests/Feature/RefundPaymentMethodLedgerTest.php`, RED reproduisant 'credit') : refund vente POS cash → cash_back `payment_method='cash'` ; refund commande counter-collected → `'counter_cash'`. 2/2. Régression : refund/cancel/fiscal suites toutes vertes.
- **Tripwire frozen** : vide ✅ (Fiscal/* non touché)

---

## Fix 6 — B-R1-19 [P0, part backend] — Branch Manager 403 sur payment-gateway list (/admin/transactions) — ✅ HEALED

- **SHA**: `42ce66fea`
- **Contexte sécurité** : le gate SET-01 (`permission:settings` sur index) protégeait les SECRETS (`GatewayOptionsResource.value` : stripe_secret…) mais cassait le filtre « Mode de paiement » du BM (403 + PAGEERROR à chaque visite).
- **Fix sans ré-ouvrir la fuite** : index → `permission:settings|transactions` ; **les VALEURS d'options sont strippées au niveau resource** (`PaymentGatewayResource` : options=[] sauf détenteur `settings`) ; update reste `permission:settings` STRICT (2 middlewares empilés). Sentinel `GatewaySecretIndexAuthzSentinelTest` RESTRUCTURÉ (pas affaibli) : index gated par un set incluant settings + update gated settings-EXACT (anti élargissement du write). + test comportemental `PaymentGatewayIndexBranchManagerAccessTest` : BM 200 sans secret (assert chaîne `sk_live_secret_value` ABSENTE), Chef 403, Admin voit les options, BM PUT 403. 4/4 + sentinel 2/2 + Admin 122/122.
- H3 a posé le catch front défensif en parallèle (dégradation filtre) — défense en profondeur conservée.
- **Tripwire frozen** : vide ✅

---

## Fix 7 — ADV-B-08 [P1] — champ `source` contrôlé par le client à la création POS — ✅ HEALED

- **SHA**: `d71131352`
- **Fix** : `$validated['source'] = Source::POS` forcé server-side dans posOrderStore (valeur client ignorée) **ET** canonical quote/commit (`OrderQuoteService::canonicalPayload` force Source::POS pour surface=pos des DEUX côtés — sinon le forcing aurait cassé le hash d'intent de toutes les ventes POS dont le frontend envoie source=1). Kiosk inchangé (le token machine est l'autorité canal).
- **Test** (`PosSourceServerSideTest`, RED : source=5 Web persisté comme au live 4520) : client envoie source=WEB → order persiste source=15 + source_surface='pos', 201. 1/1 + Pos 86/86.
- **LIVE** : order 4569 source=15. ✅
- **Tripwire frozen** : vide ✅

---

## Fix 8 — E-ADV-3 [P1] — identité client borne = « Admin Le Cayenne » + refund borne crédite le wallet ADMIN — ✅ HEALED

- **SHA**: `7102fcd18`
- **Décision scope-minimal documentée** : flipper `orders.user_id` vers le client passage CASSAIT 3 chemins porteurs (`FrontendOrderService::show`/`changeStatus` ownership borne :699/:730 + `finalizePaidKioskOrder` détection :1247) → fix aux 2 points de fuite, plumbing intact :
  1. `SimpleOrderResource::customer_name` (la source de l'historique + tracker) → « Client borne » quand `source_surface` kiosk (check colonne, 0 N+1) — aligné sur le label W2 des surfaces encaissement/show. Client web réel inchangé.
  2. `PaymentService::cashBack` → garde `isWalletCreditableCustomer` : JAMAIS de crédit wallet vers un compte machine borne (KioskMachine), un staff (Admin/BM/POS Operator/Chef/Stuff/Waiter) ou le client passage — le refund est du CASH au tiroir ; le crédit wallet doublait la sortie tiroir (live : balance admin 2,00 → 5,80). Row `cash_back` + audit NF525 inchangés ; vrai client conserve son crédit wallet (testé).
- **Tests** (`KioskOrderIdentityTest`, RED reproduisant « Admin Le Cayenne » + le 2,00→5,80 exact) : 4/4. Régression Payment 30/30, Cash 101/101, OrderHistoryUnified 4/4, BranchIsolation 6/6.
- **Tripwire frozen** : vide ✅ (operator_id d'audit = caissier, intact)

---

## Fix 9 — ADV-F-P1-2 [P1, part backend] — 3 SKU techniques upsell polluent la grille client Sandwich — ✅ HEALED (seeder à exécuter)

- **SHA**: `bbeecd437`
- **Vérité terrain** : dérive DATA — `items` 1/2/3 (menu-frites-boisson, frites-seules, boisson-seule) portent `item_category_id=1` (Sandwich Cayenne) + `is_featured=ACTIVE` dans foodking_e2e. Le design d'origine « category NULL » (commentaire AlignAddonItemsChannelsSeeder) est PÉRIMÉ : `items.item_category_id` est **NOT NULL** (migration 2022_11_17_110514, vérifié MySQL live + SQLite test).
- **Fix data-driven** : `database/seeders/HideUpsellVehicleItemsFromGridSeeder` (idempotent, résolution par SLUG — leçon W4) : catégorie INTERNE dédiée `technique-interne-upsell` `channels=["admin"]` + `is_featured` off. KioskMenuService filtre les catégories par `isVisibleOn('kiosk')` (:71) → les 3 SKU sortent de la grille ET de la sidebar borne (idem toute surface channel-aware). **Orderabilité par id INTACTE** : `PricingService::assertOptionsOrderable` (:537, lu, non modifié) valide les `channels` de l'ITEM addon (laissés NULL=everywhere), jamais la catégorie.
- **Tests** (`tests/Feature/Kiosk/HideUpsellVehicleItemsFromGridSeederTest`) : déplacement + featured off + items ACTIVE + payload KioskMenuService sans les SKU/catégorie interne + vrai produit conservé + non-clobber + idempotence (catégorie non dupliquée). 2/2 (27 assertions). Kiosk 36/36 + KioskPhase1 91/91.
- **⚠️ ACTION ORCHESTRATEUR** : exécuter `APP_ENV=e2e php artisan db:seed --class=HideUpsellVehicleItemsFromGridSeeder` (contre foodking_e2e) avant la capture round-2 — je n'ai pas lancé d'artisan write contre la DB live (règle harness). Le volet image-fallback UI = H2/H3.
- **Tripwire frozen** : vide ✅

---

## Fix 10 — E-ADV-9 [P2 latent] — order_payments jamais alimentée par counter-collect/POS mono-mode → ventilation Z structurellement vide — ✅ HEALED (évalué SÛRE)

- **SHA**: `36147531b`
- **Évaluation NF525 (mandat « ne force pas »)** : le consommateur `ZReportCashEnrichmentService::aggregateByTerminal` est un **décorateur additif HORS signature HMAC** (constat explicite du verdict E adversarial : « PAS de corruption de chaîne ») et n'est PAS modifié (Fiscal/* intouché). Écrire des rows `order_payments` ne change AUCUN octet signé → fix jugé sûr et exécuté.
- **Fix** : une row OrderPayment mono-mode (même shape que les tranches SplitPaymentService) à (a) la création POS inline-paid (`OrderService::posOrderStore` — mode réel, terminal_id du payload si présent, tendered/change pour cash, `change_amount` NOT NULL respecté) et (b) au `PaymentService::confirmCounterPayment`. **Zéro double-compte** : skip si split actif (tranches déjà persistées) ; `firstOrCreate(order_id)` idempotent (re-confirm race-protégé).
- **Contrat inversé assumé** : `SplitPaymentEndToEndTest` affirmait « legacy single-tender ne doit PAS écrire order_payments » (le défaut même que condamne E-ADV-9) → 2 assertions mises à jour vers le nouveau contrat (1 row mono = mode+total entier, JAMAIS les tranches d'un breakdown ignoré flag-OFF).
- **Tests** (`tests/Feature/Payment/MonoModeOrderPaymentVentilationTest`, RED 3/3) : row POS cash (mode/amount/tendered/branch/paid_at), row counter-collect unique, **`aggregateByTerminal` non-vide avec cash_total exact**. Régression APRÈS correctifs : Pos 86/86, Cash 101/101, Payment 33/33, **Fiscal 221 (0 failure, 3 skipped pré-existants)**, ZReportTerminalBreakdown 6/6, ZReportSplitPaymentBucketing 3/3.
- **LIVE** : order 4569 → `order_payments` mode=1 amount=3,42 tendered=10,00 change=6,58. ✅
- **Tripwire frozen** : vide ✅

---

## REPRO LIVE FINALE (:8768, PID 38797, cwd = CE worktree — provenance vérifiée)

Payload EXACT du frontend frozen (quote AVEC discount, POST order SANS le champ) :
- `POST /api/admin/pos/quote` discount 0,38 (10 % Tiramisu 3,80) → 200, total_ttc 3,42.
- `POST /api/admin/pos` SANS champ discount, espèces reçu 10 → **HTTP 201**, order **4569** (#A0023), discount 0,38 facturée, total 3,42, **fiscal_sequence_no 2176 alloué** (pré-fix : 401 « Order quote intent mismatch » → logout → panier perdu).
- DB 4569 : `source=15`, `source_surface=pos`, `transactions` POS-4569 cash +3,42, `order_payments` mode=1/3,42/tendered 10/rendu 6,58.
- `GET /api/admin/cash-overview` : CAISSE 28,42 €/2tx (incluait la vente), grand total 43,22.

## SYNTHÈSE
- 10/10 fixes HEALED, 0 SKIPPED. 10 commits (`9b4cb6af3`→`36147531b`), tripwire frozen vide à chaque commit.
- 10 fichiers app/** touchés (aucun frozen), 1 seeder, 12 fichiers tests (8 nouveaux, ~40 tests TDD neufs).
- Notes orchestrateur : (1) H2 wire-up `loyalty_redeem_discount` dans kioskCart.js ; (2) seeder ADV-F-P1-2 à exécuter sur foodking_e2e ; (3) rebuild front central inutile pour mes fixes backend, requis pour pos-app.js (intercepteur) ; (4) clé i18n : aucune nécessaire côté H1.
