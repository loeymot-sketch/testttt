# MISSION ONB-07 — TABLEAU DE BORD & RAPPORTS VRAIS · Rapport de mission
- GOAL : `plans/GOAL_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`) — **zone NON auditée en direct** (limite de session) : la reconnaissance est la W1 de ce GOAL.
- Port : **8807** · Voie : CENTRAL « tableau de bord & rapports » · Parallèle avec : 01, 02, 05, 06, 08, 09, 10 (vague A)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-07 (tableau de bord & rapports vrais). Lis : CONSTITUTION.md, PROJECT_BRAIN.md §2, SYSTEM_MAP.md, PARALLEL_PROTOCOL.md,
plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS.md,
plans/GOAL_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS_2026-08-26.md, puis recon/_BRIEF_COMMUN.md et la section Z4 (+ RÉSILIENCE) de recon/_ZONES.md,
recon/Z0_carte_dashboard.md (§1, §3). Pré-vol §0.1 : worktree .claude/worktrees/onb07-rapports depuis HEAD, APP_URL=http://127.0.0.1:8807, .env.testing,
liens durs, serveur 8807, PLAYWRIGHT_BASE_URL, filet backup/pre-onb07. ⛔ Ce GOAL est en LECTURE : aucune commande, session de caisse ou Z créés pour
« avoir des données » — usines de tests seulement ; jamais migrate:fresh. Puis « lance le GOAL » : W0 (compter les commandes locales par statut/jour) →
W1 = exécuter le brief Z4 (navigateur + API + SQL, ≤ 2 navigateurs, captures lues, livrable recon/Z4_dashboard_rapports.md ; lire les 3 sentinelles de parité
existantes) → W2..W6. Tout constat P0/P1 exige DEUX moyens (API + SQL). Pipeline ultra-audit-profond, spécialistes lecture seule en un message (DBA en tête),
implémenteur unique, ROUGE avant tout « fini », Jalonneur, matrice §S, deux cycles identiques. Fichiers possédés = §0.2 ; fiscal gelé, caisse = autre voie.
Jamais de push. Gates §G : proposer, ne pas trancher. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Le soir, le commerçant fait confiance au logiciel **si les chiffres se recoupent**. Le mandat : contrôle total et confiance. Les widgets ont été corrigés plusieurs fois en août
(tuiles « depuis toujours », alertes SLA, diviseur des moyennes) sans qu'une **définition** de chaque chiffre soit écrite ni qu'une parité écran/export soit re-prouvée
depuis juin. Ce GOAL écrit le dictionnaire, le teste, et rend le Dashboard lisible. Persona Nadia, 22 h 05, comptable le lendemain.

