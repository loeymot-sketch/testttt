# Round4 — Flux métier non/sous-testés (delivery / dine-in / parked / split / loyalty / refund)

Lane: abuse des flux moins couverts par les rounds caisse/borne.
DB: foodking_e2e (SELECT only). READ-ONLY, 0 mutation, 0 fichier modifié.

## Méthode
Read des services + DB SELECT + reconstruction des chemins d'évènements.
Fichiers lus : DeliveryBoyCashSessionService, PosParkedOrderService,
SplitPaymentService, PosRedemptionService, LoyaltyService,
ClawbackLoyaltyPointsOnRefund, RefundWithCounterEntryService,
OrderService::changeStatus (RETURNED), PaymentService::cashBack,
PosOrderController::refundWithCounterEntry/refundPreZ,
AwardLoyaltyPointsOnDelivery, PosPurgeParkedOrders.

## Ce qui tient (vérifié, pas de finding)
- SPLIT payment : `persistTranches` atomique (DB::transaction dans la txn
  parente), somme<total → 422, overpay borné à 1€ (TOLERANCE_OVERPAY),
  CARD exige terminal_id ACTIF même-branche (defense-in-depth non-HTTP),
  cash-tranche exige session ouverte (sauf simulation). DB : ordre 4937 =
  1.50 cash + 2.50 card = 4.00 = total. OK.
- PARKED : recall re-price via backend SSOT (payload stocke item_id/options,
  pas de prix figé client → re-calcul PricingService au store, immune à un
  edit de prix entre park et resume). `pruneUnavailableParkedVariations`
  retire les variations inactives/trashed. Purge cron `pos:purge-parked-orders`
  fonctionne. DB : 0 parked résiduel.
- REFUND post-Z (counter-entry) : mirror RETURNED négatif, fresh fiscal_seq,
  mirror order_payments négatifs (Z TPE équilibré), CashMovement CASHBACK
  OUT, refundPoints + RefundCreated (clawback) dispatchés. UNIQUE(parent_order_id)
  bloque le double-mirror. Solide.
- DELIVERY reconciliation : `reconcileSession` calcule expected = opening +
  Σ(signedAmount), variance = closing − expected, fige RECONCILED idempotent.
  DB : 8 sessions reconciled, variances correctement calculées (session 10 :
  opening 50 + collect 13 = expected 63, closing déclaré 0 → variance −63
  correctement flaggée). Pas de trou de calcul.
- LOYALTY redeem : lockForUpdate customer + balance check + UNIQUE(user,order,
  type) anti-double-redeem (409 ALREADY_REDEEMED), points multiple-of-rate,
  discount ≤ subtotal, pre-payment-only guard, branche TTC/HT correcte
  (EDGE-3 P1 déjà healé). clawbackEarnedPoints clamp balance ≥ 0 (jamais
  négatif).

---

## [P3] app/Services/OrderService.php:2150-2278 — Refund pré-Z (RETURNED) ne déclenche PAS le clawback des points GAGNÉS ni la release stock pour les ordres sans Transaction `payment`

**Titre** : asymétrie post-Z vs pré-Z — `RefundCreated` n'est émis sur le
chemin pré-Z RETURNED que via `cashBack()`, qui court-circuite sans
Transaction `payment` préalable → `ClawbackLoyaltyPointsOnRefund` jamais
exécuté.

**Repro (code)** :
1. PosOrderController::refundWithCounterEntry → si NON sealed → `refundPreZ`
   (PosOrderController.php:107-109,215-229) → `OrderService::changeStatus`
   vers RETURNED.
2. changeStatus RETURNED (OrderService.php:2150-2196) : appelle
   `cashBack()` UNIQUEMENT `if ($locked->transaction)` (ligne 2188), puis
   `refundPoints()` (reversal des points REDEEMÉS seulement, ligne 2195).
3. Post-txn (OrderService.php:2255-2278) : `OrderCanceled::dispatch` n'est
   émis QUE pour CANCELED/REJECTED — RIEN n'est dispatché pour RETURNED.
4. Donc `RefundCreated` (seul déclencheur de `ClawbackLoyaltyPointsOnRefund`,
   EventServiceProvider.php:200-213) ne peut venir que de `cashBack`.
5. `PaymentService::cashBack` early-return SANS dispatcher `RefundCreated`
   s'il n'existe pas de Transaction type='payment' (PaymentService.php:129-134).

**Evidence (DB)** :
- `SELECT COUNT(DISTINCT o.id), COUNT(DISTINCT t.order_id) FROM orders o LEFT JOIN transactions t ON t.order_id=o.id AND t.type='payment'`
  → 2968 ordres, seulement 1007 avec Transaction `payment`. 1961 ordres
  sans Transaction `payment` → `$order->transaction` NULL → cashBack jamais
  appelée → RefundCreated jamais émis sur refund pré-Z → points gagnés NON
  repris + stock/availability NON relâchés.
