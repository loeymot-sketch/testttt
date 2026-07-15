# WA — Audit GESTION (rapports EOD + dashboard) — 2026-07-15

Périmètre : `DashboardService.php`, `DashboardController.php`, `CashSessionReportController.php`,
`CreditBalanceReportController.php`, `SalesReportController` + `OrderService::salesReportOverview`,
lecture `ZReportService` (frozen, non modifié). V1 LOCAL Le Cayenne, mono-poste, branch_id=1.

Méthode : lecture code + reproduction DB réelle (tinker sur la base live) + comparaison avec la
logique de bucketing du Z (source de vérité fiscale).

---

## P1 — EOD synthèse PDF : tenders CARTE/ticket-resto/mobile de la borne (Plan B) comptés en « Espèces » → diverge du Z

**Fichier** : `app/Services/DashboardService.php:712-737` (`resolvePaymentBucketKey`), gate ligne 714-715.

**Cause** : le bucket de paiement de la synthèse EOD ne lit `pos_payment_method` (le vrai tender
encaissé au comptoir) QUE si `order_type === OrderType::POS (15)` :

```php
$orderType = (int) ($order->order_type ?? 0);
$isPosTender = $orderType === \App\Enums\OrderType::POS;   // 15 uniquement
if ($isPosTender) { ... pos_payment_method ... }
$method = (int) ($order->payment_method ?? 0);              // sinon → payment_method
```

Or, à Le Cayenne, `kiosk.payment_route_all_to_counter=true` (Plan B, vérifié : config=true) :
TOUTE commande borne est créée avec `payment_method = CASH_ON_DELIVERY (1)` et
`order_type = TAKEAWAY(10)` ou `KIOSK(25)` — **jamais 15**. Au comptoir, `confirmCounterPayment`
(`PaymentService.php:358`) écrit le VRAI mode dans `pos_payment_method` (CASH=1, CARD=2, MOBILE=3,
TICKET=5, OTHER=4). Comme `order_type != 15`, `resolvePaymentBucketKey` ignore `pos_payment_method`
et bucket sur `payment_method=1` → **« Espèces »** pour toutes ces ventes, y compris celles réglées
par carte / ticket-resto / mobile.

**Divergence avec le Z (preuve)** : `ZReportService::applyOrderToTotals` (frozen, `:792`) fait
`$method = pos_payment_method ?: payment_method` — précédence **inverse** : le Z bucketise
correctement ces ventes sous leur vrai tender. Le PDF EOD est pourtant gated `pos-manage-fiscal`,
archivé 6 ans NF525, et commenté « owner: agree with the Z » (DashboardController:225,
DashboardService:629). Il **contredit** le Z sur la ventilation par mode de paiement.

**Repro DB (base live)** — commandes NON-POS, PAID, `pos_payment_method` ≠ cash, mais `payment_method=1` :
```
pos_payment_method=2 (CARTE)   : 66 cmd, 491,31 €  → affichées « Espèces »
pos_payment_method=5 (TICKET)  : 25 cmd, 173,50 €  → affichées « Espèces »
pos_payment_method=3 (MOBILE)  :  7 cmd,  78,00 €  → affichées « Espèces »
pos_payment_method=4 (AUTRE)   :  6 cmd,  21,00 €  → affichées « Espèces »
TOTAL : 104 cmd / 763,81 € de tenders non-cash étiquetés Espèces (historique)
```
Le `total_ca` reste juste (les deux buckets itèrent le même set `$realized`) ; c'est la
**ventilation** qui est fausse : Espèces surévalué, Carte/Ticket/Mobile sous-évalué, sur un document
fiscal — et en désaccord avec le Z.

Blade `resources/views/pdf/eod_synthesis.blade.php:113-118` rend `by_payment` verbatim (label/count/total),
donc le mauvais bucket sort tel quel sur le PDF.

