# AUDIT B — LOGIQUE MÉTIER du cycle de commande (round 1)

- **HEAD** : `a13e1e65672c9214a515fa6fd3a7e48a5abc4e4e` · 2026-08-06 · lecture seule + tests sqlite `:memory:`
- **Preuves exécutées** : `tests/Feature/Pos/ZzAuditBLogiqueRound1Test.php` (4/4 verts = 4 défauts REPRODUITS ; fichier NON committé, à supprimer après heal)

## Séquences jouées (15)

| # | Séquence | Verdict |
|---|----------|---------|
| S1 | Téléphone créée → annulée caisse → encaissable ? | **SAIN** — TERMINAL-COLLECT-GUARD `PaymentService.php:383-387` (422) + file exclut terminaux `routes/api.php:876` |
| S2 | Split qui échoue à la 2e tranche | **SAIN** — tranches persistées DANS la tx du flip PAID (`PaymentService.php:477-479`, `OrderService.php:1363-1366`) ; `validateBreakdown` jette → rollback total, zéro demi-état |
| S3 | Web carte payée pendant que le janitor l'expire (race 60 min) | **SAIN** — janitor re-garde sous lock (`CleanupStalePendingKioskOrders.php:322-327`) ; webhook `paid` tardif sur terminale → **auto-refund** (`Mollie.php:516-525`) ; REFUNDED jamais re-payée (`Mollie.php:497-505`) |
| S4 | Refund après split multi-tranches → ventilation | **DÉFAUT D1 (P1)** ci-dessous |
| S5 | Programmée/advance passée le lendemain → fenêtres board | **SAIN** — plancher advance 48 h (`KitchenDisplaySystemOrderService.php:149,180`), grâce scheduled 2 h (`KitchenReleaseRule.php:193-199`), straddle minuit fenêtre glissante 8 h (l.142) |
| S6 | Bump KDS pendant encaissement simultané | **SAIN** — les 2 chemins `lockForUpdate` + verrou optimiste `expected_status` → 409 (`KitchenDisplaySystemOrderService.php:553-566`) |
| S7 | DELIVERY web abandonnée → stock | **SAIN côté stock** (lanes janitor étendues web+delivery `CleanupStalePendingKioskOrders.php:115,252`) **mais → DÉFAUT D3** (invisibilité amont) |
| S8 | Double-accept web par 2 caissiers | **SAIN** — `changeStatus` lock + retour idempotent statut égal (`OrderService.php:2366-2374`) |
| S9 | Parked order reprise après changement de prix | **SAIN** — reprix SSOT serveur (`posOrderStore`) + sceau devis 409 au drift (`Order/OrderQuoteService.php:122-123`) |
| S10 | Points fidélité sur commande annulée jamais payée (chemin interactif) | **DÉFAUT D2 (P1)** ci-dessous |
| S11 | Mixte cash-dominant à l'encaissement → tiroir | **DÉFAUT D4 (P1)** ci-dessous |
| S12 | Double-encaissement 2 caissiers même commande | **SAIN** — PAID re-lu sous lock : même caissier → no-op 200, autre → 409 typé (`PaymentService.php:337-370`) |
| S13 | « Marquer payé » direct sur une différée | **SAIN** — 422 bloqué, seul l'encaissement scelle (`OrderService.php:2757-2761`) ; UNPAID→PAID hors différé alloue bien la séquence (l.2781-2793) |
| S14 | Résurrection terminale (CANCELED→ACCEPT en course) | **SAIN** — garde pré-lock + re-check in-lock (`OrderService.php:2233,2390`) |
| S15 | Encaissement d'une carte web UNPAID par la caisse | **SAIN** — R1 exclu de la file (`routes/api.php:946-949`) + garde centralisée web/delivery (`OrderService.php:2248-2253`) |

## Défauts confirmés (preuve exécutée)

### D1 — [P1] Refund d'un paiement SPLIT : compensation dérivée du mode DOMINANT, pas des tranches
`OrderService::changeStatus:2444-2449` dérive le gateway de refund du seul `pos_payment_method` (mode dominant). `PaymentService::cashBack` crédite alors le wallet du **TOTAL** (`PaymentService.php:154-158`) ET sort du tiroir la **portion cash** (`l.197-218`).
**Repro exécutée** (`test_d1`) : commande 20,01 € payée 12 carte + 8,01 cash, dominant CARD → RETURN → wallet **+20,01** ET tiroir OUT **8,01** = **28,02 € rendus pour 20,01 € payés** (la tranche espèces est remboursée deux fois). Sens inverse (dominant CASH) : `refundIssuedInCash=true` → wallet sauté → la tranche **carte** n'est compensée nulle part (sous-remboursement). Le fix MP-01/MP-03 a corrigé le tiroir par-tranche mais jamais le **wallet** par-tranche : il devrait créditer `total − portionCash` (cas 'credit').