- Comparatif : le refund post-Z (RefundWithCounterEntryService.php:415) ET
  Stripe (Stripe.php:442) dispatchent RefundCreated directement → clawback OK.
  Seul le pré-Z dépend de cashBack → asymétrie.
- Régression du heal P1 documenté GOAL-J2-HEAL-07 (ClawbackLoyaltyPointsOnRefund.php:12-20)
  pour le sous-chemin pré-Z (chemin pré-Z ajouté plus tard, WI-REFUND-PREZ 2026-06-04).

**Lentille** : double-dip fidélité (argent rendu + points conservés) /
asymétrie release stock. Money-adjacent.

**Exposition V1 RÉELLE = quasi nulle** : `SELECT ... WHERE status=22 AND
loyalty_points_awarded>0` → 0 ligne. Aucun ordre RETURNED n'a gagné de
points dans la DB. `SELECT COUNT(*),SUM(loyalty_points_awarded) FROM orders
WHERE loyalty_points_awarded>0` → 2 ordres / 19 pts au total (fidélité
quasi-dormante : code client rarement fourni en caisse). Z fiscal NON
impacté (revenu correctement compté ; gap = solde de points client, non
NF525). D'où P3 et non P2.

**Reco** : faire dispatcher `RefundCreated::dispatch($order)` (after-commit)
sur la transition RETURNED dans `changeStatus`, indépendamment de la présence
d'une Transaction `payment` (idempotent : clawback gardé par
UNIQUE(user,order,'manual_deduct') + pré-check ; AvailabilityService idempotent
via released_qty). Aligne pré-Z sur post-Z. NON-frozen (OrderService).
À confirmer owner : la release stock sur un RETURNED de plat déjà livré
est-elle voulue (post-Z la fait déjà) → décider la sémantique unique.

**Frozen touch** : non (OrderService.php, PaymentService.php non frozen).

---

## [P3] app/Services/OrderService.php:1954-1986 — Collecte cash COD livreur dans DeliveryBoyCashSession = best-effort silencieux (100% des COD livrés sans mouvement)

**Titre** : la collecte doorstep COD n'enregistre un mouvement
`order_collect` dans la session cash livreur que si une session est OUVERTE
au moment du DELIVERED ; sinon skip silencieux (strict=false) → trou de
réconciliation par-livreur (Z fiscal intact).

**Repro (code)** : OrderService.php:1954-1972 — sur DELIVERED COD,
`recordMovement(..., strict=false)` n'est tenté que `if ($openSession)`
(findOpenSessionForDeliveryBoy). Pas de session ouverte → aucun mouvement,
juste un `Log::error` drift non-bloquant (1980-1985). Le commentaire reconnaît
le drift et délègue la détection à ZReportCashEnrichmentService.

**Evidence (DB)** :
- `SELECT o.status, COUNT(*), SUM(CASE WHEN m.order_id IS NULL THEN 1 ELSE 0 END) FROM orders o LEFT JOIN delivery_boy_cash_movements m ON m.order_id=o.id AND m.type='order_collect' WHERE o.payment_method=4 GROUP BY o.status`
  → status=13 (DELIVERED, vérifié OrderStatus.php:12) : 15 ordres COD livrés,
  **15 sans mouvement order_collect** (100%). Cash physiquement encaissé par
  le livreur sans aucune trace dans une session cash livreur.
- Sessions livreur peu adoptées (1 open + 8 reconciled ; 13 mouvements
  order_collect totaux référencent d'autres ordres). Le revenu reste compté
  au Z (fiscal_sequence alloué) — gap = contrôle interne cash par-livreur,
  pas NF525.

**Lentille** : contrôle interne cash livreur / réconciliation incomplète
(non-fiscal).

**Reco** : aucune correction code stricte requise pour V1 LOCAL (mono-poste,
flux delivery mineur = 35 ordres source delivery). Comportement best-effort
assumé + cross-check ZReportCashEnrichment. Si owner veut une vraie
responsabilisation livreur : exiger une session OUVERTE avant de passer un
COD en DELIVERED (strict=true sur ce hook) — décision owner (durcit le flux
de livraison). P3.

**Frozen touch** : non.

---

## Notes (non-findings, vérifiés)
- LOYALTY clawback partiel : `ClawbackLoyaltyPointsOnRefund` reprend le
  montant gagné COMPLET sur refund partiel (docstring l.30-35) — défavorable
  client, jamais défavorable resto, documenté V1.0.2 backlog. Pas de trou
  resto.
- SPLIT refund partiel d'une tranche = hors scope V1 (SplitPaymentService
  docstring l.33). Le refund est full-only (RETURNED négocie l'ordre entier).
- PARKED recall d'un ITEM (simple, sans variation) supprimé/désactivé après
  park : la ligne est conservée (prune ne couvre que les variations) puis
  re-validée au store via PricingService. Pas de repro de trou prouvé → non
  reporté (anti-hallucination).
