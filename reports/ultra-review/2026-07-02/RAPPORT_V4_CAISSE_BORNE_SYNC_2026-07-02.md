# RAPPORT V4 — AUDIT PROFOND A→Z : CAISSE + BORNE + SYNCHRONISATION — 2026-07-02
**Mission** : GOAL_FABLE5 V4 — audit A→Z caisse/borne/synchro, même rigueur que V3 (refute-by-default,
10 angles, verify, re-prove) + **boucle de test-e2e live**. HEAD `61e9ea7b7` + working-tree (heals V1-V3).

## 1. VERDICT — cœur SOLIDE, 1 P1 réel healé, 1 P3 documenté

Workflow refute-by-default 6 cibles profondes (10 agents) : **4 GREEN_HELD, 2 BROKEN → 3 findings**
(1 CONFIRMED P1, 1 DOWNGRADE P3, 1 REFUTED). **CAISSE (commande inline), BORNE (commande Plan B +
encaissement), INTERSECTION A→Z (zéro-doublage)** ont TENU sous attaque. Le trou : un chemin de
statut-paiement qui crée des ventes off-book.

## 2. FINDING CASSÉ → HEALÉ (repro live + test)

| Sév | Finding | Fix | Test |
|---|---|---|---|
| **P1** | **`change-payment-status` crée des ventes PAID OFF-BOOK** : un POS Operator peut flipper une commande DIFFÉRÉE (borne Plan B, PENDING_COUNTER) → PAID via `POST /pos-order/change-payment-status/{id}` ; `OrderService::changePaymentStatus` (l.2428) pose `payment_status=PAID` **sans allouer `fiscal_sequence_no` NI `cash_movement`** → vente hors chaîne NF525 (exclue du Z, `ZReportService` filtre `whereNotNull`), hors trail caisse (invisible réconciliation), jamais rattrapée (`RetryFiscalAllocCommand` ne la voit pas) = **orphelin PERMANENT**. Distinct du heal V2 (autre chemin). **Re-prouvé : 19 commandes PAID fiscal_sequence_no=null en DB, transitions 15→5 et 10→5 légales, rôle POS Operator suffit.** | garde dans `changePaymentStatus` : **PENDING_COUNTER → PAID sans fiscal = 422** (« utilisez l'encaissement »). Zéro allocation depuis ce chemin = **zéro risque de corruption de chaîne** ; le chemin correct reste `confirmCounterPayment` (encaissement). | `PosOffBookPaidGuardTest` 2/2 |

## 3. DÉFÉRÉ / ESCALADÉ OWNER (rationale)
- **P1 — périmètre élargi + nettoyage** : mon garde bloque le vecteur clair (PENDING_COUNTER→PAID). Restent
  à trancher par l'owner (§10 business-critical) : (a) la politique UNPAID→PAID via ce endpoint (chemins
  légitimes client-kiosk-pay / carte-confirm à préserver — allouer le fiscal serait risqué depuis ce chemin) ;
  (b) le **nettoyage des 19 commandes PAID orphelines** existantes (test-DB ? à ré-encaisser ? à annuler ?).
- **P3 — MonitorOutboxStaleness fausse alarme « worker down »** : 37 vieux events `LoyaltyBalanceChanged`
  (juin, tentatives épuisées) restent non-dispatchés (cron retry dormant en local) → épinglent l'alarme RED
  en permanence → **fatigue d'alerte** (une vraie panne worker serait masquée). Le symptôme est réel ; le
  mécanisme « immortel » revendiqué a été RÉFUTÉ par le vérificateur. Fix recommandé (shared-zone bus → LOCK+gate) :
  le monitor doit exclure de l'alarme « down » les events à tentatives épuisées (dead-letter) + purger les 37 vieux.
- **REFUTÉ** : « 20 order.created non-dispatchés 2 semaines = rescue ne converge pas » — le vérificateur a
  réfuté (données stale + cron local absent, pas un bug de logique).

## 4. TENU SOUS ATTAQUE + PROUVÉ e2e LIVE (la boucle)
- **CAISSE inline** (walkin=false) : commandes récentes = PAID inline + fiscal alloué à la création +
  cash_movement (card=0), **absentes de « à encaisser »**. Modèle owner confirmé (data live + `PosDeferred…Test` 3/3).
- **BORNE Plan B** : GREEN_HELD (token machine réel) — PENDING_COUNTER, fiscal à l'encaissement seul.
- **SYNCHRONISATION** : **36 domain_events dispatchés dans la dernière heure** (worker high,default + soketi
  actifs) → temps-réel VIVANT ; dégradation (worker down → poll 5s KDS/OSS, no-data-loss) code-prouvée.
- **INTERSECTION A→Z + ZÉRO-DOUBLAGE** : **0 paire (branche, fiscal_seq) dupliquée** parmi les commandes
  live ; KDS affiche CAISSE **et** BORNE avec le format symbolique correct (`G|TACOS|L|Cordon P|BL`, `S|CAYENNE|STO|ALG`+`MENU`,
  `S|SUPRÊME|MAY`…), chaque commande UNE fois, n° de file distincts, cap 30 (bannière). `v4-kds.png`.
- **NF525 CHAIN OK 4 branches** (avant + après). `kds_station` = mythe reconfirmé (KDS filtre status+payment).

## 5. GATES
- **Suite backend** : [FINAL §6] + `PosOffBookPaidGuardTest` 2/2.
- **Frozen-diff 0** (heal : `OrderService::changePaymentStatus` non-frozen ; `PaymentStateMachine` NON touché ;
  aucun fichier §7). **NF525 CHAIN OK**. **Zéro doublage** (heals V1-V3 non re-faits).

## 6. LEÇON
Le cœur caisse/borne/sync est **robuste** (4/6 cibles GREEN_HELD sous attaque, zéro-doublage prouvé). Le
seul vrai trou = un chemin latéral (`change-payment-status`) qui contourne l'encaissement et crée des ventes
off-book NF525 — invisible aux 3 audits précédents car ils suivaient le chemin NOMINAL (encaissement), pas
le chemin ALTERNATIF. **Refute-by-default sur les chemins alternatifs = ce qui débusque l'off-book.**
