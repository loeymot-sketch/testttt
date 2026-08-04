# CYCLE3 — RED adversarial (3ᵉ passe, CONFIRMATION de convergence) : CUMUL + UTILISATION des points fidélité

Repo HEAD `2ce5fc113` · 2026-08-04 · audit READ-ONLY (aucune modif du code applicatif).
Passe INDÉPENDANTE : re-dispute des 5 correctifs + du heal cycle-2, sans faire confiance aux cycles 1/2.
But : valider **2 passes consécutives P0+P1=0** (cycle-2 = 0/0 ; ce cycle doit confirmer).

Le seul commit depuis le HEAD cycle-2 (`ae4b27033`) est `2ce5fc113` (DLQ↔refund, `Mollie.php` + test) —
**zéro ligne de code fidélité touchée** (`git show --stat` vérifié). Interaction analysée en (B/F).

## Preuve exécutable (PHPUnit 9.6.29, driver sqlite `:memory:`, PHP 8.2.30) — relancée ce cycle

| Suite | Résultat |
|---|---|
| `LoyaltyEarnClawbackAsymmetrySentinelTest` (CUMUL P0 clawback sans-filtre-statut) | **OK 4/4, 8 assertions** |
| `CleanupStaleLoyaltyRefundTest` (phantom kiosk **+ WEB** purge+clawback, idempotent) | **OK 5/5, 18 assertions** |
| `KioskLoyaltyDoubleRedeemRefusedTest` (cluster redeem RED) | **OK 9/9, 35 assertions** |
| `OrphanRedeemReaperTest` | **OK 4/4, 14 assertions** |
| `LoyaltyClawbackOnRefundSentinelTest` | **OK 5/5, 19 assertions** |
| `LoyaltyRefundOwnerAndStatusSentinelTest` | **OK 3/3, 6 assertions** |
| `LoyaltyRefundPointsIdempotentTest` | **OK 3/3, 10 assertions** |
| `KioskRedeemWholePointSnapSentinelTest` | **OK 3/3, 8 assertions** |
| `CouponNotBurnedByCanceledOrderTest` + `CouponMaxUsesGlobalEnforcementTest` (fix #5) | **OK 10/10, 13 assertions** |
| `--filter Loyalty` (régression complète du domaine) | **OK 124/124, 450 assertions** |

Non-no-op du heal cycle-2 re-confirmé par lecture : `CleanupStalePendingKioskOrders.php:113`
= `->whereIn('source_surface', ['kiosk', 'web', 'phone'])`. Le test WEB
(`CleanupStaleLoyaltyRefundTest.php:283-312`) crée `source_surface='web'` ; un filtre kiosk-only
ne matcherait pas → `deleted_at` resterait null → l'assertion l.310 échouerait. Le test ENCODE le fix.

---

## (A) Re-dispute des 5 correctifs (prouver chacun OU le casser)

### 1. `LoyaltyService::clawbackEarnedPoints` sans filtre de statut
**[CORRECTIF-TIENT]** `app/Services/LoyaltyService.php:159-221`
- Aucun filtre de statut : `User::where('id', $userId)` (L184), miroir EXACT de l'award (aucun filtre,
  `AwardLoyaltyPointsOnDelivery.php:70-74`) et de `refundPointsToOwner` (L59). Legacy `status=1` ET
  désactivé `status=10` bien repris. Preuve : `LoyaltyEarnClawbackAsymmetrySentinelTest` (dataProvider
  statuts 1 & 10) → balance 300→0.
- **Solde négatif impossible** : `$newBalance = max(0, $currentBalance - $amount)` (L194) ; le ledger
  enregistre le débit RÉEL `-$actualDeducted` (L206), pas l'attendu.
- **Double clawback impossible** : garde d'existence `manual_deduct` (L165-176) + index UNIQUE
  `(user_id, order_id, type)` (migration `2026_03_26_075919`). 2ᵉ event = NOOP idempotent ; en course, le
  2ᵉ INSERT lève 23000 dans la `DB::transaction` interne, avalé par le listener
  (`ClawbackLoyaltyPointsOnRefund.php:82-93`).

