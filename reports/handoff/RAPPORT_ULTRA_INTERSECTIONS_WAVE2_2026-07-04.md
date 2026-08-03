# ULTRA A→Z — Wave 2 : FONCTIONS PARTAGÉES CROSS-SURFACE (SSOT) — 2026-07-04
**Goal** (suite Wave 1) : abuser les 8 fonctions communes reliant plusieurs systèmes, adversaire +
raisonnement + refute-by-default, jusqu'à validation absolue. Workflow 21 agents (8 find + verify + critic),
0 erreur, 2.08M tokens. HEAD après heals : `41308a72e`. Discipline : PHP/API/logique only (0 `.vue` KDS/caisse).

## 1. RÉSULTAT — 9 confirmés (après downgrade adversaire), 4 réfutés. **3 HEALÉS (dont 1 P1 NF525), 6 documentés.**
La même classe de bug que Wave 1 (invariant appliqué sur un chemin, oublié sur l'autre) — et cette fois
elle a livré un **vrai P1 : une vente off-book NF525**, trouvé exactement en « abusant » l'intersection fiscal-allocate.

## 2. HEALÉS (TDD, frozen 0, NF525 OK, commités)
| Commit | Sév | Intersection | Le bug + le fix |
|---|---|---|---|
| `5048972b7` | **P1** | **fiscal-allocate** | **VENTE OFF-BOOK NF525.** `OrderService::changePaymentStatus` scellait `UNPAID→PAID` (« marquer payé » livraison/en-ligne/table) **sans allouer `fiscal_sequence_no`** — le garde off-book ne couvrait QUE `PENDING_COUNTER→PAID`. Commande PAID sans numéro fiscal = **exclue du Z signé** (`ZReportService whereNotNull`), **jamais rattrapée** (`fiscal_alloc_error_at` reste NULL). **Preuve LIVE : 19 commandes PAID+fiscal NULL en base.** Fix = miroir EXACT de `PaymentService::confirmCounterPayment:335-337` (alloc nested dans la tx sous lock → savepoint, unique `orders_branch_fiscal_seq` = zéro gap/double). Exclusions : PENDING_COUNTER reste bloqué, statut terminal non scellé, Uber non fiscalisé. |
| `41308a72e` | P3 | pricing-calculateOrder | **delivery_charge anti-gonflage POS.** `FrontendOrderService:280` force `delivery_charge=0` hors DELIVERY (payload forgé `TAKEAWAY+delivery_charge=99`) ; le POS ne le faisait pas → total gonflé. `PosOrderRequest::prepareForValidation` force 0 hors DELIVERY + ne calcule le fee distance QUE pour une vraie livraison (fin du fee fantôme). |
| `41308a72e` | P3 | idempotency-middleware | **loyalty/add-points idempotence.** Le jumeau `/redeem` (débit) porte `idempotency` ; `add-points` (crédit) non → retry = double-crédit points + double ledger (UNIQUE inefficace car `order_id=NULL`). Ajout middleware + `required_routes`. |

## 3. DOCUMENTÉS — réels mais dormants / owner-decision / frozen-adjacent (fix fourni, application supervisée)
| Sév | Intersection | Pourquoi documenté (pas auto-healé) | Fix |
|---|---|---|---|
| **P3→owner** | **pricing (livraison offerte ≥30€ POS)** | Waiver ≥30€ appliqué web/borne (`FrontendOrderService:538`) mais PAS caisse (`OrderService:1046`) → même panier livraison GRATUIT sur le site, FACTURÉ 4€ au téléphone. **Décision owner** : la promo ≥30€ est-elle tous-canaux (répliquer le waiver au POS) ou web-only acquisition (documenter l'exclusion) ? Changer un prix caisse sans arbitrage = §10 human gate. | Répliquer `FrontendOrderService:538-542` dans `posOrderStore` OU extraire un `DeliveryWaiverService` partagé, SELON la décision. |
| P3 | **branchscope (admin global)** | `DefaultAccessModelTrait::branch()` lit `DefaultAccess.default_id` AVANT le test `branch_id===0` → BranchScope résout l'admin à **branche 1** alors que caisse/Z font `withoutGlobalScope` (voient TOUT). **Reproduit LIVE** : `col=0 branch()=1 adminSees=2816 total=2820` → 4 commandes (branches 7/8/9) invisibles KDS/historique. **Impact V1 = NUL** (mono-branche, tout=branch 1). V1.0.2 backlog §9, **isolation-critique** (alimente le BranchScope frozen) → ne pas heal à l'aveugle. | Tester `branch_id===0` AVANT le lookup DefaultAccess + boot-guard « staff jamais NULL-branch » ; gate BranchScopeCoverageSentinel. |
| **P2→owner** | **orderstatemachine (FrontendOrder cancel)** | Le cancel client est le SEUL des 4 chemins `changeStatus` **sans `lockForUpdate` ni re-validation** contre le statut verrouillé → lost-update possible (CANCELED écrase un PREPARING committé + remboursement sur commande fiscalement séquencée). **Frozen-adjacent** (NF525, docblock OrderStateMachine → LOCK gate). Reachability V1 basse (Plan B → `finalizePaidKioskOrder` dormant). | Envelopper le corps cancel dans `DB::transaction`+`lockForUpdate`+re-validation, miroir des 3 frères. **Owner LOCK gate requis.** |
| P3 | **composition-snapshot (Uber items-board)** | Merge-key `orderItems()` groupe sur `item_variations/item_extras` bruts au lieu du snapshot → 2 lignes Uber à sauces différentes fusionnent en « x2 » (cartes/OSS/ticket corrects). **Uber dormant** (Production Access EN ATTENTE). | Merge-key snapshot-first + fallback brut, miroir `resolveExtrasForKds`. **Workstream Uber go-live.** |
| P3 | **broadcast (Uber OrderCreated)** | `createFromUber` `save()` sans `OrderCreated::dispatch` → KDS/OSS/caisse aveugles temps-réel (polling seul). **Uber dormant.** NB : `OrderCreated` déclenche aussi stock/FCM/print → **décision de design Uber requise** (source-gater). | Dispatch OrderCreated dans la tx + source-gater les listeners. **Workstream Uber go-live.** |
| P3 | **broadcast (OrderPaymentStatusChanged)** | Event émis mais AUCUN consommateur front (absent de `BROADCAST_MAP`) → le passage PAID qui release au board KDS n'est pas poussé (rafraîchi au prochain event/dégradation WS). Fix préféré = **front (.vue/eventContract.js) = territoire cowork** → ne pas toucher (conflit). | Abonner KDS/OSS/caisse à l'event (cowork/owner). |

## 4. RÉFUTÉS (verify-before-report — 4 faux positifs tués par l'adversaire)
- **coupon limit_per_user cross-surface** : kiosk/POS n'envoient JAMAIS de `coupon_id` (kiosk=`kiosk_promo_code`, POS `coupon_id:null` hardcodé) ; seule surface réelle = web, où clé de check = clé d'écriture (même client auth) → cohérent. NONE.
- **table dine-in idempotency** : asymétrie de code réelle MAIS `pos_dine_in_enabled=false` par défaut → route 404 avant exécution (dormant fail-close). P3 latent, pas P2 live.
- **NF525 cancel audit row** : `$auth=true` = dead-code (aucun caller) ; `recordTransition` couvre le trail dans TOUS les chemins ; money-trail préservé. NONE.
- **sync deleted_ids board-exit** : repro tautologique (`status=10 AND status∈{13,16,19}`=toujours 0) ; WS-up neutralise via OrderStatusChanged ; auto-guérit. P3 backlog DRY, pas P2.

## 5. GATES
- **25 tests verts** (Fiscal seal 4, ChangePaymentStatus 4+4, delivery guard 3, no-client-totals 4, idempotency 1, loyalty 5).
- **Frozen 0** sur les 3 commits · **NF525 CHAIN OK** (4 branches) · 0 `.vue` KDS/caisse.

## 6. RESTE OWNER / WORKSTREAM
- **Décision pricing** : livraison ≥30€ = tous-canaux ou web-only ? (débloque le heal POS ou son commentaire d'exclusion).
- **Data backfill** : 19 commandes off-book PAID+fiscal NULL déjà en base — rétro-allocation = migration délicate (risque gap/double), **décision owner** (le fix code stoppe la création de NOUVELLES).
- **LOCK gate** : FrontendOrder cancel lock (P2 frozen-adjacent).
- **Workstream Uber go-live** : merge-key snapshot-first + OrderCreated dispatch (source-gaté) — à traiter ensemble à l'activation Uber.
- **V1.0.2 backlog** : `branch()` admin resolution (isolation-critique, no V1 impact).
