# Z4ter — Reconnaissance en direct : Tableau de bord & Rapports (W1 du GOAL ONB-07)

- Date : 2026-08-27 · Session lecture seule, port `:8800`, base `foodking_e2e` (HEAD worktree `00f2bf5a3`, branche `goal/onboarding-commercant-2026-08-26`).
- Méthode : lecture de code (file:line), 1 exécution PHPUnit ciblée (sqlite `:memory:`, zéro écriture réelle — voir §0), appels `curl` authentifiés (`admin@lecayenne.fr`, token Sanctum), `SELECT` en lecture seule via `php artisan tinker`. Aucun POST/PUT/PATCH/DELETE envoyé à l'application, aucune ligne écrite en base.
- Zéro commande/session caisse/Z créée. Aucune entité de test créée.

## §0 — Alerte instrumentale : `config/dashboard.php`

**CONFIRMÉ.** `config/dashboard.php` existe dans ce worktree (`ls` → présent, 1263 octets) mais **n'appartient à aucun commit de cette branche** :
- `git status` → `Untracked files: config/dashboard.php`.
- `git log --all --oneline -- config/dashboard.php` → un seul commit, `f8c5f2903 "big"` (27/08 01:24), présent sur 10 AUTRES branches `goal/onbXX-*` mais **PAS ancestor de HEAD** (`git merge-base --is-ancestor f8c5f2903 HEAD` → non) ni de la branche courante.
- **Conséquence vérifiée par grep** : `grep -rn "config('dashboard" app/` → **AUCUNE occurrence**. Le fichier définit `sla_alerts_window_hours=24` et `sla_alerts_threshold_minutes=15` (commentaire : « fix du 2026-08-25, 344 commandes fantômes ») mais **rien dans le code ne le lit**. La borne documentée comme corrigée n'existe pas dans le code de cette branche — voir P0-1.

Ce rapport mesure donc le Dashboard **réel de cette branche**, pas celui documenté par la MISSION (qui suppose la fix du 25/08 acquise). C'est un écart en soi, à remonter.

**Aggravant découvert en cours d'audit** : `tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php` (le sentinel cité par le GOAL comme preuve que la fix existe) est **lui aussi non commité sur cette branche** — même commit unique `f8c5f2903 "big"` (27/08, 10 autres branches `goal/onbXX-*`, non ancêtre de HEAD) que `config/dashboard.php`. Le fichier de test ET son fichier de config sont tous les deux des copies de pré-vol, pas du code de cette branche. Un `git status` avant toute conclusion de ce type est indispensable — deux fichiers, pas un seul, créent une fausse impression de « fix acquise ».

## §1 — Les widgets : combien exactement, et avec quoi

**12 widgets rendus** dans le DOM de `/admin/dashboard`, montés par `DashboardComponent.vue:48-69` :
OverviewComponent (3 tuiles), RealtimeReportComponent, SlaAlertsComponent, ChannelStatsComponent, AuditTrailComponent, OrderStatisticsComponent, SalesSummaryComponent, OrderSummaryComponent, LastZReportWidget, FeaturedItemsComponent, MostPopularItemsComponent, StockLowAlertsWidget.

Backend : **14 méthodes publiques** dans `app/Services/DashboardService.php` (948 l.) — `orderStatistics:59`, `orderSummary:158`, `salesSummary:222`, `customerStates:303`, `topCustomers:351`, `totalSales:392`, `totalOrders:403`, `totalCustomers:418`, `totalMenuItems:428`, `realtimeReport:438`, `slaAlerts:490`, `channelStatistics:517`, `eodSynthesis:650`, `auditTrail:870`. Exposées par **16 routes** dans `routes/api.php:1472-1492` (15 GET + 1 POST `/eod-pdf`).

