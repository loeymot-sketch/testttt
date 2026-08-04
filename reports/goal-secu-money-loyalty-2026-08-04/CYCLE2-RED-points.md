# CYCLE2 — RED adversarial (2ᵉ passe convergence) : CUMUL + UTILISATION des points fidélité

Repo HEAD `ae4b27033` · 2026-08-04 · audit READ-ONLY (aucune modif du code applicatif).
Mission : (A) DISPUTER le heal cycle-1 (lane fantôme PREPARED étendue web/phone) + re-disputer
les 5 correctifs · (B) chasser tout NOUVEAU P0/P1. But : confirmer **P0+P1=0**.

## Preuve exécutable (PHPUnit, driver sqlite `:memory:`)

| Suite | Résultat |
|---|---|
| `CleanupStaleLoyaltyRefundTest` (dont le NOUVEAU `test_phantom_prepared_WEB_order_claws_back_earned_points_on_purge`) | **OK 5/5, 18 assertions** (testdox : les 5 s'exécutent, aucun skip) |
| `CleanupStalePendingKioskOrdersExtendedSentinelTest` + `LoyaltyEarnClawbackAsymmetrySentinelTest` | **OK (10/10 combiné, 36 assertions)** |
| `--filter Loyalty` (régression complète) | **OK 124/124, 450 assertions** |
| `KioskLoyaltyDoubleRedeemRefused` + `OrphanRedeemReaper` + `*Redeem*` (cluster UTILISATION) | **OK 37/37, 142 assertions** |

Diff du heal vérifié : `git show ae4b27033 -- app/Jobs/CleanupStalePendingKioskOrders.php`
= **une seule ligne** `->where('source_surface','kiosk')` → `->whereIn('source_surface',['kiosk','web','phone'])`
sur la lane PREPARED (L113). Le reste de la lane (garde NF525, clawback) est inchangé.

---

## (A) Le heal cycle-1 disputé — lane fantôme PREPARED étendue web/phone

### CORRECTIF-TIENT — `CleanupStalePendingKioskOrders.php:105-121` (+ clawback `:380-405`)

**Q1 — Une commande web PREPARED impayée à points est-elle VRAIMENT purgée + clawbackée ?**
OUI. `test_phantom_prepared_WEB_order...` (`CleanupStaleLoyaltyRefundTest.php:283-313`) crée un
`source_surface='web'`, `order_type=TAKEAWAY`, `status=PREPARED`, `payment_status=PENDING_COUNTER`,
`fiscal_sequence_no=null`, `loyalty_points_awarded=300`, solde client 300 → après `handle()` :
**soft-deleté** (`deleted_at` non-null) + **solde 0** + **1 ligne `manual_deduct`**. Vert.
Non-no-op prouvé : le filtre kiosk-only exclurait `source_surface='web'` (0 ligne matchée), donc
les assertions `points=0` + `deleted_at != null` ÉCHOUERAIENT sans la ligne L113 — le test encode
bien le fix, pas un no-op.

**Q2 — Idempotent ?** OUI, triple :
- clawback : garde d'existence `manual_deduct` (`LoyaltyService.php:165-176`) + index UNIQUE
  `(user_id, order_id, type)` (`2026_03_26_075919`) → 2ᵉ passage NOOP.
- refundPoints : garde `manual_add` (`LoyaltyService.php:89-100`).
- ordre soft-deleté → `whereNull('deleted_at')` (L106) l'exclut au re-run du cron (toutes les 5 min,
  `Kernel.php:136-139`).

**Q3 — La garde NF525 protège-t-elle une commande PAYÉE d'une purge erronée ?** OUI, **double
exclusion**. Filtre requis : `payment_status ∈ {UNPAID, PENDING_COUNTER}` (L109) **ET**
`fiscal_sequence_no IS NULL` (L107), re-checkés sous lock (`softDeleteStalePreparedPhantom` L369-373).
Chemin réel d'une vente carte web payée en ligne (Mollie) = `finalizePaidKioskOrder`
(`FrontendOrderService.php:1369-1372` `isWebCardOrder`) :
`payment_status===PAID` exigé AVANT toute promotion (L1414) **puis** `fiscal_sequence_no` alloué
dans la MÊME transaction (L1437-1443) **avant** `status=ACCEPT` (L1506) → une commande web payée
n'atteint PREPARED qu'avec **PAID + séquence fiscale scellée**. Elle est donc exclue par les DEUX
prédicats. → **impossible de soft-deleter une vente web légitime payée en ligne**. REFUTED l'angle
« l'extension casse la lane payée ».

