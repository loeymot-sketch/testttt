# CYCLE1 — RED adversarial : CUMUL + UTILISATION des points fidélité

Repo HEAD `0649cb40d` · 2026-08-04 · audit READ-ONLY (aucune modif).
Mission double : (A) disputer les correctifs déployés · (B) chasser tout NOUVEAU P0/P1.

## Preuve exécutable (PHPUnit, driver sqlite local)

| Suite | Résultat |
|---|---|
| `LoyaltyEarnClawbackAsymmetrySentinelTest` (CUMUL P0+P1) | **OK 4/4, 8 assertions** |
| `KioskLoyaltyDoubleRedeemRefusedTest` + `OrphanRedeemReaperTest` + `CleanupStaleLoyaltyRefundTest` (UTILISATION RED-1/2/3/4) | **OK 17/17, 64 assertions** |
| `--filter Loyalty` (régression complète) | **OK 123/123, 447 assertions** |

Diff de la réordonnance vérifié : `git show e08662639` prouve que la garde IDOR
était AVANT le fix positionnée APRÈS le `return` de la branche rattachement, et
que le filtre `source_surface='kiosk'` y a été retiré → la vuln RED-3 était RÉELLE.

---

## (A) Correctifs disputés

### 1. CUMUL P0 — `LoyaltyService::clawbackEarnedPoints` sans filtre de statut
**[CORRECTIF-TIENT]** `app/Services/LoyaltyService.php:159-221`
- Aucun filtre de statut : `User::where('id',$userId)` (L184), miroir exact de
  l'award qui n'en a aucun (`AwardLoyaltyPointsOnDelivery.php:68-77`) et de
  `refundPointsToOwner` (L59). Le porteur legacy `status=1` ET désactivé
  `status=10` se fait bien reprendre ses points.
  Preuve : `test_clawback_removes_points_even_on_non_active_account` (dataProvider 1 & 10) → **balance 300→0**.
- **Négatif impossible** : `$newBalance = max(0, $currentBalance - $amount)` (L194),
  et `points = -$actualDeducted` enregistre le débit RÉEL, pas l'attendu (L195, L206).
- **Double clawback impossible** : garde d'existence `manual_deduct` (L165-176)
  + index UNIQUE `(user_id, order_id, type)` (`database/migrations/2026_03_26_075919`)
  → 2ᵉ event = NOOP idempotent ; en course, le 2ᵉ INSERT lève 23000 dans la
  `DB::transaction` interne, rollback, et le listener `ClawbackLoyaltyPointsOnRefund.php:82-93`
  l'avale (jamais de double décrément, jamais de crash du refund).

### 2. CUMUL P1 — garde anti-award étendue [CANCELED, REJECTED, RETURNED]
**[CORRECTIF-TIENT]** `app/Listeners/AwardLoyaltyPointsOnDelivery.php:31-38` + `:55-59`
- Double défense : (a) garde en mémoire L36 sur `$order->status`, (b) garde SQL
  atomique `whereNotIn('status', [16,19,22])` **couplée** à `whereNull('loyalty_points_awarded')`
  (L58) → un event DELIVERED différé sur une commande passée en terminal
  n'award plus (update touche 0 ligne).
  Preuve : `test_award_never_credits_a_terminal_refunded_order` (REJECTED, RETURNED) → **balance reste 0, sentinelle jamais posée**.