### 2. Garde anti-award `[CANCELED, REJECTED, RETURNED]` (mémoire + SQL atomique)
**[CORRECTIF-TIENT]** `app/Listeners/AwardLoyaltyPointsOnDelivery.php:36` + `:57-58`
- Double défense : (a) garde in-memory L36 sur `$currentStatus` ; (b) garde SQL atomique
  `whereNotIn('status', [16,19,22])` **couplée** à `whereNull('loyalty_points_awarded')` (L57-58) → un
  event DELIVERED/PREPARED différé arrivant après passage terminal touche 0 ligne (`$updated===0` → return).
- **Vérif indépendante clé** (grep exhaustif des 7 writes de `loyalty_points_awarded`, TOUS dans ce seul
  fichier) : L59 pose `-1` (claim atomique) ; L82/92/111/161 remettent `null` **uniquement sur les branches
  d'échec de CE MÊME award** (pas de user, rate≤0, points≤0, exception) ; L144 finalise à `$pointsToAward`.
  **AUCUN chemin de refund / clawback / cancel / purge ne remet la sentinelle à null.** Donc une commande
  ayant déjà crédité reste non-null à vie → le `whereNull` bloque DÉFINITIVEMENT tout ré-award (y compris
  après remboursement ou résurrection DLQ — cf. F).

### 3. `CleanupStalePendingKioskOrders` lane fantôme PREPARED web/phone : purge + refund + clawback, garde NF525
**[CORRECTIF-TIENT]** `app/Jobs/CleanupStalePendingKioskOrders.php:105-121` (lane) + `:356-439` (worker)
- Cible : `status=PREPARED` (L108), `payment_status ∈ {UNPAID, PENDING_COUNTER}` (L109),
  `fiscal_sequence_no IS NULL` (L107), `source_surface ∈ {kiosk, web, phone}` (L113),
  `order_type ∈ {KIOSK, TAKEAWAY}` (L114). Worker : re-garde ABSOLUE sous lock (L369-372, fiscal_null +
  payment_status + status) → `refundPoints` (rend le DÉPENSÉ, L378) **puis** `clawbackEarnedPoints`
  (reprend le GAGNÉ si `loyalty_points_awarded>0`, L385-405) → casse marqueur counter-deferred → soft-delete.
- **Garde NF525 tenue** : une vente web payée-en-ligne atteint PREPARED **seulement** avec `PAID` +
  séquence fiscale scellée dans la MÊME transaction (`FrontendOrderService::finalizePaidKioskOrder` :
  PAID exigé L1414 → `fiscal_sequence_no` alloué L1437-1443 → `status=ACCEPT` L1506). Elle est donc
  **doublement exclue** (payment_status ≠ UNPAID/PENDING_COUNTER ET fiscal_sequence_no ≠ null). Impossible
  de soft-deleter une vente légitime payée.
- **Résolveur de porteur symétrique** à l'award (L388-396 = `loyalty_customer_code → loyalty_code`, repli
  `user_id` si loyalty_code) → clawback repris au MÊME client crédité.
- **Idempotent** : soft-delete → `whereNull('deleted_at')` (L106) exclut au re-run (cron `everyFiveMinutes`,
  `Kernel.php:136`) ; refund (`manual_add`) et clawback (`manual_deduct`) idempotents.