### D2 — [P1] `cancelCounterPayment` ne reprend JAMAIS les points GAGNÉS (3e jumeau oublié)
Annulation interactive au comptoir d'une borne Plan-B déjà **PREPARED** (cas explicitement prévu, WD1-03 `PaymentService.php:842-851`) : l'award a eu lieu au PREPARED (`AwardLoyaltyPointsOnDelivery:43`). Le chemin appelle `refundPoints` (points dépensés, `l.869-872`) mais **pas** `clawbackEarnedPoints`, et ne dispatche que `OrderCanceled` + `OrderStatusChanged` — aucun listener de ces events ne claw-back (`EventServiceProvider:202-215` ; le clawback n'écoute que `RefundCreated`). Les 2 jumeaux ont été corrigés (`OrderService.php:2486-2506` P1 2026-07-16 ; `CleanupStalePendingKioskOrders.php:439-459` P1-2 2026-08-04), ce 3e chemin non.
**Repro exécutée** (`test_d2`) : PREPARED + 50 pts awardés → cancel comptoir → **50 pts conservés** sur une vente jamais payée (exploit « QR + faire préparer + faire annuler au comptoir » — même famille que P1-2 CUMUL, via le chemin INTERACTIF).

### D3 — [P1 latent, P0 à l'activation livraison] DELIVERY web PENDING invisible dans les DEUX files d'action caisse → annulée par le janitor = perte de commande silencieuse
`FrontendOrder::creating` force `source_surface='delivery'` (`FrontendOrder.php:30-33`). Or la file caisse « web à traiter » filtre `'web'` seul (`routes/api.php:939`) et le tracker exige `surface==='web'` (`PosOrdersTrackerComponent.vue:1475-1479` ; le PENDING delivery devient « phantom pending » exclu du bucketing, l.964). Personne ne peut l'accepter depuis la caisse ; le janitor — correctement étendu à 'delivery' (PROCUREUR F-2) — l'**annule** après TTL. L'équivalence web≡delivery a été propagée aux gardes/purges mais PAS aux 2 lanes de **visibilité** (motif récurrent « surface jumelle »).
**Repro exécutée** (`test_d3`) : delivery PENDING absente de `web-orders/pending` ET de `counter-collect/pending`.

### D4 — [P1] Mixte CASH-DOMINANT à l'encaissement : tiroir doublement crédité (tranche + TOTAL)
Le modal envoie `mode` = mode **dominant** (`PosCounterCollectModal.vue:620-639`) : « 12 € carte, le reste espèces » sur 25,01 € → `mode=CASH` + breakdown. `SplitPaymentService` écrit 1 IN par tranche cash dans la tx (`SplitPaymentService.php:318-328`), puis le hook post-commit `if ($mode===CASH) recordCashOrderMovement($order)` (`PaymentService.php:553-555`) écrit un **2e IN = TOTAL**, sans garde d'existence — contrairement au chemin jumeau `changePaymentStatus` qui l'a (`OrderService.php:2923-2939`).
**Repro exécutée** (`test_d4`, `simulation_hardware=false` = config PROD) : IN observés `[13.01, 25.01]` = **38,02 € au tiroir pour 13,01 € d'espèces réelles** → variance de rapprochement garantie à chaque mixte cash-dominant. Variante simulation ON : IN unique de 25,01 (tranches sautées) au lieu de 13,01 — faux aussi. Le test existant `CounterCollectSplitPaymentTest` n'exerce que le dominant **CARD** (fixture qui évite le cas cassant). Fix : ne pas armer le hook post-commit quand `$breakdown !== null` (les tranches portent déjà le cash-trail).

## Observations mineures (non bloquantes)
- **P3** — `cancelCounterPayment` pose `payment_status=REFUNDED` alors qu'aucun argent n'a bougé ; le janitor jumeau documente l'inverse (« REFUNDED would be a lie », `CleanupStalePendingKioskOrders.php:372-376`). Incohérence sémantique entre jumeaux, impact stats seulement.
- **P3** — `tokenCreate` (`OrderService.php:2966-2990`) écrit `token` sans aucune garde de statut/branche sur le chemin non-auth.

## Séquences réfutées notables
Le cœur des courses (S3 janitor↔webhook avec auto-refund, S6, S8, S12) est remarquablement bien verrouillé — chaque écrivain re-lit sous `lockForUpdate` et perd sa course gracieusement. Les 4 défauts restants sont tous de la même famille : **un correctif appliqué au chemin regardé, pas à ses jumeaux** (wallet vs tiroir sur split ; clawback sur 2 chemins sur 3 ; web vs delivery sur les lanes de visibilité ; garde anti-double-IN sur un seul des 2 chemins d'encaissement).