- Vérifié : **aucun chemin de refund ne remet `loyalty_points_awarded` à NULL**
  (seuls les writes de l'award existent, grep exhaustif) → la sentinelle non-nulle
  bloque tout ré-award même si la garde de statut est contournée par un in-memory périmé.

### 3. CUMUL P1-2 — janitor `softDeleteStalePreparedPhantom` clawback des points GAGNÉS
**[CORRECTIF-TIENT — mais surface KIOSK uniquement, voir P1 ci-dessous]**
`app/Jobs/CleanupStalePendingKioskOrders.php:377-402`
- Idempotent triple : clawback idempotent (garde `manual_deduct`) + ordre
  soft-deleté → exclu par `whereNull('deleted_at')` au re-run (L106) + résolution
  du porteur identique à l'award. Pas de double-clawback, pas de collision avec
  `refundPoints` appelé juste avant (L375, ledger `manual_add` ≠ `manual_deduct`).

### 4. UTILISATION — `applyKioskLoyaltyDiscount` réordonné (LOCK_FRONTENDORDER_REDEEM_REORDER)
**[CORRECTIF-TIENT] les 4 RED tiennent** `app/Services/FrontendOrderService.php:1013-1153`
- **RED-1** (skipBalanceGate au rattachement) : `DiscountCalculator::kioskLoyaltyRedemption(..., skipBalanceGate:true)`
  (`DiscountCalculator.php:36,66-71`) ne sert QU'à calculer la VALEUR de remise. Le
  débit FRAIS re-teste le solde (L1128 `if ($loyaltyUser->loyalty_points < $pointsRequired) return`)
  → **jamais de solde négatif** (`balanceAfter = points - required ≥ 0`, L1138).
  Preuve : `test_redeem_entire_usable_balance_still_applies_the_discount` (solde 100→0, remise appliquée, 1 SEUL débit).
- **RED-2** (rattachement TOUTE surface) : le filtre `source_surface='kiosk'` a été
  retiré (L1094-1102). La requête reste bornée à `user_id = $loyaltyUser->id`
  (résolu depuis le code de la requête) + montant qui DOIT matcher (L1105 sinon 422).
  **Impossible de consommer le pré-rachat d'autrui** (scoping user + garde IDOR amont).
- **RED-3** (IDOR avant attach) : garde borne/propriétaire/staff DÉPLACÉE en PREMIER
  (L1076-1090), donc couvre AUSSI le rattachement. Preuve :
  `test_pending_redeem_attach_is_refused_for_a_non_owner_caller` → **422, pré-rachat NON rattaché**.
- **RED-4** (min au /loyalty/redeem) : `LoyaltyController.php:399-408` refuse `< min_redeem`
  AVANT tout débit ; le chokepoint aval applique le même plancher (`DiscountCalculator.php:63`).
  Preuve : `test_redeem_below_min_is_refused_without_debit` → **aucun débit, 0 ledger**.
- **Payer plein tarif avec points partis / double débit** : le débit et la remise
  sont couplés dans la transaction de commande (verrou `lockForUpdate` L1038, dans la
  `DB::transaction` L190). Un rattachement échoué (montant ≠) → throw → rollback commande →
  pas de vente plein tarif. Preuve : `test_pending_redeem_with_different_discount_amount_is_rejected`.

### 5. COUPON — comptage exclut les commandes annulées
**[CORRECTIF-TIENT]** `app/Services/CouponService.php:445-473`
- `$liveOrderCoupon` (`whereHas('order', whereNotIn status [16,19,22])`) appliqué
  aux DEUX plafonds (`limit_per_user` L456, `max_uses_global` L469) → un paiement
  carte abandonné (auto-cancel) ne brûle plus le quota 1-usage.

---

## (B) Nouveaux angles / findings restants

### P1 — [CONFIRMÉ, encore atteignable] Fantôme WEB (et téléphone) PREPARED impayé garde les points GAGNÉS
`app/Jobs/CleanupStalePendingKioskOrders.php:105-118` (lane phantom = **kiosk-only**)
vs `:190-212` (lane web = **exclut PREPARED**) · award `app/Listeners/AwardLoyaltyPointsOnDelivery.php:40,43`

**Scénario / preuve de chemin :**
1. Commande WEB créée UNPAID (`FrontendOrderService.php:290`, `source_surface='web'`,
   `order_type=TAKEAWAY(10)` — cf. commentaire `:610`).
2. Auto-accept → ACCEPT → KDS bump PREPARING → **PREPARED** (la lane web L190-193 cible
   déjà ACCEPT/PREPARING pour des web UNPAID → ces états sont prouvés atteignables ;
   le bump PREPARING→PREPARED est une action cuisine indépendante du paiement).
3. À PREPARED, l'award se déclenche : `$isKiosk` est **vrai pour TAKEAWAY** (L40) →
   `shouldTrigger` sur PREPARED (L43) → **+N points crédités sur une commande jamais payée**
   (le listener ne regarde PAS `payment_status`).
4. Purge : la lane web (L190) ne cible que PENDING/ACCEPT/PREPARING (PREPARED
   volontairement exclu, transition illégale). La lane `softDeleteStalePreparedPhantom`
   qui, elle, clawback (fix P1-2), filtre `source_surface='kiosk'` (L110) → **ne voit jamais le web**.
   Aucun autre job ne purge (Kernel : `CleanupStalePendingKioskOrders` est le seul).
   Aucun clawback : seul `RefundCreated` déclenche `ClawbackLoyaltyPointsOnRefund`, et
   un orphelin jamais annulé/remboursé ne l'émet jamais.
→ **Points cumulés conservés à vie sur une vente inexistante.** Exploit répétable
   « commande web pay-at-counter + laisser préparer + partir » = ferme de points
   (points = remise NF525 = argent). C'est le **Cumul P1-3 déjà connu** : le fix
   kiosk P1-2 (5414dae24) ne l'a PAS étendu au web ni au téléphone.
**Repro test (à écrire)** : commande `source_surface='web'`, `order_type=TAKEAWAY`,
`payment_status=UNPAID`, dispatch `OrderStatusChanged(...→PREPARED)` → asserte points>0 ;
puis run job cleanup avec `created_at` vieilli → asserte l'ordre TOUJOURS présent
(non soft-deleté) ET points TOUJOURS crédités (pas de clawback).

### P2 — [pré-existant, s'auto-guérit] Fenêtre rattachement (10 min) < fenêtre reaper (30 min)
`FrontendOrderService.php:1099` (attach `>= now-10min`) vs `config/loyalty.php:119` (reap 30 min)
- Un client qui pré-rache puis commande entre 10 et 30 min plus tard : pré-rachat trop
  vieux pour le rattachement → débit frais (ou remise refusée si solde épuisé). Le
  pré-rachat orphelin est re-crédité par le reaper à 30 min. **Sur-débit/perte-de-remise
  TEMPORAIRE, jamais permanente.** Pas un leak. Non introduit par la réordonnance.

### P2 — [pré-existant, connu] Sentinelle award `-1` bloquée sur crash dur
`AwardLoyaltyPointsOnDelivery.php:59` — un kill du process ENTRE la pose de `-1` et la
finalisation (les catch L82/92/111/161 ne couvrent que les exceptions, pas un SIGKILL)
laisse `loyalty_points_awarded=-1` → award futur bloqué (`whereNull` faux) ET clawback
sauté (`ClawbackLoyaltyPointsOnRefund.php:51-55` : `awarded ≤ 0`). Cumul perdu à vie.
Faible probabilité, connu (P2-1).

### P2 — [backlog documenté, erre en faveur de la maison] Refund partiel = clawback TOTAL
`ClawbackLoyaltyPointsOnRefund.php:30-35` — un remboursement partiel reprend la TOTALITÉ
des points gagnés. Sur-clawback (le client perd trop), donc **jamais au détriment de la
maison** → problème d'équité client, pas de sécurité money. Déféré V1.0.2.

### REFUTED (angles testés, aucun leak)
- **skipBalanceGate → solde négatif sur le débit frais** : REFUTED — le débit frais
  re-teste le solde (L1128), `balanceAfter ≥ 0`.
- **Rattachement pré-rachat d'AUTRUI (montant identique)** : REFUTED — requête bornée
  `user_id`+`loyalty_code` du porteur résolu, garde IDOR en amont ; cross-user impossible.
  Same-user cross-order = comptabilité symétrique (chaque remise adossée à un débit) ou
  auto-guérie par le reaper.
- **Désync grand-livre / colonne introduite par la réordonnance** : REFUTED — le débit
  frais calcule `balanceAfter` sous verrou de la ligne user (L1038), écrit `users.loyalty_points`
  ET `loyalty_transactions.balance_after` de façon cohérente (L1140-1147), aucun writer concurrent.

### P3 — cosmétique
`FrontendOrderService.php:1086` : la garde IDOR lève `\InvalidArgumentException(...,422)`
(type inhabituel). Le test asserte bien 422 (mapping OK), mais la sécurité tient de toute
façon par le throw+rollback. Purement cosmétique.

---

## VERDICT

- **P0 restants = 0.** Les deux P0 CUMUL (clawback asymétrique de statut ; points
  volés/détruits) et le P0 UTILISATION sont **TENUS** (preuve exécutable + diff).
- **P1 restants = 1** : **fantôme WEB/téléphone PREPARED impayé conserve les points
  gagnés** (Cumul P1-3 connu — le fix kiosk P1-2 est surface-kiosk-only, non étendu au
  web). Money-adjacent, répétable.
- Les **5 correctifs disputés TIENNENT** (CUMUL P0, CUMUL P1, CUMUL P1-2/kiosk,
  UTILISATION RED-1/2/3/4, COUPON). Reste : 3 P2 (2 pré-existants auto-guéris/faible-proba,
  1 backlog erre-safe) + 1 P3 cosmétique.

**Reco brain** : porter la logique `softDeleteStalePreparedPhantom` (soft-delete +
`refundPoints` + `clawbackEarnedPoints`) à une lane `source_surface IN ('web','phone')`
pour fermer le P1 restant — même garde absolue NF525 (`whereNull('fiscal_sequence_no')`
+ `payment_status ∈ {UNPAID, PENDING_COUNTER}`), zéro touche frozen-zone.