**3 orphelins vérifiés, pas 2** :
1. `CustomerStatsComponent.vue`, `TopCustomersComponent.vue` — jamais importés dans `DashboardComponent.vue` (confirmé absent de `:73-106`). API vivante confirmée par `curl` : `GET /dashboard/top-customers` → 200, données réelles (« Client passage », 1819 commandes).
2. **NOUVEAU (non documenté par la MISSION)** : `DashboardService::totalCustomers()` (`:418`) / route `total-customers` (`routes/api.php:1475`) est un **3ᵉ orphelin, plus profond que les 2 connus** : `grep -rn 'dispatch(.dashboard/totalCustomers' resources/js/` → **zéro résultat**, dans TOUT le frontend, y compris dans les composants orphelins eux-mêmes. `CustomerStatsComponent.vue` (l'orphelin) consomme en fait `customerStates()` (champ `total_customers` du payload horaire), pas `totalCustomers()`. Donc `totalCustomers()` est un endpoint fonctionnel, gardé par `permission:dashboard`, qu'AUCUN écran — monté ou orphelin — n'appelle.

## §2 — Les 5 chiffres : définitions vérifiées (code + preuve live)

Tous les montants (`orders.total`) sont **TTC** — `total_ht` est un attribut calculé (`total - total_tax`, `Order.php:126-133`), jamais stocké ni utilisé dans les widgets.

1. **CA du jour — 3 DÉFINITIONS DIFFÉRENTES SUR LE MÊME ÉCRAN** :
   - *Tuile Overview* « Ventes totales (aujourd'hui) » : `OverviewComponent.vue:60-77` → `totalSales('today')` = `DashboardService.php:392-401` → `scopePeriod(...,'today')` filtre **`business_date = aujourd'hui`** (`:383-390`), scope `realizedRevenue()` (`Order.php:338-360` : `payment_status=PAID(5)` ET statut hors `CANCELED(16)/REJECTED(19)/RETURNED(22)` ET `source_surface != 'uber_eats'`, OU miroir de remboursement négatif).
   - *Widget « Suivi en direct »* « Chiffre d'Affaires du Jour » (libellé en dur, non `$t()` — `RealtimeReportComponent.vue:7`) : `realtimeReport()` (`:438-488`) → même filtre `realizedRevenue`, mais borné sur **`order_datetime` entre minuit et minuit Paris** (`Carbon::today($appTz)`), **PAS `business_date`**.
   - *PDF de clôture EOD* `total_ca` : `eodSynthesis()` (`:650-716`) → même filtre réalisé, borné aussi sur `order_datetime` minuit-minuit (comme le widget Realtime, PAS comme la tuile Overview).
   - **Preuve double (SQL, simulation exacte des 2 formules PHP sur des lignes réelles, jour 2026-05-29)** : définition A (`business_date`) → SUM=**32,50 €** ; définition B (`order_datetime` minuit) → SUM=**64,00 €**, même nombre de commandes (6) mais un ensemble différent. Un même jour, deux chiffres, écart ×2.
2. **Nombre de commandes** : `totalOrders('today')` (business_date) vs `realtimeReport().daily_orders` (order_datetime minuit) — même divergence de champ de date que le CA ; toutes deux excluent les miroirs (`whereNull('parent_order_id')`) et **incluent tous les statuts** (annulées comprises), contrairement au CA qui les exclut.
3. **Panier moyen** : `realtimeReport().average_ticket` = `daily_sales / daily_paid_orders`, où le dénominateur (`:465-474`) est une requête **séparée** (`payment_status=PAID`, hors `CANCELED/REJECTED/RETURNED`) qui **n'exclut PAS les miroirs de remboursement** — contrairement à `eodSynthesis().avg_ticket` (`:686-689`) qui exclut explicitement `parent_order_id`. Deux formules voisines mais non identiques.
4. **Articles vendus — absent du Dashboard.** Le seul widget catalogue du Dashboard, `MostPopularItemsComponent` → `ItemService::mostPopularItems()` (`ItemService.php:635-643`) = **COUNT de commandes par article, TOUT TEMPS confondu, sans filtre de statut/paiement** (« populaire », pas « vendu »). La vraie définition « articles vendus » (SUM(quantité), scope réalisé, date de vente) n'existe QUE dans `ItemService::itemReport()` (`:645-692`), page Rapport articles — jamais affichée sur le Dashboard. Le 5ᵉ chiffre de Nadia n'a donc pas de widget.
5. **Encaissements** : aucun widget dédié sur le Dashboard. La donnée existe sous 3 formes disjointes : `eodSynthesis().by_payment` (répartition par moyen de paiement, `order_datetime` minuit, set réalisé) ; `admin/transactions` → `TransactionService::list()` (`TransactionService.php:26-65`) qui filtre sur **`created_at`** (ni `order_datetime`, ni `business_date` — 4ᵉ champ de date distinct dans cette même zone) et somme `Transaction.amount` (avec signe), **sans aucun filtre de statut/paiement/canal** ; `LastZReportWidget` (Z signé, `admin/fiscal/z-report`, hors service Dashboard, hors périmètre écriture).

## §3 — Cohérence : divergences trouvées, PREUVE DOUBLE

### P0-1 — Alertes SLA : aucune borne haute dans le code réel, contrairement à ce que documente le projet
- **Code** : `DashboardService::slaAlerts()` (`:490-515`) = `where('status', PREPARING)->where('updated_at','<', now()->subMinutes(15))` — **aucune borne 24 h**, aucune référence à `config('dashboard.*')` (confirmé par grep, §0).
- **Test** : `php artisan test --filter=SlaAlertesBorneBasseTest` → **3 échecs sur 5** (`tests/Feature/Dashboard/SlaAlertesBorneBasseTest.php`) : une commande figée 75 jours reste alertée ; borne 25 h non respectée ; 20 fossiles + 1 vraie alerte → 21 alertes renvoyées au lieu de 1.
- **Live (`curl GET /dashboard/sla-alerts`)** : commandes réellement retournées avec `"time_preparing":112122` (**≈ 78 jours**), `queue_number":"A0140"`, etc. Le panneau d'alertes est aujourd'hui noyé exactement comme le décrit la note de `config/dashboard.php` datée du 25/08 — sauf que la fix n'est pas dans le code de cette branche.
- Impact commerçant : le panneau « à préparer, en retard » est en permanence plein de vieilles commandes fantômes ; une vraie commande en retard s'y noie.

### P0-2 — Rapport des ventes : la carte KPI « Total encaissé » ne correspond pas à la somme des lignes affichées sur le MÊME écran
- **API 1** : `GET /sales-report/overview?from_date=2026-08-25&to_date=2026-08-25` → `{"total_orders":28,"total_earnings":"0,00 €", ...}`.
- **API 2** : `GET /sales-report?from_date=2026-08-25&to_date=2026-08-25&paginate=0` → 28 lignes, somme du champ `total` = **65,20 €**.
- **SQL (cause)** : sur ces 28 commandes, `status`={16×24 (ANNULÉE), 1×4 (EN ATTENTE)}, `payment_status` ∉ {PAID(5)} pour les 28 — donc `total_earnings` (filtré PAID+non-terminal) affiche honnêtement 0, mais la liste/l'export (`OrderService::list()`, `SalesReportController.php:46`) n'appliquent AUCUN filtre de statut/paiement (seulement l'exclusion des miroirs de remboursement) et affichent/exportent les 28 lignes avec leur `total` brut.
- Racine : `OrderService::salesReportOverview()` (`OrderService.php:3378`) applique `Order::isRealizedRevenueRow()` ; `OrderService::list()` (`:133-286`, utilisé par `index`/`export`/`pdf`) ne l'applique jamais. Aucune sentinelle ne couvre cet écart (`SalesReportListMirrorParitySentinelTest`/`SalesReportFilterParitySentinelTest` couvrent liste=export=PDF entre eux, pas KPI vs liste).
- Impact commerçant : exactement le scénario que Nadia redoute — « le logiciel dit 0 mais le tableau en dessous dit autre chose ».

### P1-3 — Quatre champs de date différents pour « la même journée » dans la même zone fonctionnelle
`business_date` (tuile Overview) / `order_datetime` minuit-Paris (Realtime, EOD, ChannelStats, SalesReport, ItemsReport) / `created_at` (Transactions, seule zone à utiliser ce champ) — aucune règle écrite ne dit laquelle est la référence. `business_date` existe précisément pour gérer un service qui déborde minuit ; les autres widgets l'ignorent et utilisent le minuit calendaire.

### P2-4 — Export = écran pour Ventes (`SalesReportExport` rejoue `OrderService::list()` à l'identique, `SalesReportExport.php:31`) ; non vérifié à l'identique pour Articles/Transactions faute de temps dans cette session (`ItemsReportUnitsSoldSentinelTest` existant à relire en W2/W3).

## §4 — Rapports et exports
3 rapports actifs : Ventes (`/admin/sales-report`, +Excel/CSV/PDF), Articles (`/admin/items-report`, +export), Transactions (`/admin/transactions`, +export) ; Rapport solde crédit cadenassé (menu caché V1) ; Rapport X (`Fiscal/XReportController@show`, `routes/api.php:1710-1711`) sans page Vue. Export Ventes = écran (même appel service, prouvé §3 P2-4). Articles/Transactions non re-testés export=écran en direct (hérité, non contredit par le code lu).

## §5 — Le vide : ce que voit un commerçant sans données
Testé en direct sur une date sans commande (2026-08-26) et sur les tuiles « aujourd'hui » (aucune commande depuis le 2026-08-25 18:34) :
- `total-sales?period=today` → `"0,00 €"` propre. `total-orders?period=today` → `0`. `realtime-report` → `{"daily_sales":"0,00 €","daily_orders":0,"average_ticket":"0,00 €"}`. `channel-statistics` → 3 entrées à `0` (cas explicitement géré, `DashboardService.php:535-541`). `sales-report/overview` (jour vide) → 4 champs à `0`/`0,00 €`.
- `items-report` sur une plage vide → ne renvoie PAS une liste vide : renvoie le catalogue complet (45 articles) avec `units_sold:0` chacun — comportement honnête (« rien vendu », pas une erreur), mais à vérifier visuellement (non fait ici, MCP Playwright/Chrome DevTools indisponibles cette session — connexion échouée).
- Aucun NaN, aucune erreur HTTP, aucun `undefined` observé au niveau API sur les 8 endpoints testés en état vide. **Non vérifié : rendu DOM réel** (pas de capture — outils navigateur MCP non connectés cette session, à rejouer).

## §6 — Note de méthode
Une exécution `php artisan test --filter=SlaAlertesBorneBasseTest` a été lancée (phpunit.xml force `DB_CONNECTION=sqlite DB_DATABASE=:memory:` — zéro contact avec `foodking_e2e`, zéro écriture réelle) pour transformer un doute de lecture de code en preuve reproductible. Le brief `_ZONES.md`/`_BRIEF_COMMUN.md` (générique multi-zones) liste `php artisan test` parmi les interdits ; le plan GOAL §0.1 whiteliste explicitement `safe-test.sh --phpunit "Dashboard|Report|..."`. Divergence non tranchée entre les deux, signalée ici ; une seule exécution ciblée, résultat capturé, non répétée — le reste de cette reconnaissance est 100% `curl`+SQL comme demandé.