**Fix (scope-minimal, hors frozen)** : aligner `resolvePaymentBucketKey` sur la précédence du Z —
préférer `pos_payment_method` dès qu'il porte un tender réel (∈ {CASH,CARD,MOBILE,OTHER,TICKET_RESTAURANT}),
quel que soit `order_type` :
```php
$posMethod = (int) ($order->pos_payment_method ?? 0);
$isPosTender = in_array($posMethod, [CASH,CARD,MOBILE_BANKING,OTHER,TICKET_RESTAURANT], true);
if ($isPosTender) { return match($posMethod){...}; }
// sinon payment_method (web/kiosk réglé en ligne)
```
COUNTER_DEFERRED(6) reste exclu (pas encore encaissé) → tombe sur `payment_method`. Non-régression :
une vente POS pure (order_type=15) a déjà `pos_payment_method` → comportement identique.

---

## P2 — Dashboard donut « channel-statistics » : commandes livraison + POS legacy silencieusement droppées (somme des % < 100)

**Fichier** : `app/Services/DashboardService.php:537-549` (`channelStatistics`, filtre `webCount` sans catch-all).

**Cause** : `channelStatistics` répartit en 3 buckets (Web / Kiosk-App / POS) SANS bucket
fourre-tout. `webCount` ne compte que `source_surface==='web'` OU `source===WEB(5)` ; `kioskCount`
que `surface==='kiosk'` OU `source===APP(10)` ; `posCount` que `surface==='pos'` OU `source===POS(15)`.
Toute commande dont le couple (source_surface, source) ne matche AUCUN prédicat est **comptée nulle
part** alors qu'elle est bien dans `$total = $orders->count()` → les 3 pourcentages somment à **< 100 %**.
Contraste : `bucketChannels` (PDF EOD, `:762-763`) a un `web` catch-all et attribue chaque commande.

**Repro DB (logique exacte rejouée sur base live)** :
```
total=3212  kiosk=1248  web=107  pos=1786  sum=3141  DROPPED=71 (2,2 %)
Combos droppés :
  delivery|NULL => 19    delivery|0 => 12   delivery|1 => 1   (= 32 cmd LIVRAISON, canal online)
  NULL|1 => 33  (POS legacy source=1 sans surface)
  NULL|NULL => 7   NULL|2 => 2   mobile|0 => 1   phone|NULL => 1
```
Les 32 commandes livraison (`source_surface='delivery'`, `source` null/0 — auto-taggées delivery par
le déploiement, cf. commentaire `channelStatistics:519-520`) disparaissent du donut : la tranche
« Web » sous-représente le volume en ligne. Repro utilisateur : GET
`/api/admin/dashboard/channel-statistics` un jour comportant une commande livraison → web+kiosk+pos < 100 %,
la livraison n'apparaît dans aucune tranche. Défaut déterministe (le donut est today-only mais le
chemin livraison est live).

**Fix (scope-minimal, hors frozen)** : donner à `webCount` le rôle de catch-all online comme
`bucketChannels` — compter Web = toute commande qui n'est ni kiosk ni pos (au lieu du prédicat
positif `surface==='web' || source===WEB`). Optionnellement router les POS legacy (`source=1`/surface
NULL) vers pos. La somme redevient 100 % et la livraison rejoint le canal Web.

---

## Vérifs négatives (pas de finding)

- **Réconciliation total EOD** : `by_payment` et `by_channel` itèrent le même set `$realized` avec
  buckets exhaustifs (match `default` + `web` catch-all) → Σ == `total_ca`. Le TOTAL réconcilie ;
  seule la ventilation #1 est fausse.
- **Isolation branche** : `dashboardBranchId()` (Admin→null=tout, staff→branch_id) et
  `CashSessionReportController` (admin bypass BranchScope, staff scopé) corrects. Mono-branche V1 de
  toute façon. Hint `branch_id` d'un staff ignoré silencieusement (pas de leak, pas de 403 fuiteur).
- **RBAC rapports** : `sales-report` (Admin+Branch Manager only), `credit-balance-report`,
  `pos-manage-fiscal` (EOD PDF) correctement gated ; `salesReportOverview` ajouté au `->only()`
  (REP-AUTHZ-01). POS Operator / Chef n'ont pas `sales-report` (vérifié seeder live).
- **bucketChannels miroirs remboursement** : `source`/`source_surface` copiés du parent (SELF-AUDIT
  R3) → nettent dans le bon canal. OK.
