# GOAL SUPERVISEUR — Audit LOGIQUE + TESTS + ROBUSTESSE (2026-07-31)

> Owner /goal : « act comme supervisor audit + créer un plan plein de logique/test/amélioration,
> tourne avec max agents skilled + superpowers + gstack + test-e2e. » Conducteur = skill `brain`.
> Lentille NOUVELLE (distincte de la campagne abus/sécu déjà faite) : **correctness de la LOGIQUE
> métier**, **trous de COUVERTURE DE TESTS**, **ROBUSTESSE** (validation/erreur/race/intégrité).

## Cadre (brain loop + gstack)
Phase 1 squad parallèle read-only (max agents) → Phase 2 verify-before-report (file:line ou rejet) →
Phase 3 test-gate DB-safe (`safe-test.sh`, JAMAIS `php artisan test`) → Phase 4 verdict (continue/heal/
block/escalate) → Phase 5 heal gains SÛRS non-frozen (frozen §7/NF525 = gate owner) → Phase 6 plan next
→ Phase 7 seal + rapport. Anti-fiction : un test vert ≠ sémantique correcte ; un finding sans repro = rejeté.

## Squad (6 auditeurs skilled — LOGIQUE/TEST/ROBUSTESSE, pas abus)
| # | Système | Cible logique | Trous de test | Frozen |
|---|---------|---------------|---------------|--------|
| A1 | **Fiscal NF525** | Z-report close, séquence gap-free, chaîne HMAC, rétention, tamper id=1 | close e2e, seq concurrente, chain break | ZReport/FiscalSequence/AuditLog svc §7 |
| A2 | **Pricing/argent** | arrondi, TVA par taux, menus composites, extras/suppléments math, remise clamp | edge rounding, multi-taux, snapshot | PricingService §7 |
| A3 | **Cycle commande** | OrderStateMachine complétude, régressions statut, refund/cancel, idempotence | transitions invalides, refund partiel | OrderStateMachine §7 |
| A4 | **Stock/dispo** | décrément/restore, quota vs manuel 86, race, intégrité on_hand | restock, annul, collision | — |
| A5 | **Tiroir/réconciliation** | session open/close, variance, symétrie IN/OUT, Z cash enrichment | variance override, multi-tender | — |
| A6 | **Couverture tests + robustesse** | système-wide : chemins critiques non testés, sentinelles manquantes, error-handling/défensif | (le méta-auditeur) | — |

## Critères de convergence (production-grade verdict)
- 0 P0 logique non traité · findings P1/P2 healés (non-frozen) ou gate (frozen) · tests ajoutés pour
  chaque trou critique · frozen diff = 0 · NF525 chaîne OK · verdict GO/HEAL/BLOCK argumenté.

## REGISTRE — CONVERGÉ
| Système | P0/P1 | Action |
|---------|-------|--------|
| A1 Fiscal | **0 P0/P1** (cœur mûr : séquence gap-free, chaîne HMAC, fail-closed, boot guards) | 5 P2 trous de test → backlog ; tamper id=1 = données pas logique |
| A2 Pricing | **1 P1 LOGIQUE** ventilation TVA reçu écran non-nettée | **HEALÉ+DÉPLOYÉ** `02ae29436` + régression ; 2 P2 (test discount>0, item sans tax_id=TVA 0% FROZEN gate) |
| A3 Commande | **1 P1 ROBUSTESSE** matière/destroy sans withTrashed | **HEALÉ+DÉPLOYÉ** ; 4 P2 (PENDING_COUNTER→REFUNDED incohérent, OUT_FOR_DELIVERY cul-de-sac FROZEN gate, self-cancel mort) |
| A4 Stock | **0 P0/P1** | 3 P2 (rollover quota borne/web, quota ignore composants menu, recordManualOutflow delta=0) → backlog |
| A5 Tiroir | **0 P0/P1** ; **résout le test rouge pré-existant** | test `RefundCashNoWalletCredit` **RÉPARÉ+VERT** (semé le IN) ; P2 asymétrie garde cash-trail → **ESCALADE owner** |
| A6 Méta | trous de test money/fiscal + robustesse | idem A5 (swallow refund) → escalade ; couponDiscount clamp aval, payment() hors tx → backlog |

## 🏁 VERDICT SUPERVISEUR (production-grade)
**HEAL — 3 défauts logique réels corrigés+déployés+testés** (ventilation TVA écran, matière/destroy, test rouge). **Cœur money/fiscal/stock/commande = SAIN** (0 P0/P1 exploitable sur 5 systèmes ; les invariants durs — séquence, chaîne, idempotence, locks, symétrie — tiennent). Frozen §7 = 0 touché · NF525 chaîne OK · phpunit 31/31.

**GATE OWNER (décisions, pas du travail inachevé)** :
1. **Asymétrie garde cash-trail** `hasRecordedCashIn` (pré-Z vs post-Z) — A5 démontre qu'elle peut produire l'INVERSE de son intention (variance fantôme si le cash a été fondu dans l'opening du mono-tiroir). Heal délibéré `662a846bc` → **owner tranche** : retirer la garde OU l'appliquer symétriquement.
2. **Frozen §7** : OrderStateMachine (OUT_FOR_DELIVERY cul-de-sac), ZReportService (prédécesseur Z par 2 clés), PricingService (item sans tax_id = TVA 0% silencieuse) → LOCK + gate.

## PASSE BACKLOG (« continue jusqu'à la fin ») — HEALÉ+DÉPLOYÉ `9f6c8593d`
- **A4-P2 recordManualOutflow sur stock 0** : delta=0 écrivait un StockMovement bruit + consommait la clé
  idempotence + flag `stock_decremented=true` trompeur → `return false` si delta==0 (flag exact, clé
  préservée) + régression test. phpunit 7/7.
- **A6-P2 couponDiscount INVALIDÉ** (anti-fiction) : `CouponService::calculateDiscountAmount` clampe déjà.
- **Différés (medium-risk, à froid)** : PENDING_COUNTER→REFUNDED incohérent (state-machine), rollover quota
  par lecture borne/web (hot-path), payment() hors-tx (cartes dormantes V1). Non-frozen mais méritent une
  passe dédiée (anti-régression). Gates owner inchangés (cash-trail, frozen §7).

## PLAN NEXT (backlog test/robustesse, non-bloquant)
- **Tests à ajouter** (couverture invariants durs) : FiscalSequence concurrence, delivery-COD seal fail-closed, variance seuil-exact, recordMovement négatif, recordManualOutflow delta=0/idempotence, verifyChain sequence_gap, z_reports sentinel.
- **P2 logique non-frozen** : PENDING_COUNTER→REFUNDED (dispatcher OrderCanceled+clawback OU retirer l'arête), rollover quota déclenché par lecture menu borne/web, couponDiscount clamp à la source, payment() dans DB::transaction.
