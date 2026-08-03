# Ultra-intersection audit — KitchenReleaseRule + broadcast (KDS / OSS / customer-display)

HEAD 48050af80 · DB foodking_e2e · read-only · slug `ui-board-release-sync`

## Cible = fonction partagée `App\Domain\Kds\KitchenReleaseRule`
SSOT « quelle commande est visible/bumpable au board cuisine » + son miroir SQL
`applyBoardReleaseFilter()`. Son propre docstring (l.84-98) impose : *« list() and
changeStatus() MUST share one definition so 'visible on the board' and 'bumpable'
can never diverge again »*.

## N consommateurs énumérés + vérifiés

| # | Chemin | Prédicat release utilisé | Cohérent ? |
|---|--------|--------------------------|-----------|
| 1 | `KitchenDisplaySystemOrderService::list()` (board) — l.78 | `applyBoardReleaseFilter` | ✅ |
| 2 | `KitchenDisplaySystemOrderService::changeStatus()` (bump guard) — l.447 | `orderIsReleasedForBoard` | ✅ (même booléen `isReleasedForBoard`) |
| 3 | `KitchenDisplaySystemOrderService::orderItems()` (item-board) — l.533 | `applyBoardReleaseFilter` | ✅ |
| 4 | `KdsSyncService::sync()` (feed delta polling) — l.96-108 | **AUCUN** | ❌ **DIVERGENT** |
| 5 | `OrderStatusScreenOrderService::list()/listForBranch()` (OSS wall) | pas de filtre paiement, mais gate implicite `status∈{PREPARING,PREPARED}` | ✅ (0 divergence prouvée) |
| 6 | Broadcast `OrderCreated`/`OrderStatusChanged` → `domain_events` → soketi | signal-only, non-rendu | ✅ |

### Identité du prédicat (raisonnement)
- Booléen `isReleasedForBoard` = `PAID || PENDING_COUNTER || (POS && CASH)`.
- SQL `applyBoardReleaseFilter` = `payment_status=PAID OR =PENDING_COUNTER OR (order_type=POS AND pos_payment_method=CASH)`.
- **Équivalents.** list()==changeStatus()==orderItems() → « visible == bumpable » tenu. ✅

### PENDING_COUNTER (borne Plan B) — abus demandé
Prouvé cohérent : PENDING_COUNTER est released des 3 côtés (board l.133, bump l.105,
item-board l.533). Une commande borne Plan B est visible ET bumpable ET sur l'item-board,
à l'identique. ✅

### OSS (wall client) — abus demandé
OSS n'utilise PAS KitchenReleaseRule ; il filtre `order_type∈{KIOSK,TAKEAWAY}` +
`status∈{PREPARING,PREPARED}`. Repro LECTURE : sur 189 commandes OSS-visibles,
**0 non-released-au-board** → aucune divergence (pour atteindre PREPARING la commande
est déjà passée par une transition release-gated). Fail-closed allowlist OK. ✅

### Broadcast (KDS/OSS/customer-display) — cohérence
- `DispatchKdsTicket` (chemin bump KDS) gate `OrderStatusChanged` sur `shouldDispatchStatusChanged`
  = canTransition (ACCEPT→PREPARING, PREPARING→PREPARED).
- `OrderService::changeStatus` (chemin POS/admin) dispatch `OrderStatusChanged` **inconditionnel**
  → transitions de sortie (→DELIVERED/CANCELED/RETURNED) atteignent bien le board.
- Deux producteurs, gating différent, mais **corrects par domaine** (le chemin KDS ne fait
  que des canTransition ; le chemin POS doit tout diffuser). Le front `_statusChangeAffectsKds`
  inclut old_status∈{4,7,8} → retire les cartes sorties. Cohérent. ✅

## INCOHÉRENCE CONFIRMÉE (P2) — `KdsSyncService::sync()` oublie le filtre release

Le feed delta que le KDS interroge en fallback/polling filtre seulement
`status∈{ACCEPT,PREPARING,PREPARED}` + fenêtre du jour. Son docstring dit
« Mirror KitchenDisplaySystemOrderService::list active window » — il ne mirror QUE la
fenêtre temporelle, **pas** `applyBoardReleaseFilter`. C'est un 3ᵉ chemin de lecture du
même board qui viole l'invariant SSOT que le docstring de KitchenReleaseRule interdit.

### Repro LIVE (couche requête partagée, tinker lecture seule)
```
active total=498  board-visible(list)=495  DIVERGENT(sync-only, unreleased)=3
  #4621 status=4(ACCEPT)  pay=10(UNPAID) type=5(DELIVERY) posPM=NULL released=false
  #4825 status=4(ACCEPT)  pay=10(UNPAID) type=5(DELIVERY) posPM=NULL released=false
  #4991 status=8(PREPARED) pay=10(UNPAID) type=5(DELIVERY) posPM=NULL released=false
```
Ces 3 commandes livraison impayées (cash-on-delivery) sortent dans le feed `sync.orders`
alors que `list()` les exclut correctement. État REACHABLE (donnée réelle produite ;
`total ACCEPT+UNPAID=2` aujourd'hui hors fenêtre). Aucun sentinel n'assure ce miroir.

### Pourquoi P2 et non P0/P1 (refute-by-default, honnête)
- **Pas de fuite board visible aujourd'hui** : le front (`KitchenDisplaySystemComponent`
  l.1526) n'affiche PAS `sync.orders` — il l'utilise comme SIGNAL puis refetch `admin/kds-order`
  (list, gaté). Les cartes viennent donc du chemin gaté.
- **Pas de leak notification/off-book** : même si une carte non-released apparaissait, le
  guard `orderIsReleasedForBoard` de changeStatus (l.447) bloque le bump (422).
- **Ce qui RESTE réel** : (a) exposition API — `/admin/kds-order/sync` renvoie le détail
  complet (KDSOrderDetailsResource) de commandes NON libérées à tout staff cuisine ;
  (b) violation de l'invariant SSOT documenté ; (c) piège de régression : tout client qui
  rendrait `sync.orders` directement (le pattern « raw axios » est documenté dans le code)
  ré-ouvrirait la classe de bug « unreleased visible » déjà corrigée sur list()/changeStatus.

### Fix proposé (1 ligne, non-frozen, hors NF525)
Dans `KdsSyncService::sync()` après la construction de `$ordersQuery` (l.108, avant le
`if ($branchId>0)`), ajouter :
```php
KitchenReleaseRule::applyBoardReleaseFilter($ordersQuery);
```
+ sentinel qui compare l'ensemble d'ids sync vs list pour une commande unpaid-delivery
active du jour (doit être vide).

## Verdict : HAS_ISSUES (1×P2). Les 3 vrais consommateurs de KitchenReleaseRule
(list/changeStatus/item-board) + OSS + broadcast sont COHÉRENTS ; seul le 4ᵉ chemin de
lecture (KdsSyncService) a silencieusement divergé du SSOT.