**Q4 — Résolution du porteur symétrique à l'award ?** OUI. Award (`AwardLoyaltyPointsOnDelivery.php:68-77`),
clawback phantom (`CleanupStalePendingKioskOrders.php:387-396`) et `ClawbackLoyaltyPointsOnRefund.php:62-71`
utilisent le MÊME résolveur : `loyalty_customer_code → User::where('loyalty_code')`, repli `user_id`
seulement si ce user a un `loyalty_code`. → le clawback reprend au MÊME client que l'award a crédité
(pas de vol au voisin, pas d'asymétrie).

**Q5 — Double-clawback entre lanes ?** IMPOSSIBLE. La lane phantom cible `status=PREPARED` (L108) ;
la lane web (L193-215) cible `status ∈ {PENDING, ACCEPT, PREPARING}` — **disjointes par statut**, une
commande n'est jamais traitée par les deux dans un run. Et le clawback est idempotent même si un
`RefundCreated` survenait après (garde `manual_deduct` survit au soft-delete : `loyalty_transactions`
n'est pas supprimé).

---

## (A-bis) Re-dispute des correctifs cycle-1 (prouver qu'ils tiennent)

### CORRECTIF-TIENT — Clawback sans filtre de statut · `LoyaltyService.php:184`
`User::where('id',$userId)` sans `whereIn('status',...)`, miroir exact de l'award (aucun filtre) et
de `refundPointsToOwner` (L59). Balance clampée `max(0, …)` (L194), débit RÉEL enregistré
`-$actualDeducted` (L206). Legacy `status=1` et désactivé `status=10` bien repris. Couvert par
`LoyaltyEarnClawbackAsymmetrySentinelTest` (vert).

### CORRECTIF-TIENT — Garde anti-award [CANCELED, REJECTED, RETURNED] · `AwardLoyaltyPointsOnDelivery.php:36 + :58`
Double défense : (a) garde in-memory L36 sur `$order->status` ; (b) garde SQL **atomique**
`whereNotIn('status',[16,19,22])` couplée à `whereNull('loyalty_points_awarded')` (L57-58) → un event
DELIVERED/PREPARED différé sur une commande passée en terminal touche 0 ligne (`$updated===0` → return
L61). La sentinelle non-nulle bloque tout ré-award. Aucun chemin de refund ne remet
`loyalty_points_awarded` à NULL (grep exhaustif : seuls les writes de l'award existent).

### CORRECTIF-TIENT — Cluster redeem RED-1/2/3/4 (LOCK_FRONTENDORDER_REDEEM_REORDER) · `FrontendOrderService.php:1047-1152`
- **RED-1** skipBalanceGate : `DiscountCalculator::kioskLoyaltyRedemption(..., skipBalanceGate:true)`
  (`DiscountCalculator.php:36,68-72`) ne calcule QUE la valeur ; le débit FRAIS re-teste le solde
  (`FrontendOrderService.php:1128`) → `balanceAfter = points - required ≥ 0`, jamais négatif.
- **RED-2** rattachement TOUTE surface : filtre `source_surface='kiosk'` retiré ; requête bornée
  `user_id` + `loyalty_code` du porteur résolu (L1094-1102) + montant qui DOIT matcher (L1105, sinon
  `ValidationException`).
- **RED-3** IDOR AMONT : garde borne/propriétaire/staff DÉPLACÉE avant le rattachement (L1076-1090) →
  couvre attach ET débit ; un caller non-autorisé lève 422 (aucun point brûlé, aucun rattachement).
- **RED-4** plancher min : refusé au endpoint `LoyaltyController.php:406-408` AVANT tout débit ET
  au chokepoint aval `DiscountCalculator.php:63`. Multiple-de-rate aussi vérifié (L395-398).
Cluster couvert par 37/37 tests verts.

### CORRECTIF-TIENT — Coupon exclut les commandes annulées · `CouponService.php:445-448`
Closure `$liveOrderCoupon` = `whereHas('order', whereNotIn status [16,19,22])`, appliquée aux DEUX
plafonds (`limit_per_user` L457, `max_uses_global` L470) → un paiement carte abandonné (auto-cancel)
ne brûle plus le quota.

---

## (B) Nouveaux angles

### P2 — [NOUVEAU, introduit par le heal · ORDER-LOSS, pas un leak de points] Purge prématurée d'une commande web/phone À L'AVANCE atteignant PREPARED
`CleanupStalePendingKioskOrders.php:115-118` (staleness de la lane phantom)

La lane phantom, désormais appliquée à `web`+`phone`, réutilise la staleness **agressive de la borne** :
`created_at < $staleThreshold OR order_datetime < $staleThreshold` avec le **TTL borne** `$ttlMinutes`
(`kiosk.stale_collect_ttl_minutes`, défaut **180 min / 3 h**, L28). Or les lanes DÉDIÉES web (L199-205)
et téléphone (L158-164) ont été spécifiquement conçues **order_datetime-priority + TTL généreux
(360 min)** précisément parce qu'une commande web/téléphone peut être « à emporter ce soir »
(commentaires L150-157, L182-184). Le heal n'a pas porté cette prudence : une commande web/phone
**pay-at-counter à l'avance** qui atteint PREPARED est éligible à la purge **3 h après création**,
sans regarder son créneau. `softDeleteStalePreparedPhantom` ne dispatche PAS
mail/sms/push (contrairement à `cleanupStaleDeferredOrder`) → **disparition silencieuse**.

**Pourquoi ce n'est PAS un P0/P1 :**
1. **Comptabilité points SYMÉTRIQUE** — la purge fait `refundPoints` (rend le dépensé, L378) **et**
   `clawbackEarnedPoints` (reprend le gagné, L398). Aucun point n'est ni détruit ni volé : c'est une
   perte de COMMANDE (disponibilité/UX), pas un leak money-path.
2. **NF525 intacte** — uniquement `fiscal_sequence_no IS NULL` + non-PAID.
3. **Reachability largement MASQUÉE** : `order_datetime` est posé à l'heure de CRÉATION
   (`FrontendOrderService.php:288`), le créneau réel vit dans `scheduled_at` (colonne séparée,
   `OrderRequest.php:179`, `OrderService.php:1208-1209`). La lane web ACCEPT (L199) purge donc déjà la
   commande à l'avance à ~6 h après création **avant** qu'elle n'atteigne PREPARED (le KDS la retient
   hors board via `KitchenReleaseRule` `scheduled_at` jusqu'à ~20 min du créneau). Fenêtre d'atteinte
   étroite.

**Repro (chemin) :** commande `source_surface='web'`, `pos_payment_method=COUNTER_DEFERRED`,
`payment_status=PENDING_COUNTER`, `scheduled_at` = +5 h, `created_at` = maintenant ; bump cuisine
→ PREPARED ; avancer l'horloge de 3 h → `handle()` → l'ordre est soft-deleté alors que le créneau
n'est pas atteint (points néanmoins équilibrés). **Reco brain** : la lane phantom devrait mirrorer la
staleness order_datetime/scheduled_at-priority + TTL par surface des lanes web/phone (zéro touche
frozen), pour cohérence — mais AUCUNE urgence sécu (points symétriques).

### REFUTED (angles neufs testés, aucun leak)
- **Soft-delete d'une commande web PAYÉE en ligne** : REFUTED — double exclusion PAID (`:1414`) +
  fiscal_sequence_no scellé (`:1437-1443`) avant PREPARED.
- **Clawback sur un award déjà remboursé (double)** : REFUTED — garde `manual_deduct` idempotente
  (`LoyaltyService.php:165-176`) + lanes PREPARED vs PENDING/ACCEPT/PREPARING disjointes.
- **Pré-rachat rattaché à une commande d'une autre session (montant identique)** : REFUTED — les
  points sont DÉJÀ débités au pré-rachat ; le rattachement les consomme une seule fois (le pré-rachat
  passe `order_id=NULL → order_id`, plus jamais re-crédité par le reaper `whereNull('order_id')`
  `LoyaltyService.php:283`). Second ordre même montant → pré-rachat déjà consommé → débit frais légitime.
- **Désync grand-livre / colonne après réordonnance** : REFUTED — débit sous `lockForUpdate`
  (`FrontendOrderService.php:1040`), écriture cohérente `users.loyalty_points` + `balance_after` (L1140-1147).

### P2/P3 — pré-existants (inchangés, hérités cycle-1, non introduits par le heal)
- **P2** Clamp-à-0 : si le client DÉPENSE ailleurs les points fantômes avant le clawback, la reprise
  clampe (`max(0,…)`, `LoyaltyService.php:194`) → fuite partielle en faveur du client. Fenêtre étroite
  (earn PREPARED → spend → abandon < 3 h), déjà présent en borne. Non aggravé par le heal.
- **P2** Refund partiel = clawback TOTAL (`ClawbackLoyaltyPointsOnRefund.php:30-35`) — erre en faveur
  de la MAISON. Déféré V1.0.2.
- **P2** Sentinelle award `-1` bloquée sur SIGKILL entre pose et finalisation. Faible proba.
- **P3 (coverage)** Le heal ajoute un test WEB mais AUCUN test `phone` dédié pour la lane phantom
  (même code, risque de régression silencieuse non-sentinellée si un futur refactor filtre par surface).
- **P3 (cosmétique)** `softDeleteStalePreparedPhantom` appelle `refundPoints($locked, 'kiosk')`
  (L378) même pour un ordre web/phone → `loyalty_transactions.source_surface='kiosk'` mal-étiqueté
  dans l'audit d'un remboursement web/phone. Aucune conséquence sécu/money.

---

## VERDICT

- **P0 restants = 0.** Clawback sans-filtre-statut, garde award terminale, cluster redeem RED-1/2/3/4,
  coupon-hors-annulées : **TOUS TIENNENT** (preuve exécutable + file:line + diff).
- **P1 restants = 0.** Le P1 cycle-1 (fantôme web/phone PREPARED impayé conservant les points GAGNÉS)
  est **FERMÉ et prouvé** : purge + `refundPoints` + `clawbackEarnedPoints`, idempotent, garde NF525
  double, résolution de porteur symétrique, une vente web payée est double-exclue. Aucun NOUVEAU
  P0/P1 trouvé.
- Reste : **1 P2 nouveau** (purge prématurée order-loss d'un ordre web/phone à l'avance atteignant
  PREPARED — points SYMÉTRIQUES, reachability masquée, reco cohérence non-urgente) + 3 P2 + 2 P3
  pré-existants. **Le domaine CUMUL + UTILISATION des points converge : P0+P1 = 0.**