## 2. ÉTAT CONNU LE 2026-08-26 (code + historique ; **aucune mesure écran** — W1)
**2.1 Surfaces** (Z0 §1, §3) : `/admin/dashboard` (12 widgets montés, accès rapide 13 liens, bouton PDF EOD), `/admin/sales-report` (+export/pdf/overview), `/admin/items-report` (+export), `/admin/transactions` (+export), `/admin/historique`, `/admin/credit-balance-report` (caché), `/admin/settings/z-reports` (lecture), rapport X sans page.
**2.2 Connu vert** : `DashboardTilesArePeriodScopedTest` (tuiles `period=today` sur `business_date`, 15/08) ; `SlaAlertesBorneBasseTest` (fenêtre 24 h, 344 → 0, 25/08) ; `SalesSummaryAvgPerDayDivisorSentinelTest` ; `DashboardRevenueNettingSentinelTest` ; `EodPdfRecapSentinelTest`, `EodTenderPrecedenceSentinelTest` ; `SalesReportListMirrorParitySentinelTest`, `SalesReportFilterParitySentinelTest`, `SalesReportNetTotalSentinelTest` ; `ItemsReportUnitsSoldSentinelTest` ; `ReportPdfNoTruncationTest`, `ReportPdfMaxRowsIsConfigurableTest` ; `DashboardBranchScopeMatrixTest` ; `OrderStatisticsSingleGroupedQueryTest`.
**2.3 Constats connus (à reproduire en W1 avant d'être retenus)**
| Sév. attendue | Constat | Source |
|---|---|---|
| P2 | Deux composants de widgets orphelins (`CustomerStatsComponent`, `TopCustomersComponent`) avec API vivante (`/top-customers`, `routes/api.php:1481`, `DashboardService::customerStates :303`, `topCustomers :351`) | Z0 §3, code |
| P2 | Rapport X : contrôleur (`routes/api.php:1710-1711`) sans page | Z0 §6 |
| P2 | Parité écran/export REP-03/04 (juin) jamais re-vérifiée à l'écran | GOAL ADMIN NAV BREADTH 13/08 |
| P2 | Deux dossiers de tests `Report/` (1) et `Reports/` (7) | `ls` 26/08 |
| P2 | Aucune définition écrite des chiffres (période, statuts, TTC/HT) ; vocabulaire technique (« SLA », « netting », « tender », « audit trail ») | code |
| P3 | Accès rapide : repli permissif (`DashboardComponent.vue:143-145`) — traité par ONB-06 (fail-closed global) | Z0 §3 |
| P3 | `/api/frontend/menu` 731 ms / 799 requêtes SQL (hors périmètre, signalé) | Z2 |
**2.4 Angles morts attendus** : « aujourd'hui » = jusqu'à quand ; commandes annulées/remboursées ; canaux (borne / comptoir / téléphone / web / Uber) ; TTC vs HT ; états vides.
**2.5 Cayenne** : PDF EOD et exports (en-têtes) — à vérifier W1.

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-06-03 : colonne vertébrale Dashboard/Historique convergée (25/25 boutons, KPI, invariants d'enregistrement) ; Wave E Reports **différée, jamais exécutée**.
- 2026-08-15 V5 : tuiles scopées à la journée métier ; widget stock-bas faux-vide corrigé.
- 2026-08-25 : SLA bornées 24 h ; PDF EOD sentinelles.
- Tests existants : `tests/Feature/Dashboard/` (14), `tests/Feature/Reports/` (7), `tests/Feature/Report/` (1), `tests/Feature/Security/ExcelFormulaInjectionGuardTest.php`.

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Service | `app/Services/DashboardService.php` (948 l.) | `orderStatistics :59`, `orderSummary :158`, `salesSummary :222`, `customerStates :303`, `topCustomers :351`, `totalSales :392`, `totalOrders :403`, `totalCustomers :418`, `totalMenuItems :428`, `realtimeReport :438`, `slaAlerts :490`, `channelStatistics :531`, `eodSynthesis :664`, `auditTrail :884` | à définir un par un |
| Contrôleur | `app/Http/Controllers/Admin/DashboardController.php` · `routes/api.php:1472-1493` (`/top-customers :1481`, `/sla-alerts :1486`, `POST /eod-pdf :1492`) | | |
| Config | `config/dashboard.php:24` (`sla_alerts_window_hours` 24), `:29` (`sla_alerts_threshold_minutes` 15) | | réglages typés → ONB-05 |
| Widgets | `resources/js/components/admin/dashboard/*.vue` (15 ; montés `DashboardComponent.vue:48-69`, imports `:73-106`, accès rapide `:133-188`, EOD `:17-27,219-249`) | | orphelins ×2 |
| Ventes | `SalesReportController.php` (`index :46`, `export :59`, `pdf :68`, `salesReportOverview :112`) · `app/Exports/SalesReportExport.php` · `SalesReportOverviewResource` · `admin/salesReport/*` | routes `:1495-1500` | |
| Articles / transactions / crédit | `ItemsReportController` (`:1502-1506`), `TransactionController` (`:1547-1550`), `CreditBalanceReportController` (`:1508-1511`) · exports homonymes | | crédit caché |
| Historique | `OrderHistoryController` (`:1352-1355`) · `admin/orderHistory/{HistoriqueComponent,HistoriqueListComponent}.vue` | | |
| Fiscal (lecture) | `Fiscal/XReportController@show` (`:1710-1711`), `Fiscal/ZReportController` (`:1700-1712`), `settings/Fiscal/ZReportListComponent.vue` · cron Z `app/Console/Kernel.php:495-549` | | `ZReportService` gelé |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Dashboard|Reports|Report"` → figer W0 · `SELECT status, COUNT(*) FROM orders GROUP BY status` et par `business_date` (7 derniers jours) → figer W0 (base locale, volumes locaux).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-DEF | Définitions des chiffres ambigus (annulations, remboursements, TTC/HT par défaut, « aujourd'hui ») | TTC par défaut, annulations exclues des ventes mais comptées à part, « aujourd'hui » = journée métier | tests non figés |
| G-WIDGETS | Retirer ou monter `CustomerStats`/`TopCustomers` | retirer (V1 sans comptes clients) | orphelins restent |
| G-CREDIT | Retirer « Rapport solde crédit » (via ONB-05) | oui | page cachée reste |
| G-X | Page Rapport X en lecture | oui | X inaccessible |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- **Zone non mesurée** : ne pas convertir les « constats attendus » en P1 sans reproduction API + SQL.
- Les chiffres locaux sont des volumes de bac d'essai (commandes de stress, annulations de nettoyage `status=16` — NF525 n'efface jamais) : distinguer définition et données.
- Un widget qui affiche 0 € peut être un faux-vide (leçon V4/V5 du 15/08) : vérifier la requête réseau.
- Exports : `GeneratedReportsStayOutOfRepoTest` — ne pas commiter de fichiers générés.
- Le serveur de dev est mono-requête : 12 widgets = 12 requêtes en série ; mesurer par requête, pas la page.
- `:8000` = autre worktree ; ta session = **:8807**.


### 8.9 Le Top 5 du PDF ne comptait pas la même population que le chiffre d'affaires

Trouvé le 2026-08-28 par un balayage adverse — **et c'est mon propre correctif
inachevé.**

Le même jour, j'avais remplacé la recopie du prédicat de revenu par un appel à la
règle (`DashboardService:737`), en écrivant au-dessus, noir sur blanc :

> « On appelle la règle au lieu de la recopier. Une copie ne suit pas les
> corrections de l'original — c'est exactement ce qui s'est passé ici. »

Et j'ai laissé la **même recopie** 150 lignes plus bas, dans `topItemsOfDay`, sans
l'exclusion Uber. *Le jumeau oublié, dans le même fichier, sous l'avertissement qui
le décrit.*

Mesure de l'agent sur la base, journée du 14/08 : **17 commandes** retenues par le
prédicat du Top 5 contre **7** pour le CA. Le document remis au comptable et archivé
six ans présentait deux populations différentes sous deux titres voisins, sans rien
signaler.

Le banc chiffre l'écart en restaurant le défaut : **42 unités au lieu de 2**.

### 8.10 Le PDF français écrivait les montants au format anglo-saxon

`AppLibrary::reportCurrencyAmountFormat` rendait `1,234.56` — séparateurs `'.', ','`
— quand l'écran affiche `1 234,56 €` via `NumberFormatter('fr_FR')`. Deux formats
pour la même somme, dans le même produit, sur des documents que le commerçant
compare.

Pire qu'inélégant : **« 1,234.56 » se lit « 1,23 » pour un œil français** — un
facteur mille au bas d'un document remis au comptable.

### 8.11 ⚠️ CONSTAT NON CORRIGÉ — deux tuiles du même tableau de bord

« Ventes du jour » filtre sur `business_date` ; « Chiffre d'Affaires du Jour » filtre
sur `order_datetime` **et** passe par `realizedRevenue()`, qui inclut les miroirs de
remboursement. Or `RefundWithCounterEntryService:112-133` crée le miroir **sans
`business_date`**. Mesure : 6 miroirs, **6 à NULL**, somme −89,00 €.

La tuile du haut est donc brute, celle du bas nette — et l'écart est exactement les
remboursements du jour. Les deux sont rendues sur la même page.

Le correctif touche la création des miroirs de remboursement, adjacente au domaine
fiscal. Consigné pour arbitrage plutôt que corrigé à chaud.

## 8. JOURNAL DE MISSION (rempli par la session)

Audit adverse en lecture seule le 2026-08-28. Consigne particulière donnée à
l'auditeur : signaler toute grandeur calculée à DEUX endroits, même hors constats —
un Total PDF a surestimé le chiffre d'affaires pendant quatre mois entre deux
sentinelles qui se croyaient complémentaires. Il en a trouvé quatre.

### 8.1 Les trois chiffres faux corrigés cette nuit

| Défaut | Ce que ça coûtait | Preuve |
|---|---|---|
| `applyOrderFilter` faisait `payment_status LIKE '%5%'` — or `PENDING_COUNTER` vaut **15**, et `15 LIKE '%5%'` est VRAI | 3 017 commandes comptées au lieu de 2 774, dont 243 **en attente d'encaissement** présentées comme payées | `OrderService.php:3498-3516` ; `LesChiffresDesRapportsSontJustesTest` |
| Le ticket moyen divisait un CA **sans** Uber par un nombre de commandes **avec** Uber | 9,10 € affiché contre 22,09 € réels au 14/08 — 59 % de sous-évaluation, sur le chiffre qui sert à décider d'une hausse de prix | `DashboardService.php:484-487` |
| Le PDF de clôture **recopiait** le prédicat de CA, et sa copie omettait l'exclusion Uber | 413,38 € annoncés contre 154,65 € au Z signé le 14/08 ; 137,00 € contre 0,00 € le 12/08. C'est le document remis au comptable, archivé six ans : son CA **et sa TVA** étaient surévalués du montant Uber, déjà facturé par l'agrégateur — donc déclaré deux fois | `DashboardService.php:737` |

### 8.2 Deux de mes quatre bancs NE MORDAIENT PAS

L'audit l'a trouvé, et c'est la trouvaille la plus importante de cette mission.

Ils faisaient `assertStringContainsString('Order::isRealizedRevenueRow', $source)` sur
`DashboardService.php`. Or cette chaîne y apparaît **trois fois** — dont **deux dans les
commentaires posés par mon propre commit correctif** (`:476`, `:724`). Remettre la copie
manuelle du prédicat laissait le banc VERT. Idem pour la regex du ticket moyen : le
commentaire explicatif contient les deux mots qu'elle cherchait.

C'est la pire forme du motif « sentinelle au mauvais périmètre » : le banc ne se
contente pas de ne rien prouver, il PRÉTEND attester le chiffre remis au comptable.

Réécrits en bancs de comportement. Le jeu d'essai contient délibérément une vente Uber
payée — que le prédicat rejette — sinon la copie manuelle et l'appel donneraient le
même total et le banc serait vert dans les deux cas. **Prouvé : avec la copie
restaurée, « Failed asserting that 600.0 matches expected 100.0 ».**

### 8.3 Divergences écran / export trouvées

| # | Grandeur | Écran | Export / PDF | Statut |
|---|---|---|---|---|
| **D1** | « Total » du rapport articles | `subTotal(itemsReports)` = somme de la PAGE (`per_page: 10`) | `paginate => 0` = tout le catalogue | **FIXÉ ce soir** — le serveur renvoie `total_unites_vendues` sur le même périmètre filtré ; `LEcranEtLExportDonnentLeMemeTotalTest` (3). ⚠️ Ce défaut avait DÉJÀ été corrigé pour le PDF en juillet, jamais porté à l'écran |
| **D2** | Lignes du rapport des ventes | `list($request, true)` — **sans** contre-écritures de remboursement | PDF : `list($request, false)` — **avec** | **OUVERT** — les Totaux concordent, mais le PDF liste des lignes `RTN-*` absentes de l'écran, et additionner à la main la colonne Total de l'écran ne redonne pas la tuile « Encaissé ». Choix documenté, indéfendable devant un commerçant qui vérifie |
| **D3** | « Aujourd'hui » | Tuile : `business_date` (**jour fiscal**) | Widget juste en dessous : `order_datetime` minuit-minuit | **GATE OUVERT** — `docs/gates/GATE_DEUX_DEFINITIONS_DU_JOUR_2026-08-28.md`. Le Cayenne sert jusqu'à 00h30 : l'écart est structurel, tous les soirs |
| **D4** | Construction des filtres | `OrderService::list` | `OrderService::salesReportOverview` | **OUVERT** — deux constructeurs pour la même fenêtre ; c'est la cause du « 3185 contre 3191 » corrigé le 12/08. Un filtre ajouté d'un seul côté le refait |

### 8.4 Constats §2.3 revérifiés

- **Rapport X sans page** : **CORRIGÉ AVANT L'ÉCRITURE DE LA MISSION** — le bouton existe depuis le 2026-08-06 (`ZReportListComponent.vue:136-140`), soit vingt jours avant le rapport qui l'affirme absent.
- **Widgets orphelins** (`CustomerStats`, `TopCustomers`) : **ENCORE VRAI** — API vivante, aucun import. Du code mort avec route ouverte.
- **Aucune définition écrite des chiffres** : **VRAI À 90 %** — exactement 3 clés `def_` dans tout le dépôt, toutes sur le rapport des ventes ; les 6 tuiles du tableau de bord et du suivi en direct n'en ont aucune.
- **Repli permissif de l'accès rapide** : **ENCORE VRAI** — `DashboardComponent.vue:139-145`, permission inconnue ou liste vide = lien affiché.
- **Deux dossiers `Report/` et `Reports/`** : **ENCORE VRAI, et l'écart s'est creusé** (1 contre 9). Un `--filter Reports` manque le singulier.

### 8.5 Ce qui reste — par coût pour qui lit ses chiffres pour décider

1. **D3** — trancher ce qu'est « aujourd'hui ». Tant que deux nombres cohabitent sous le même mot, aucun n'est cru. Gate ouvert, trois options chiffrées.
2. **D2** — soit le PDF cesse de lister les miroirs, soit l'écran les liste. La demi-mesure rend la colonne du PDF non additionnable.
3. **D4** — fusionner les deux constructeurs de filtres.
4. **Écrire le dictionnaire des chiffres** (gate G-DEF) et l'afficher : c'est ce qui transforme « je ne comprends pas ce chiffre » en « je décide ».
5. **Widgets orphelins** : les monter ou retirer composants ET routes.
6. **`tests/Feature/Report/` → `Reports/`.**

**État final ONB-07 : les trois chiffres faux sont corrigés ET leurs bancs réécrits en comportement après avoir été démasqués comme tautologiques. D1 est fixé. D2, D3 et D4 sont documentés, dont un en gate propriétaire — parce qu'ils touchent une définition ou un document archivé six ans, pas un défaut de calcul.**