- **Disjonction inter-lane** : lane fantôme = PREPARED ; lanes deferred (kiosk L70 / phone L146 / web L196)
  = {PENDING, ACCEPT, PREPARING}. Statuts disjoints → jamais traité 2× dans un run. Les lanes deferred ne
  font QUE `refundPoints` (pas de clawback) — correct : PENDING/ACCEPT/PREPARING < PREPARED, donc jamais
  awardé (award ne fire qu'à PREPARED/DELIVERED). Confirmé par le state machine (voir angle H).

### 4. Cluster redeem `applyKioskLoyaltyDiscount` (LOCK_FRONTENDORDER_REDEEM_REORDER)
**[CORRECTIF-TIENT]** `app/Services/FrontendOrderService.php:1013-1153` + `DiscountCalculator.php:36-74`
- **Ordre correct** : (1) lock user L1038-1041 ; (2) valeur remise `skipBalanceGate:true` L1051-1058 ;
  (3) **garde IDOR EN PREMIER** L1076-1090 ; (4) rattachement L1094-1125 ; (5) débit frais avec check solde L1128.
- **skipBalanceGate ⇒ jamais négatif (RED-1)** : `skipBalanceGate` ne sert QU'à calculer la valeur
  (`DiscountCalculator.php:66-71` — la barrière de solde ne s'applique qu'au débit frais). Le débit FRAIS
  re-teste `loyalty_points < pointsRequired` (L1128) → `balanceAfter = points - required ≥ 0`. Le
  rattachement ne touche PAS le solde (points déjà partis).
- **IDOR couvre attach ET débit (RED-3)** : garde `isKioskCaller || isOwnerCaller || isStaffCaller`
  DÉPLACÉE avant le rattachement (L1076-1090). `isKioskCaller` exige une VRAIE `KioskMachine`
  (L1077, un invité porteur de `kiosk:order` n'en a pas). Sinon throw 422 → aucun point brûlé, aucun
  rattachement. Preuve : `KioskLoyaltyDoubleRedeemRefusedTest` (9/9).
- **Rattachement TOUTE surface, borné user+montant (RED-2)** : requête pending-redeem sans filtre
  `source_surface`, scopée `user_id` + `loyalty_code` du porteur RÉSOLU (L1095-1096), `whereNull('order_id')`
  + `lockForUpdate` (L1098-1100), montant qui DOIT matcher `abs(points)===pointsRequired` sinon throw (L1105).
- **Min au /loyalty/redeem (RED-4)** : `LoyaltyController.php:402-408` refuse `< min_redeem` AVANT tout
  débit ; chokepoint aval identique (`DiscountCalculator.php:63` `pointsRequired < minRedeemPoints → 0`).

### 5. Coupon exclut les commandes annulées
**[CORRECTIF-TIENT]** `app/Services/CouponService.php:445-473`
- `$liveOrderCoupon` = `whereHas('order', whereNotIn status [16,19,22])` (L445-449), appliqué aux DEUX
  plafonds : `limit_per_user` (L456) ET `max_uses_global` (L469). Un paiement carte abandonné (auto-cancel)
  ne brûle plus le quota. Preuve : `CouponNotBurnedByCanceledOrderTest` + `CouponMaxUsesGlobalEnforcementTest`
  (10/10).

---

## (B) Nouveaux angles — chasse P0/P1 résiduel

Tous **REFUTED** (repro/chemin ci-dessous), sauf 1 P2 latent NEUF (config-gated).

- **Payer plein tarif avec points partis ?** REFUTED (permanent) — débit + remise couplés dans la
  transaction de création (`FrontendOrderService`, débit L1140 → remise L1149, ou attach L1112→discount L1116).
  Rollback de la commande annule les deux (l'attach `order_id` est dans la même TX → re-null au rollback).
  Un pré-rachat orphelin (commande jamais passée / sans loyalty_code) est **temporairement** échoué mais
  re-crédité par le reaper (P2 connu, auto-guéri).
- **Double débit ?** REFUTED — l'attach `return` (L1124) AVANT le débit frais ; un seul chemin s'exécute.
  Ledger frais gardé par UNIQUE `(user_id, order_id, type)` (`createKioskLoyaltyRedeemLedger` L1171-1188).
  `applyKioskLoyaltyDiscount` appelé 1× par création (L525).
- **Consommer le pré-rachat d'autrui ?** REFUTED — requête pending scopée `user_id` + `loyalty_code` du
  porteur résolu depuis le code de la requête ; garde IDOR en amont. Cross-user impossible.
- **Pré-rachat rattaché à la mauvaise commande (montant identique, 2 sessions) ?** REFUTED — le
  `lockForUpdate` sur la ligne USER (L1038-1041) **sérialise** deux créations concurrentes du même client ;
  la 1ʳᵉ consomme le pending (`whereNull('order_id')` + lock L1098-1100, `order_id` posé), la 2ᵉ ré-évalue
  `whereNull('order_id')` → pending consommé exclu → tombe au débit frais (solde re-testé). Deux pending de
  même montant → chaque commande en consomme un distinct (`latest('id')`), chaque remise adossée à un débit
  réel. Montant ≠ → throw (L1105). Aucun double-consume, aucun mis-attach.
- **Solde négatif ?** REFUTED — débit frais check solde (L1128) ; clawback clampe `max(0,…)` (L194) ;
  attach & refund ne descendent jamais le solde.
- **Award sur commande remboursée ?** REFUTED — garde terminale (mémoire L36 + SQL atomique L58) +
  `whereNull('loyalty_points_awarded')` (L57). Après refund, la sentinelle reste non-null (clawback ne la
  reset pas) → ré-award bloqué à vie (cf. A.2).
- **Clawback double ?** REFUTED — garde existence `manual_deduct` (L165-176) + UNIQUE index. Les 3
  déclencheurs (`ClawbackLoyaltyPointsOnRefund:76`, `OrderService:2492` cancel POS, `Cleanup:398` purge)
  convergent tous sur le même `clawbackEarnedPoints` idempotent.
- **Désync ledger/colonne ?** REFUTED — toute mutation de solde (award L120, redeem debit L1140, POS
  redeem L182, clawback L197, refund L104, reaper L330) écrit sous verrou et pose `balance_after` cohérent
  dans la même TX.
- **(F) Résurrection DLQ (commit `2ce5fc113`) × ré-award ?** REFUTED — même si une commande REFUNDED
  ressuscitait en PAID (le bug que ce commit ferme), le ré-award serait bloqué par la sentinelle non-null
  (A.2) et le clawback aurait déjà eu lieu. Le commit protège le côté fiscal/cuisine ; la fidélité était
  déjà défendue. Aucune interaction négative sur les points.
- **(H) Stranding des points gagnés par transition arrière ?** REFUTED — `OrderStateMachine.php:65-71` :
  PREPARED n'autorise QUE OUT_FOR_DELIVERY / DELIVERED (ou RETURNED avec permission `pos-refund`). Pas de
  PREPARED→PREPARING ni PREPARED→CANCELED. Un ordre awardé (PREPARED) ne peut donc pas glisser vers un état
  traité par une lane deferred sans clawback : il va soit à DELIVERED (reste awardé, légitime), soit RETURNED
  → `RefundCreated` → clawback. Le net clawback est complet.

### P2 — [NOUVEAU ce cycle · latent, config-gated, erre en faveur du CLIENT] Fenêtre reaper configurable SOUS la fenêtre d'attach hardcodée
`app/Services/LoyaltyService.php:242-247` (`orphan_redeem_reap_minutes`, plancher `if (<1) →30`) vs
`app/Services/FrontendOrderService.php:1099` (attach hardcodé `>= now-10min`).

Le reaper ne plancher QUE `< 1` (L245-247) ; il n'impose PAS `≥ 10 min`. Si un opérateur pose
`LOYALTY_ORPHAN_REDEEM_REAP_MINUTES` **< 10**, une fenêtre s'ouvre où un pré-rachat de 6-9 min est à la fois
**reapable** (re-crédité) ET encore **attachable** (`created_at >= now-10min`). Chemin : T0 pré-rachat
(−X, solde débité) → T0+6min cron reaper re-crédite X (solde restauré, ligne redeem laissée `order_id=NULL`
immuable) → T0+7min commande passée → attach consomme la MÊME ligne (`whereNull('order_id')` toujours vrai)
et applique la remise X. **Client obtient les points rendus ET la remise = double bénéfice (la maison paie)**.

**Pourquoi P2 et non P1 :**
1. **SÛR sous config par défaut** (30 min > 10 min) et sous toute valeur ≥ 10. Le défaut
   (`config/loyalty.php:119` = 30) et le commentaire de conception (« window > the 10-min attach window »)
   attestent l'intention correcte.
2. **Erre en faveur du client** (léger sur-crédit), jamais vol au voisin ni destruction de points.
3. **Requiert une mauvaise configuration** d'une valeur que l'owner n'a aucune raison de poser (reaper plus
   agressif que la fenêtre d'attach).
4. Non introduit par aucun des 5 correctifs ni par le heal cycle-2 — couplage latent pré-existant entre deux
   fenêtres réglées indépendamment.

**Reco hardening (non-urgent, zéro frozen)** : clamper le reaper à `max($olderThanMinutes, 11)` (ou aligner
sur `attach_window + marge`) à `LoyaltyService.php:245-247`, + une sentinelle liant les deux constantes.

### P2/P3 — inventaire (hérités, inchangés ce cycle)
- **P2** [cycle-2] Purge prématurée d'un ordre web/phone À L'AVANCE atteignant PREPARED (lane fantôme réutilise
  le TTL borne 180 min au lieu du order_datetime-priority des lanes web/phone). **Order-loss, PAS un leak de
  points** — la purge fait refund+clawback (points SYMÉTRIQUES) ; reachability masquée (KDS retient les
  `scheduled_at` off-board). `Cleanup...:115-118`.
- **P2** [cycle-1] Clamp-à-0 : si le client DÉPENSE les points fantômes avant le clawback, la reprise clampe
  (`max(0,…)` L194) → fuite partielle en faveur du client. Fenêtre étroite (earn PREPARED → spend → abandon
  < 3 h). `LoyaltyService.php:194`.
- **P2** [cycle-1] Sentinelle award `-1` bloquée sur SIGKILL entre pose (L59) et finalisation/revert. Faible proba.
- **P2** [cycle-1] Fenêtre attach (10 min) < reaper (30 min) : sur-débit/perte-de-remise TEMPORAIRE entre 10 et
  30 min, jamais permanent (reaper re-crédite). Auto-guéri.
- **P3** [cycle-1] Refund PARTIEL = clawback TOTAL (`ClawbackLoyaltyPointsOnRefund.php:30-35`) — erre en faveur
  de la MAISON. Déféré V1.0.2.
- **P3** [cycle-2] `softDeleteStalePreparedPhantom` appelle `refundPoints($locked, 'kiosk')` (L378) même pour
  un ordre web/phone → `loyalty_transactions.source_surface='kiosk'` mal-étiqueté. Cosmétique audit.
- **P3** [cycle-2] Lane fantôme couvre `phone` (L113) sans test `phone` DÉDIÉ (même code que WEB, prouvé) —
  risque de régression silencieuse non-sentinellée. Coverage, pas un défaut runtime.
- **Hors-domaine (noté, pas un leak de points)** : un ordre web `order_type=DELIVERY` bloqué PREPARED impayé
  n'est purgé par AUCUNE lane (fantôme exige KIOSK/TAKEAWAY ; lane web exclut PREPARED). Mais DELIVERY n'award
  qu'à DELIVERED → **aucun point fantôme** ; seul le stock est strandé (hygiène commande/stock, hors CUMUL/
  UTILISATION fidélité).

---

## VERDICT

- **P0 restants = 0.** Clawback sans-filtre-statut, garde award terminale (mémoire + SQL atomique +
  sentinelle write-locked au seul award), cluster redeem RED-1/2/3/4, coupon-hors-annulées : **TOUS
  TIENNENT** (preuve exécutable + file:line + relecture indépendante).
- **P1 restants = 0.** Le P1 cycle-1 (fantôme web/phone PREPARED impayé conservant les points GAGNÉS) est
  **FERMÉ et re-prouvé indépendamment** : purge + refundPoints + clawbackEarnedPoints, garde NF525 double,
  résolveur symétrique, idempotent, vente payée double-exclue. **Aucun NOUVEAU P0/P1 trouvé** ; tous les
  nouveaux angles (double débit, pré-rachat d'autrui, mauvaise commande 2 sessions, solde négatif, award
  sur remboursée, clawback double, désync, résurrection DLQ, transition arrière) sont **REFUTED**.
- Reste : **1 P2 NEUF** (reaper configurable sous la fenêtre d'attach — sûr par défaut, erre-client,
  reco hardening) + 4 P2 + 3 P3 hérités (auto-guéris / faible-proba / cosmétiques / coverage).

**CONVERGENCE CONFIRMÉE : 2 passes consécutives P0+P1 = 0 (cycle-2 = 0/0, cycle-3 = 0/0).** Le domaine
CUMUL + UTILISATION des points fidélité est stable. Aucune action bloquante ; le P2 reaper-window est un
durcissement de cohérence non-urgent, zéro touche frozen-zone.
