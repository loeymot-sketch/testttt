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

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi : ONB-05 (menu : page X, retrait crédit ; réglages SLA typés) · ONB-11 (vocabulaire des widgets) · ONB-12 (« Cayenne » dans PDF/exports) · ONB-01 (mentions légales du PDF) · ONB-02 (`/api/frontend/menu` 799 requêtes) · État final : —
