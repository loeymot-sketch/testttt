# GOAL — ONB-07 TABLEAU DE BORD & RAPPORTS VRAIS
## FoodKing — Onboarding commerçant · chaque chiffre du Dashboard a une définition, égale le rapport, égale la base, égale l'export — et un commerçant le comprend en 30 secondes

- **Slug** : `ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « tableau de bord & rapports » (`admin/{dashboard,salesReport,itemsReport,transactions,orderHistory,creditBalanceReport}/**`)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS.md`
- **Port de session** : **8807** · **Persona** : Nadia ferme à 22 h et veut savoir en 30 s ce qu'elle a vendu, par canal, si le tiroir est juste ; elle exporte pour son comptable.

> **En cinq lignes.** Le problème : 12 widgets, 3 rapports, 18 exports, un PDF de clôture — mais **aucune définition écrite** de ce que chaque chiffre inclut (période,
> `business_date` vs `created_at`, statuts, TTC/HT, canaux), une parité écran/export **jamais re-prouvée** depuis juin (REP-03/04), deux composants de widgets
> **orphelins** (`CustomerStatsComponent`, `TopCustomersComponent` : API vivante `/top-customers`, jamais montés), un **rapport X** sans page, un rapport crédit caché,
> et une zone **non auditée en direct** le 2026-08-26 (limite de session : brief Z4 prêt, à rejouer en W1). FINI = un « dictionnaire des chiffres » testé,
> widget = rapport = base = export (C1..C6), lisible par Nadia. Hors : caisse/encaissement/Z en écriture (jamais), `ZReportService` (gelé). Premier geste : W0 puis
> exécuter le brief Z4 de `recon/_ZONES.md` sur :8807 avec un compte de lecture.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb07-rapports`, branche `goal/onb07-rapports-2026-08-26`, depuis **HEAD**.
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8807` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Services\DashboardService::class)` → worktree ; serveur 8807 ; `PLAYWRIGHT_BASE_URL`.
- Base partagée : ce GOAL est **lecture-surtout** ; aucune commande, aucune session de caisse, aucun Z ne sont créés ; les jeux de données de test passent par les **usines de tests** (`tests/Feature`, sqlite `:memory:`) ; les mesures « écran = base » se font sur les données locales existantes (`orders` réelles de `foodking_e2e`) ; ⛔ jamais `migrate:fresh` ; `safe-test.sh --phpunit "Dashboard|Report|Reports|Transaction|OrderHistory"`.
- ⚠️ Les exports génèrent des fichiers : `tests/Feature/Reports/GeneratedReportsStayOutOfRepoTest.php` exige qu'ils restent hors dépôt — respecter.
- Filet : `git branch backup/pre-onb07-2026-08-26` (aucun dump nécessaire : lecture).

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Dictionnaire des chiffres | `app/Services/DashboardService.php` (948 l.), `app/Http/Controllers/Admin/DashboardController.php`, `config/dashboard.php`, `docs/DICTIONNAIRE_DES_CHIFFRES.md` (À CRÉER), `resources/js/components/admin/dashboard/**` (aide contextuelle) |
| S2 Parité écran / export / PDF | `app/Http/Controllers/Admin/{SalesReportController,ItemsReportController,TransactionController,CreditBalanceReportController}.php`, `app/Exports/{SalesReportExport,ItemsReportExport,TransactionExport,CreditBalanceReportExport,OrderExport}.php`, `resources/js/components/admin/{salesReport,itemsReport,transactions,creditBalanceReport}/**`, `app/Http/Resources/SalesReportOverviewResource.php` |
| S3 Rapports manquants / orphelins | `app/Http/Controllers/Admin/Fiscal/XReportController.php` (**lecture**, page Vue À CRÉER `settings/Fiscal/XReportComponent.vue`), `admin/dashboard/{CustomerStatsComponent,TopCustomersComponent}.vue`, `admin/orderHistory/**`, `OrderHistoryController.php` |
| S4 Lisibilité | `admin/dashboard/DashboardComponent.vue` (accès rapide, widgets), `fr.json` (bloc `label.dashboard_*`), périodes prédéfinies |

| HORS | Porté par |
|---|---|
| `app/Services/Fiscal/ZReportService.php`, `AuditLogService.php` (gelés), `z_reports`, clôture Z, encaissement, sessions de caisse (`CashOverview`, `CashSessionReport` = voie CAISSE) | jamais / voie CAISSE — ce GOAL **lit** et confronte |
| `PosOrdersTracker`, `Encaissement` (composants POS) | voie CAISSE |
| Visibilité de « Rapport solde crédit » (retrait) et de la page X dans le menu | ONB-05 |
| Journal d'audit (`AuditTrailComponent` lit `audit_logs` — gelé en écriture) | lecture seulement |
| Vocabulaire global | ONB-11 (ce GOAL applique sur ses écrans) |

Zones à coordonner : `routes/api.php` (route page X), `settingRoutes.js`/`dashboard` routes, `fr.json`.

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (fiscal) · SCOPE-2 3 boucles · SCOPE-3 migration (aucune ; une vue SQL éventuelle = G-DATA) · SCOPE-4 **NF525** : un « chiffre corrigé » qui change un total fiscal historique = STOP (les rapports lisent, ne réécrivent jamais) · SCOPE-5 autre voie.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques** · **⚠️ instrument avant produit** : un écart écran/base doit être reproduit par deux moyens (API + SQL) avant d'être un constat.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Dictionnaire des chiffres | chaque widget et colonne de rapport a une définition (période, champ de date, statuts inclus/exclus, TTC/HT, canaux, arrondi) **testée** | **100 %** des 12 widgets + 3 rapports |
| C2 | Widget = rapport = base | pour 5 chiffres témoins sur 3 périodes : `DashboardService` = `SalesReportController` = `SUM(...)` SQL | **15/15**, écart 0,00 |
| C3 | Export = écran | Excel / CSV / PDF portent les mêmes filtres, totaux, libellés que la liste affichée | **3/3** rapports |
| C4 | Fuseau et date métier | un ticket de 23:30 Europe/Paris tombe dans le bon jour (`business_date`), y compris changement d'heure | **VRAI** |
| C5 | Lisibilité | aide « ? » sur chaque widget ; 0 libellé brut ; états vides honnêtes ; temps de chargement par widget mesuré | **< 2 s** par widget (données locales) |
| C6 | Orphelins tranchés | `CustomerStats`/`TopCustomers` montés ou supprimés ; rapport X accessible ; rapport crédit retiré ou justifié | **3/3** tranchés |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · NF525 ajout seul · `tests/Feature/Dashboard/` = **14** (`DashboardBranchScopeMatrixTest`, `DashboardTilesArePeriodScopedTest`, `DashboardRevenueNettingSentinelTest`, `EodPdfRecapSentinelTest`, `EodTenderPrecedenceSentinelTest`, `SalesSummaryPerDayTest`, `SalesSummaryAvgPerDayDivisorSentinelTest`, `SlaAlertesBorneBasseTest`, `TotalOrdersCountSemanticsTest`, `TotalOrdersRealVolumeSentinelTest`, `ChannelStatisticsMirrorExcludedSentinelTest`, `DashboardChannelCatchAllSentinelTest`, `OrderStatisticsSingleGroupedQueryTest`, `AuditTrailUsesAuditLogSentinelTest`) ·
`tests/Feature/Reports/` = 7 (`SalesReportFilterParitySentinelTest`, `SalesReportListMirrorParitySentinelTest`, `SalesReportNetTotalSentinelTest`, `ItemsReportUnitsSoldSentinelTest`, `ReportPdfMaxRowsIsConfigurableTest`, `CreditBalanceCustomersOnlySentinelTest`, `GeneratedReportsStayOutOfRepoTest`) + `tests/Feature/Report/ReportPdfNoTruncationTest.php` (**deux dossiers : anomalie à fusionner**) ·
BRAIN : tuiles « depuis toujours » → `period=today` sur `business_date` (15/08) ; alertes SLA bornées 24 h (344 → 0, 25/08) ; `SalesSummary` diviseur corrigé.

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-NON-MESURÉ** — le brief Z4 n'a pas été exécuté le 26/08 (limite de session). Tranché : ce GOAL **commence** par le mesurer (W1) ; aucun constat P0/P1 n'est écrit ici sans reproduction — §2 liste ce qui est **connu par le code et l'historique**, pas ce qui est prouvé à l'écran.
- **C-DEUX-DOSSIERS** — `tests/Feature/Report/` (1) et `tests/Feature/Reports/` (7) coexistent (anomalie notée le 12/08, jamais traitée). Tranché : fusion dans `Reports/` en W2 (déplacement, pas de suppression).
- **C-ORPHELINS** — API `/top-customers` (`routes/api.php:1481`) et `DashboardService::customerStates :303`, `topCustomers :351` vivantes, composants jamais montés (`DashboardComponent.vue:73-106`). Tranché : G-WIDGETS (monter ou retirer) — en V1 locale sans comptes clients, **retirer** est recommandé.
- **C-X** — `Fiscal/XReportController@show` (`routes/api.php:1710-1711`) sans page. Tranché : page de lecture sous Rapports Z (perm `pos/manage-fiscal`), **aucune écriture**.

## §0.8 — Le commerçant-type et ses questions
Nadia, 22 h 05 : 1. « Combien j'ai vendu aujourd'hui, TTC, et c'est "aujourd'hui" jusqu'à quelle heure ? » 2. « Pourquoi le widget dit 1 240 € et le rapport 1 198 € ? »
3. « Mon export Excel pour le comptable, c'est le même chiffre que l'écran ? » 4. « Par canal — borne, comptoir, téléphone, Uber — je vois où ? » 5. « "Alertes SLA", "Netting", "Précédence tender"… en français ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Widgets | **CORRIGÉS EN AOÛT, NON DOCUMENTÉS** | `app/Services/DashboardService.php` (`orderStatistics :59`, `orderSummary :158`, `salesSummary :222`, `customerStates :303`, `topCustomers :351`, `totalSales :392`, `totalOrders :403`, `totalCustomers :418`, `totalMenuItems :428`, `realtimeReport :438`, `slaAlerts :490`, `channelStatistics :531`, `eodSynthesis :664`, `auditTrail :884`) · `DashboardController` (`routes/api.php:1472-1493` : `/top-customers :1481`, `/sla-alerts :1486`, `POST /eod-pdf :1492` gate `pos-manage-fiscal`) · `config/dashboard.php:24,29` · `admin/dashboard/*.vue` (15 fichiers, 12 montés `DashboardComponent.vue:48-69`) | 14 |
| S2 Rapports | **SENTINELLES DE PARITÉ EXISTANTES** | `SalesReportController.php` (`index :46`, `export :59`, `pdf :68`, `salesReportOverview :112`) · `ItemsReportController` (`routes/api.php:1502-1506`) · `TransactionController` (`:1547-1550`) · `CreditBalanceReportController` (`:1508-1511`) · `app/Exports/{SalesReportExport,ItemsReportExport,TransactionExport,CreditBalanceReportExport}.php` · `PaginateRequest` | 7 + 1 |
| S3 Manquants | **ORPHELINS** | `Fiscal/XReportController@show` (`api.php:1710-1711`) · `admin/dashboard/{CustomerStatsComponent,TopCustomersComponent}.vue` · `OrderHistoryController` (`api.php:1352-1355`) · `admin/orderHistory/{HistoriqueComponent,HistoriqueListComponent}.vue` | — |
| S4 Lisibilité | **NON MESURÉE** | `DashboardComponent.vue:30-46,133-188` (accès rapide, repli permissif `:143-145`) · `fr.json` | (À CRÉER) |

**Sortie d'ancrage brute** : `ls tests/Feature/Dashboard | wc -l` → 14 · `ls tests/Feature/Reports` → 7 · `ls tests/Feature/Report` → 1 · `grep -n "public function" DashboardService.php` → 14 méthodes (lignes ci-dessus) · `wc -l DashboardService.php` → 948 · `ls app/Exports` → 18 classes ·
`grep -n "top-customers\|sla-alerts\|eod-pdf" routes/api.php` → `:1481`, `:1486`, `:1492` · `ls admin/dashboard` → 15 composants · `SELECT COUNT(*) FROM orders` (à mesurer W0).

# §2 — ÉTAT CONNU LE 2026-08-26 (non audité en direct — à rejouer W1 avec `recon/_ZONES.md` § Z4)
**Connu par le code / l'historique** : 12 widgets montés, 2 orphelins ; bouton PDF clôture (EOD) gate `pos-manage-fiscal` ; alertes SLA bornées à 24 h depuis le 25/08 (`SlaAlertesBorneBasseTest`) ; tuiles scopées `period=today`/`business_date` depuis le 15/08 (`DashboardTilesArePeriodScopedTest`) ;
parité liste/export protégée par `SalesReportListMirrorParitySentinelTest` et `SalesReportFilterParitySentinelTest` (**à lire : que prouvent-elles exactement ?**) ; bugs REP-03/04 de juin (écran vs export) **jamais re-vérifiés à l'écran** ; `/api/frontend/menu` 731 ms / 799 requêtes (Z2 — hors périmètre, signalé) ; repli permissif de l'accès rapide (`:143-145`).
**À mesurer en W1** (brief Z4) : cohérence total du jour widget / rapport / SQL ; export vs écran ; filtres période et fuseau ; états vides ; permissions `chef@`/`pos@` ; libellés ; PDF EOD (contenu, mentions, « Cayenne ») ; les 2 orphelins.

# §3 — SOUS-SYSTÈME 1 : LE DICTIONNAIRE DES CHIFFRES

## Sub 1.1 — Définir, puis tester la définition
**Ancrages** : `DashboardService.php:59-531`, `config/dashboard.php`, `tests/Feature/Dashboard/*`.
**Tâches**
- **T-1.1.1** — Pour chacune des 14 méthodes de `DashboardService` : lire le SQL réel, écrire la définition (période par défaut, champ de date, statuts inclus/exclus — annulé `status=16` ?, remboursé ?, TTC/HT, canaux, arrondi, filiale) dans `docs/DICTIONNAIRE_DES_CHIFFRES.md` (À CRÉER).
  • test : (À CRÉER à `tests/Feature/Dashboard/DictionnaireDesChiffresSentinelTest.php` — chaque méthode publique de `DashboardService` a une entrée) 
- **T-1.1.2** — Test de cohérence « widget = rapport = base » : usine de 20 commandes (canaux, statuts, jours, heures limites 23:59/00:01, remboursement) → `totalSales`, `orderSummary`, `channelStatistics`, `salesSummary` = `SalesReportController::salesReportOverview` = `SUM` SQL, pour aujourd'hui / hier / 7 jours.
  • test : (À CRÉER à `tests/Feature/Dashboard/WidgetsEgalentRapportsEtBaseTest.php`) · C2
  • au-delà : commande annulée après paiement (NF525 : jamais supprimée) → exclue des ventes, visible dans « annulations » ; commande à cheval sur minuit ; changement d'heure (29 mars / 25 octobre) ; filiale 0 vs 1.
- **T-1.1.3** — Fuseau et date métier : prouver `business_date` (cron Z 23:59/00:01, `app/Console/Kernel.php:495-549`) et `Europe/Paris` sur une commande de 23:30 et une de 00:30.
  • test : (À CRÉER à `tests/Feature/Dashboard/BusinessDateTimezoneTest.php`) · C4
**Acceptation** : C1 = 100 % · C2 = 15/15 · C4 VRAI · 3 tests VERTS · dictionnaire écrit.

## Sub 1.2 — Lisibilité des widgets
**Tâches**
- **T-1.2.1** — Aide « ? » par widget (définition en une phrase issue du dictionnaire), unité et devise explicites, période affichée (« aujourd'hui, jusqu'à la clôture »), états vides honnêtes (« aucune vente aujourd'hui » ≠ 0 € muet).
  • ancrage : `admin/dashboard/*.vue` · test : (À CRÉER à `tests/js/dashboardWidgetsHelpAndEmptyStates.spec.js`) · visuel : `http://127.0.0.1:8807/admin/dashboard` à 1366/1024/768
- **T-1.2.2** — Vocabulaire FR : « SLA », « Netting », « Tender », « Audit trail » → termes commerçant (proposition ONB-11, application ici) ; 0 libellé brut.
- **T-1.2.3** — Temps de chargement par widget mesuré (réseau) ; `OrderStatisticsSingleGroupedQueryTest` déjà en place — étendre aux widgets > 500 ms.
  • test : (À CRÉER à `tests/Feature/Dashboard/WidgetsQueryBudgetSentinelTest.php`) · C5
**Acceptation** : C5 · 2 tests VERTS · captures lues · questions 1, 4, 5 de Nadia = OUI.

# §4 — SOUS-SYSTÈME 2 : EXPORT = ÉCRAN = PDF

## Sub 2.1 — Rapport des ventes
**Ancrages** : `SalesReportController.php:46,59,68,112`, `SalesReportExport.php`, `SalesReportListMirrorParitySentinelTest.php`, `SalesReportFilterParitySentinelTest.php`, `SalesReportNetTotalSentinelTest.php`, `ReportPdfNoTruncationTest.php`, `ReportPdfMaxRowsIsConfigurableTest.php`.
**Tâches**
- **T-2.1.1** — Lire les 3 sentinelles de parité : couvrent-elles filtres + totaux + libellés + tri + pagination (export = **toutes** les lignes, pas la page) ? Consigner les trous.
- **T-2.1.2** — Test de parité complet : mêmes filtres (période, canal, statut, filiale) → liste API, Excel (`Maatwebsite`), CSV, PDF : mêmes lignes, mêmes totaux, mêmes libellés FR, mêmes arrondis ; export sans pagination.
  • test : (À CRÉER à `tests/Feature/Reports/SalesReportExportEqualsScreenTest.php`) · C3
  • au-delà : 0 ligne (export vide propre) ; 10 000 lignes (`ReportPdfMaxRowsIsConfigurableTest`) ; caractères accentués/emoji dans les noms ; injection de formule Excel (`tests/Feature/Security/ExcelFormulaInjectionGuardTest.php` existant).
- **T-2.1.3** — Bugs REP-03/04 de juin (écran vs export) : reproduire ou clore avec preuve (référence `reports/test-e2e/mgmt-testplan-2026-06-03/` si présent).
**Acceptation** : C3 pour les ventes · test VERT · question 3 de Nadia = OUI.

## Sub 2.2 — Rapport articles, transactions, crédit
**Tâches**
- **T-2.2.1** — Même parité pour `ItemsReport` (`ItemsReportUnitsSoldSentinelTest` existant : étendre aux exports) et `Transactions` (`TransactionExport`).
  • test : (À CRÉER à `tests/Feature/Reports/ItemsAndTransactionsExportParityTest.php`)
- **T-2.2.2** — Rapport solde crédit (caché, `CreditBalanceCustomersOnlySentinelTest`) : sans objet en V1 locale sans comptes clients → proposer le retrait (fiche ONB-05, G-CREDIT).
- **T-2.2.3** — Fusion `tests/Feature/Report/` → `Reports/` (déplacement, exécution prouvée).
**Acceptation** : C3 = 3/3 · test VERT · G-CREDIT tranché.

# §5 — SOUS-SYSTÈME 3 : RAPPORTS MANQUANTS ET ORPHELINS

**Tâches**
- **T-3.1.1** — Page « Rapport X » (lecture, perm `pos/manage-fiscal`) sous Rapports Z : consomme `XReportController@show` sans le modifier ; aucune écriture fiscale ; entrée de menu via ONB-05.
  • ancrage : `routes/api.php:1710-1711`, `settings/Fiscal/ZReportListComponent.vue` (modèle) · test : (À CRÉER à `tests/Feature/Fiscal/XReportPageReadOnlyTest.php`) · visuel : `/admin/settings/z-reports` + X
- **T-3.1.2** — `CustomerStatsComponent` / `TopCustomersComponent` : G-WIDGETS → retirer (composants archivés sous `_archive/`, routes API conservées ou retirées avec test) ou monter avec définition.
  • test : (À CRÉER à `tests/js/sentinels/dashboardNoOrphanWidgetsSentinel.spec.js`)
- **T-3.1.3** — Historique (`OrderHistoryController`, `HistoriqueListComponent`) : définitions (statuts, période), export si absent, cohérence avec le rapport des ventes (une commande = une ligne des deux).
  • test : (À CRÉER à `tests/Feature/Reports/HistoriqueEgaleRapportVentesTest.php`)
**Acceptation** : C6 = 3/3 · 3 tests VERTS.

# §6 — SOUS-SYSTÈME 4 : PDF DE CLÔTURE ET ALERTES (lecture, confrontation)

**Tâches**
- **T-4.1.1** — PDF EOD (`eodSynthesis :664`, `EodPdfRecapSentinelTest`, `EodTenderPrecedenceSentinelTest`) : contenu confronté au Z du jour (lecture `z_reports`) et au rapport des ventes ; mentions légales issues de l'identité (ONB-01) ; « Cayenne » en dur → fiche ONB-12.
  • test : (À CRÉER à `tests/Feature/Dashboard/EodPdfEgaleZEtRapportTest.php`)
- **T-4.1.2** — Alertes SLA : définition dans le dictionnaire, fenêtre/seuil exposés comme réglages typés (fiche ONB-05), état vide « aucune commande en retard ».
**Acceptation** : test VERT · fiches écrites.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau coupé | effet caisse / fiscal | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Widgets | N/A | rafraîchissement cohérent | N/A | même chiffre | `chef@`/`pos@` : `DashboardBranchScopeMatrixTest` (existant) + W1 | « aucune vente » | 10 000 commandes (`WidgetsQueryBudgetSentinelTest`) | widget en erreur → message, pas 0 | lecture seule | — | minuit, changement d'heure, filiale 0 |
| Rapport ventes | N/A | filtres conservés | export idempotent | — | 403 sans `sales-report` | export vide | 10 000 lignes | timeout export → message | commande annulée exclue/visible | — | période inversée, 1 seconde, 2 ans |
| Export Excel | — | — | — | — | `ExcelFormulaInjectionGuardTest` | — | `ReportPdfMaxRowsIsConfigurableTest` | — | — | — | `=1+1` dans un nom d'article |
| Rapport X / PDF EOD | — | — | idempotent (aucune écriture) | — | `pos/manage-fiscal` | jour sans vente | — | — | **jamais d'écriture** (test) | — | jour non clos |
| Historique | — | — | — | — | `pos-orders` | vide | 10 000 | — | = rapport ventes | — | commande à cheval sur minuit |

# §A — ARMÉE D'AGENTS
Architecte (définitions, source unique des agrégats) · **DBA** (SQL réel des 14 méthodes, index sur `business_date`, `orders.status`) · Sécurité (exports, formules Excel, exposition des rapports par rôle) · UX/A11y (widgets, aide, 3 gabarits) ·
**Psychologie commerçant** (confiance dans un chiffre = savoir ce qu'il contient ; deux chiffres différents = « le logiciel ment » ; anglais = « pas pour moi ») · SRE (temps par widget) · Implémenteur unique · ROUGE (rejoue le brief Z4 + SQL après chaque vague, cherche l'écart de 0,01 €) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases (`SELECT COUNT(*) FROM orders`, par statut, par jour) | séquentiel | — |
| **W1** | **Reconnaissance Z4** (brief `recon/_ZONES.md`) : navigateur + API + SQL, captures lues ; lecture des 3 sentinelles de parité ; livrable `recon/Z4_dashboard_rapports.md` | fan-out lecture seule (≤ 2 navigateurs) | — |
| **W2** | S1 dictionnaire + cohérence (T-1.1.*) ; fusion des dossiers de tests (T-2.2.3) | séquentiel | — |
| **W3** | S2 parité exports (T-2.*) | séquentiel | — |
| **W4** | S3 manquants/orphelins (T-3.*) + S4 (T-4.*) | séquentiel | G-WIDGETS, G-CREDIT, G-X |
| **W5** | S1.2 lisibilité (T-1.2.*) | séquentiel | vocabulaire ONB-11 (proposition) |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Dashboard|Reports|Report|Fiscal"`, Vitest, Playwright `tests/e2e/onb07-*.spec.js` (À CRÉER), BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-DEF** | Définitions retenues pour les chiffres ambigus (annulations, remboursements, TTC/HT par défaut, « aujourd'hui » = jusqu'à la clôture Z) | Propriétaire | validation du dictionnaire | `docs/DICTIONNAIRE_DES_CHIFFRES.md` + MISSION §6 | EN ATTENTE — bloque T-1.1.2 (tests figent la définition) |
| **G-WIDGETS** | `CustomerStats` / `TopCustomers` : retirer (recommandé) ou monter | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-3.1.2 |
| **G-CREDIT** | Retirer « Rapport solde crédit » en V1 (exécuté par ONB-05) | Propriétaire | accord | `MISSION_ONB05` §6 | EN ATTENTE |
| **G-X** | Page Rapport X en lecture (perm `pos/manage-fiscal`) | Propriétaire | accord | MISSION §6 | EN ATTENTE — bloque T-3.1.1 |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `CLAUDE.md §3ter, §8` · `SYSTEM_MAP.md §5` · `docs/BUSINESS_RULES.md` · `docs/ORDER_FLOW.md` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-07) · `recon/_ZONES.md` (Z4) · `recon/Z0_carte_dashboard.md §1, §3` ·
`PROJECT_BRAIN.md §2` (SLA 344 → 0 ; tuiles `period=today`) · `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (Wave 5 Reports, REP-03/04) · `plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md` (B3 : deux dossiers de tests) · `tests/Feature/Dashboard/*`, `tests/Feature/Reports/*`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 10 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 (`ZReportService`, `AuditLogService`) ; 5. NF525 ajout seul, **aucun rapport n'écrit** ; 6. gates tranchés ; 7. `docs/DICTIONNAIRE_DES_CHIFFRES.md` commité et testé, BRAIN vrai ; 8. deux cycles identiques ; 9. fiches de renvoi (ONB-05 menu X/crédit, ONB-11 vocabulaire, ONB-12 « Cayenne » du PDF, ONB-01 mentions).
**Interdit** : créer une commande, une session de caisse ou un Z pour « avoir des données » (usines de test seulement) · corriger un chiffre historique · déclarer une parité sans comparer les fichiers exportés · approuver un gate.
> Le sens : à 22 h 05, Nadia lit 1 198,40 € TTC, sait que c'est « jusqu'à la clôture, annulations exclues », exporte, et le comptable voit le même nombre.
