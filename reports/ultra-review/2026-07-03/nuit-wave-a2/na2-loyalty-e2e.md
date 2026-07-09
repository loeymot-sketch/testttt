# NUIT Wave A2 — Fidélité bout-en-bout (na2-loyalty-e2e)

HEAD 86e3eee22 · DB foodking_e2e · posture refute-by-default · 0 écriture projet/DB.

## Config live confirmée
- `pos.manual_discount_enabled = true` → **redeem ACTIF en V1** (n'est plus dormant).
- `pricing.tax_inclusive_prices = true` (TTC).
- `loyalty_points_per_euro = 1` (earn), `loyalty_points_for_1_euro_discount = 100` (redeem), `loyalty_min_redeem_points = 100`.

## Attaques menées (10 angles)
accrual-correctness · double-accrual (sentinel) · accrual-sur-annulée/remboursée · redeem math (arrondi/multiple/points>solde/discount>subtotal) · QR forge/replay/expiry · concurrence accrual+redeem · intersection earn↔redeem↔refund↔cancel · IDOR résiduel check/register/scan · dégradation · durabilité ledger.

## FINDING P2 — Points fidélité crédités sur commande NON PAYÉE, jamais repris à l'annulation

`app/Listeners/AwardLoyaltyPointsOnDelivery.php:27-56` déclenche l'accrual sur transition
de STATUT (`PREPARED`/`DELIVERED`) **sans aucun contrôle de `payment_status`**. Pour le
modèle owner B (walk-in/borne Plan B → `PENDING_COUNTER`, paiement différé au comptoir),
la cuisine passe la commande à `PREPARED`/`DELIVERED` **avant** l'encaissement → les points
sont crédités alors que la commande n'est pas payée.

Second maillon : à l'annulation (`OrderService.php:2068/2204`, statut `CANCELED`/`REJECTED`)
seule `LoyaltyService::refundPoints()` est appelée — elle **ne reverse que les `redeem`**
(`LoyaltyService.php:27-29`, `type='redeem'`). Le clawback des points *gagnés*
(`clawbackEarnedPoints`) n'est déclenché QUE par `RefundCreated`
(`ClawbackLoyaltyPointsOnRefund.php:76`, seul appelant vérifié). Une annulation de commande
non payée n'émet pas `RefundCreated` → **les points gagnés restent acquis**.

### Repro LIVE (données existantes)
Commande #4489 : `status=13 (DELIVERED)`, `payment_status=15 (PENDING_COUNTER)`,
`order_type=10 (KIOSK)`, `loyalty_points_awarded=15`, `order_payments` = **0 ligne**.
Ledger : `loyalty_transactions.id=10` `type=earn +15` → client `VICT1234` (`balance_after=265`).
→ 15 points crédités pour une commande jamais encaissée.

### Chaîne d'exploit
1. Commande borne Plan B avec code fidélité → `PENDING_COUNTER` (non payée).
2. Cuisine → `PREPARED` → **+points crédités** (aucun gate PAID).
3. Client ne paie pas → caissier `CANCEL` → `refundPoints` ne reverse que les redeem (0 ici).
4. Points gagnés conservés sur une commande jamais payée. Répétable.

Impact V1 LOCAL : faible en €uros (1 pt/€, ~1% et exige le non-paiement) mais **intégrité
de l'économie de points fausse**. Distinct des items déjà healés/documentés (off-book
PENDING_COUNTER→PAID / UNPAID→PAID = fiscal ; ici = accrual fidélité).

### Fix proposé (auto-réparable, non-frozen)
Gater l'accrual sur `payment_status === PaymentStatus::PAID` dans le listener, ET re-déclencher
l'award à l'encaissement comptoir (émettre `OrderStatusChanged`/hook dédié quand
`PENDING_COUNTER → PAID`). Alternative complémentaire : cloner le clawback des points gagnés
sur `CANCELED/REJECTED` pour toute commande `loyalty_points_awarded > 0` (miroir de
`clawbackEarnedPoints`, idempotent via UNIQUE(user,order,type)).

## FINDING P3 — Points *redeemés* non restitués sur REFUND (asymétrie cancel vs refund)

`refundPoints` (reverse redeem) n'est câblée que sur l'annulation (`OrderService` cancel).
La cascade `RefundCreated` (`EventServiceProvider.php:206-220`) ne rappelle PAS `refundPoints` :
elle ne fait que `ClawbackLoyaltyPointsOnRefund` (reprise des points *gagnés*). Donc un
remboursement d'une commande *livrée + payée* qui avait consommé une remise fidélité **ne
rend pas** les points redeemés au client (il perd sa remise ET ses points). Durabilité/équité.
Fix : ajouter un listener `RefundCreated` qui appelle `LoyaltyService::refundPoints($order)`
(idempotent via le NOOP `manual_add` existant).

## FINDING P3 — `loyalty_min_redeem_points` non appliqué (cosmétique only)

`LoyaltyController::config` expose `min_redeem_points=100` mais ni `LoyaltyController::redeem`
ni `PosRedemptionService::applyToOrder` ne le contrôlent (seuls multiple-de-rate + solde +
positif sont vérifiés). Sans effet ici car `rate == min == 100`, mais si l'owner règle
`min > rate`, la contrainte annoncée à l'UI ne serait pas honorée côté serveur.

## Attestations HELD-GREEN (attaques réfutées)
- **QR signer** (`LoyaltyQrSigner`) : HMAC constant-time (`hash_equals`), version pinnée,
  nonce anti-replay via INSERT-then-catch UNIQUE (pas de TOCTOU), exp+leeway, secret
  refusé si dev-sentinel/court en prod. Forge/replay/expiry = SOLIDES.
- **Double-accrual** : sentinel atomique `whereNull('loyalty_points_awarded')` + `!=CANCELED`
  (`AwardLoyaltyPointsOnDelivery.php:52-58`) → exactly-once même concurrent. SOLIDE.
- **Redeem math** : `points % rate !== 0` rejeté, `round(points/rate,2)`, solde verrouillé
  `lockForUpdate`, `discount > subtotal` rejeté, `max(0,...)` clamp, branche TTC/HT correcte.
  Fractional-euro/overdraw = réfutés.
- **IDOR check/register/scan** : healés confirmés (register `wasRecentlyCreated`, check
  kiosk/staff/owner gate, scan legacy-plaintext OFF par défaut). Pas de nouvelle fuite.
- **Concurrence redeem** : `User::...lockForUpdate()` + UNIQUE(user,order,type='redeem')
  → pas de double-redeem sur une même commande. SOLIDE.
- **Kiosk redeem order_id** : `applyKioskLoyaltyDiscount` rattache/écrit `order_id` →
  reversible à l'annulation (le trou order_id=NULL n'affecte que le path legacy pré-commande).

## Verdict
IMPROVABLE — 1 P2 (accrual sur non-payé + pas de reprise à l'annulation, repro LIVE #4489),
2 P3. Le cœur crypto/idempotence/math de la fidélité est SOLIDE ; le défaut est au niveau
de l'**intersection accrual ↔ cycle de paiement/annulation**, pas dans le calcul des points.
