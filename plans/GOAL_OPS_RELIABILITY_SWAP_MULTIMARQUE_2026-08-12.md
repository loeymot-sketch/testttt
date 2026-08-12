# GOAL — Fiabilité des opérations + Swap multi-marque pilotable par IA

**GOAL_ID** : `GOAL-OPS-RELIABILITY-SWAP-MULTIMARQUE-2026-08-12`
**Date** : 2026-08-12
**Branche** : `pos/category-first-caisse-2026-06-23` · **HEAD** : `590e1cc62`
**Mandat parent (chantier A)** : `GLOBAL-OPS-RELIABILITY-OWNER-APPROVED-2026-08-12` (gate `HG-GLOBAL-OPS-RELIABILITY-2026-08-11`, `GATE_LOG.md:68`, statut `APPROVED_WITH_OWNER_CONSTRAINTS`)
**Mandat neuf (chantier B)** : back-office / centrale / stock / rapports / pages secondaires **réellement fonctionnels**, puis **swap multi-marque pilotable par une seule consigne IA** — demandé oralement par l'owner le 2026-08-12, aucun artefact disque produit (la session ChatGPT a été coupée par limite d'usage avant sa première sortie).

---

## §0 — PRÉAMBULE

### 0.1 Décision « arbre de travail » (obligatoire avant W1)

`git status --short` = **122 fichiers sales** au moment d'écrire. Le gate parent est explicite : *« The worktree contains unrelated and overlapping modifications, including POS, printing bridges/listeners and Uber. This gate does not authorize overwriting them. »*

Décision retenue : **INCLURE-EN-SCOPE-APRÈS-ATTRIBUTION**, jamais écraser.

- W0 attribue **chaque** fichier sale à un propriétaire (mission, session, humain) via `bash scripts/agent-activity-log.sh tail 50` + `git diff` par fichier.
- Un fichier sale **non attribuable** = **collision** → il sort du scope du lot concerné, et le lot est re-planifié autour. Interdiction absolue de « nettoyer » un diff qu'on n'a pas écrit (leçon `piege_ecran_blade_autonome_auth_2026-08-10` : le VPS portait 12 fichiers non committés d'une autre session).
- Sauvegarde avant toute édition : branche `backup/pre-goal-ops-swap-2026-08-12` + dump DB.
- ⚠️ Mémoire projet : *« DEUX sessions sur la même branche = HOLD deploy »*. Ce GOAL **ne pousse rien**. Voir §G.

### 0.2 Trois contradictions détectées — résolution explicite (CLAUDE.md §12)

Je ne les écrase pas en silence. Chacune est tranchée, avec sa source.

**C1 — Modèle d'exécution : qui édite le code produit ?**
- `AGENTS.md:161` (ère Cursor) : *« Claude … ne doit pas exécuter d'édition produit elle-même. Sa mission unique en EXECUTE = déléguer. »* Le plan ChatGPT reprend cette règle telle quelle.
- `CLAUDE.md §4` (édition **Claude Code**, 2026-05-19, autoritaire pour cette session) : *« Une session Claude Code = un agent qui orchestre ET exécute. Pas de split brain/executor. »*
- Instruction owner 2026-08-12 : *« améliorer au maximum directement audit, correction directement, et test »*.
- **→ Résolution : Claude exécute directement dans cette session.** La discipline de délégation est conservée là où elle a une valeur réelle : réservation `agent-activity-log.sh start` avant toute édition produit, et contre-audit par agents indépendants (§A). `codex-extension` reste disponible en canal de revue de plan si l'owner le veut, pas en pré-requis bloquant.

**C2 — « PAS un SaaS » vs. swap multi-marque**
- `CONSTITUTION.md §1` : *« V1 = LOGICIEL PERSONNEL du restaurant Le Cayenne … **PAS un SaaS.** Cloud / multi-tenant / scale = FUTUR. Ne JAMAIS les remonter comme P0/P1 ou blocker V1. »*
- Le chantier B demande explicitement l'inverse : un système paramétrable pour **n'importe quelle marque**, piloté par IA.
- **→ Résolution : c'est un amendement constitutionnel, pas une dérive.** L'owner en a le droit ; moi non, en silence. Donc :
  - le chantier B5 (swap) est **planifié intégralement** ici ;
  - son **exécution** est derrière la porte **G3** (§G) — une ligne de confirmation owner qui amende `CONSTITUTION.md §1` ;
  - **d'ici là, « multi-marque » ne devient jamais un P0/P1 bloquant sur les chantiers A / B1-B4.** On ne retarde aucune correction opérationnelle au nom du futur SaaS.
  - **Distinction dure à tenir** : *paramétrer* (sortir la marque du code vers la donnée) ≠ *multi-tenant* (plusieurs marques vivantes simultanément dans une même base). B5 fait le **premier** uniquement. Le second reste V2.

**C3 — Dérive documentaire vérifiée dans `SYSTEM_MAP.md`**
- `SYSTEM_MAP.md:95` annonce un *« Settings cluster (~26 controllers) »* sous `app/Http/Controllers/Admin/`.
- Réel : `ls app/Http/Controllers/Admin/ | grep -ci setting` = **0**. Les réglages vivent en **32 dossiers Vue** (`resources/js/components/admin/settings/`) + 2 groupes de routes (`routes/api.php:412`, `:1589`).
- **→ Résolution** : tâche `T-B4.1.1` corrige `SYSTEM_MAP.md`. Aucun lot ne s'appuie sur la ligne fausse d'ici là.

### 0.3 Deux chantiers, un GOAL

| | Chantier A — Fiabilité opérations | Chantier B — Back-office + swap |
|---|---|---|
| Origine | Audit + plan ChatGPT, **gate owner approuvé** | Demande orale owner 2026-08-12, **rien sur disque** |
| État planification | **Complet** : 27 sous-cycles `GLOB-OPS-00 → 26`, 18 exigences RQ-01..RQ-18 tracées | **Ce document** |
| Ma position | **Je ne re-planifie pas.** Je séquence, j'exécute, j'audite (§2) | Je décompose (§3-§7) |
| Verdict actuel | `0/18 PROVEN — HOLD` (`reports/qa/GLOBAL_OPS_REQUIREMENTS_TRACEABILITY_2026-08-12.md:8`) | Cartographie non faite |

Les deux partagent des services (`OrderService`, stock, print). **Jamais de fenêtre d'édition concurrente** entre un lot A et un lot B qui touchent le même service — cf. §X règles de parallélisme.

### 0.4 Pipeline par tâche — référence, non redécrit

Chaque tâche `T-*` s'exécute via le pipeline `ultra-audit-profond` (`~/.claude/skills/ultra-audit-profond/`). Frozen-zone → `lock-plan`. Audit de page → `test-e2e`. Ce GOAL ne re-décrit aucun de ces pipelines : il donne le **quoi**, l'**ancre**, le **test** et le **critère de rejet**.

### 0.5 Convergence + 6 règles permanentes

**Convergence d'un lot** = **deux cycles consécutifs** avec `P0+P1 = 0` **ET jeux de constats identiques**. Un seul cycle propre ne vaut rien (garde anti-flake).

Règles permanentes, applicables à **chaque** tâche de ce GOAL — issues des échecs mesurés du projet, pas de la théorie :

1. **Test-mutant obligatoire.** Tout test que j'écris : je retire le correctif, je prouve que le test **passe au rouge** avec un message parlant, je restaure. Un test resté vert = test vide → réécriture. *(Historique : « missing cart savedAt fixture », assertion sur le mauvais champ, garde doublée invisible aux mutations. Une mutation non détectée peut venir de la MUTATION mal choisie — le vérifier avant d'accuser le test.)*
2. **Le jumeau oublié.** Toute correction sur une surface → chercher **qui d'autre** répond à la même question (caisse/borne/web/KDS/mobile). Une définition dupliquée est une divergence programmée. *(Motif observé 3 fois en une seule journée le 2026-08-10.)*
3. **Balayage de régression avant le lot suivant.** `git diff HEAD~1 --name-only` → pour chaque fichier : « qu'est-ce que ce fichier faisait correctement que mon édition a pu casser ? » → réponse vérifiée par un test ou une capture. *(Historique : 3 des 6 P1 d'un cycle 7 étaient des régressions du cycle 6.)*
4. **La preuve est le contenu, jamais l'action.** Jamais « déployé » depuis un `git push` ; jamais « imprimé » depuis un 202 ; jamais « tiroir ouvert » depuis un `WritePrinter` ; jamais « page OK » depuis un HTTP 200.
5. **SQLite ne prouve rien sur la concurrence** (`lockForUpdate` ignoré). Toute assertion de concurrence exige MySQL + processus réels.
6. **Rien n'est clos sur « partiel mais plausible ».** Partiel > faux ; bloqué > silencieusement dangereux.

---

## §1 — CARTE DES SYSTÈMES (ancres vérifiées 2026-08-12)

Toutes les commandes d'ancrage ont été exécutées ; les chiffres ci-dessous sont des **sorties réelles**, pas des estimations.

| # | Système | Maturité | Ancres vérifiées | Tests existants |
|---|---|---|---|---|
| A | Opérations (POS card, borne, KDS WS, print, tiroir, inbox, stock saga) | **0/18 exigences PROVEN** | 10 × `tasks/TASK_GLOB_OPS_*.md` · plan 327 l. · audit 454 l. · décision adversariale 153 l. | dispersés — insuffisants par aveu du gate |
| B1 | CENTRALE / back-office | **inconnue — à cartographier** | **97** contrôleurs `app/Http/Controllers/Admin/*.php` · **41** modules routeur · **154** imports de vues dynamiques, **0 non résolu** · `DashboardController.php` + `app/Services/DashboardService.php` | `tests/Feature/Dashboard/` (10 fich. dont `DashboardBranchScopeMatrixTest.php`, `SalesSummaryPerDayTest.php`) · `tests/Feature/Admin/` |
| B2 | STOCK & disponibilité | partielle, dette connue | `app/Services/Stock/StockService.php` · `UnifiedStockViewService.php` · `RawMaterials/RawMaterialStockService.php` · contrôleurs `StockRuptureDashboard`, `UnifiedStockView`, `PosStockOutflow`, `Ingredient` | `tests/Feature/Stock/` (dont `StockBranchIsolationTest.php`, `StockConcurrentDecrementTest.php`, `StockCrossSurfaceSyncTest.php`, `ReconcileOrderReleasesCommandTest.php`) · `tests/Feature/Availability/` · `tests/Feature/Ingredients/` |
| B3 | RAPPORTS & pilotage | partielle | `SalesReportController` · `ItemsReportController` · `AnalyticController` · `AnalyticSectionController` · `CreditBalanceReportController` · `CashSessionReportController` | `tests/Feature/Report/` + `tests/Feature/Reports/` (**deux dossiers — anomalie, cf. T-B3.1.1**) · `tests/Feature/Analytics/` |
| B4 | RÉGLAGES / catalogue / personnalisation | large, non cartographiée | **32** dossiers `resources/js/components/admin/settings/` · `routes/api.php:412` + `:1589` · `ItemController` · `config/{menu,catalog_v15,product,pricing}.php` | `tests/Feature/Settings/` (3) · `tests/Feature/Catalog/` (26) · `tests/Feature/Items/` · `tests/Feature/Menu/` |
| B5 | SWAP multi-marque + pilotage IA | **inexistant** | **129 fichiers** citent « cayenne » sous `app/ config/ resources/js/ database/ routes/` · `app/Console/Commands/MenuResetLeCayenneCommand.php` · table `settings` (`2022_05_24_204620_create_settings_table.php`) · **46** fichiers `config/` | aucun (**tout à créer**) |

**Constat d'ancrage qui change le plan** : les **154 imports de vues admin se résolvent tous** (0 manquant). Donc la plainte owner (« plein d'onglets ne fonctionnent même pas ») **n'est PAS** un problème de fichiers absents. C'est du **runtime** : données vides, permission refusée en silence, filtre inopérant, erreur avalée, écran orphelin du menu. **Je refuse d'inventer la liste des écrans cassés** : c'est exactement la sortie de la Vague W1, et les lots B1-B4 sont dimensionnés **après** elle (§X).

**Serveur de dev** : `http://127.0.0.1:8000/login` → **HTTP 200** (les sondes runtime sont possibles immédiatement).
**Environnement** : `npm run verify:boucle` (`package.json:32`), `npm run validate:active-cycle` (`:34`).

---

## §2 — CHANTIER A : enregistrement, pas re-planification

Le chantier A **est déjà planifié et approuvé**. Le re-décomposer serait du gaspillage et un risque de dérive. Ce GOAL le **séquence** et s'engage à ses contraintes.

**Autorité** : `reports/planning/PLAN_GLOBAL_OPS_CLAUDE_ORCHESTRATION_2026-08-12.md` (27 sous-cycles `GLOB-OPS-00` → `GLOB-OPS-26`, vagues V0→V8).
**Traçabilité** : `reports/qa/GLOBAL_OPS_REQUIREMENTS_TRACEABILITY_2026-08-12.md` — 18 exigences, **0 PROVEN**. Une ligne ne passe `PROVEN` que sur preuve, jamais sur test unitaire vert.
**Contrainte owner autoritaire — CB POS manuelle externe** (elle prime sur toute formulation antérieure) :
- Le TPE est **physiquement séparé et non connecté**. Le caissier encaisse dessus, puis **confirme dans FoodKing** que la CB a été acceptée.
- FoodKing enregistre `CARD` (fiscal + gestion), imprime selon la politique, poursuit. **Aucun appel TPE, aucun health, aucun callback.**
- Le mono `CARD` **ne doit jamais être bloqué** faute de terminal configuré. Aucun `terminal_id` affiché s'il est jeté par le backend.
- **Interdit** : construire une intégration TPE dans ce scope. C'est un projet futur optionnel.
- **CB borne = fail-closed** : aucun humain ne peut attester le débit, donc sans intégration de confiance la CB borne est indisponible, pas simulée.
- **Tiroir** : ne pas supposer Ethernet. Beaucoup de tiroirs sont en **6P6C RJ12/RJ11** (ressemble à RJ45) branchés au port **DK de l'imprimante ticket**. Exiger modèle/photo/étiquette/port avant conclusion. Winspool/202 **n'est pas** un capteur d'ouverture.

**Ce que j'exécute** : V1 (confinements P0) en W2, V2-V7 (durables) en W6, V8 (qualification) en W8.
**Ce que je ne fais pas** : aucun EXECUTE monolithique ; aucune migration sans son sous-gate ; aucun GO commercial (humain, §G).

---

## §3 — B1 · CENTRALE / BACK-OFFICE

### Contrat
Le poste de pilotage du restaurant : tableau de bord, navigation, permissions, historique, pages secondaires. C'est **la centrale** au sens `CONSTITUTION.md §4.5` — tout doit être atteignable et opérant depuis là.

### Frozen zones au contact
Aucune en propre. Attention aux zones **partagées** (`SYSTEM_MAP.md §6`) : `resources/js/app.js`, `resources/js/router/index.js`, `resources/js/components/admin/components/**` (widgets importés jusque dans le `PaymentComponent.vue` gelé, `:334`).

### Sub 1.1 — Cartographie runtime et vérité de navigation
**Ancres** : `resources/js/components/layouts/backend/BackendMenuComponent.vue` · 41 modules `resources/js/router/modules/*.js` · 154 imports dynamiques · 97 contrôleurs `Admin/*.php`
- `T-B1.1.1` **Matrice d'état par écran** — chaque route admin classée en **5 états exclusifs** : `FONCTIONNEL` · `VISIBLE-MAIS-REFUSÉ` (permission) · `CHARGE-AVEC-ERREUR-SILENCIEUSE` · `ORPHELIN` (code vivant, absent du menu) · `NON-TESTÉ`. Succès = 100 % des routes classées, chacune avec sa preuve (code HTTP + extrait DOM + console).
  • ancre : `resources/js/router/modules/*.js` (41) · `routes/api.php`
  • test : (À CRÉER : `tests/Feature/Admin/AdminRouteReachabilityMatrixTest.php`)
  • visuel : sonde navigateur sur `http://127.0.0.1:8000/admin/*`
- `T-B1.1.2` **Écarts menu ↔ routeur ↔ permissions** — tout écran du menu doit être atteignable par au moins un rôle réel ; tout écran atteignable doit être joignable depuis le menu. Succès = 0 promesse morte, 0 orphelin non déclaré.
  • ancre : `BackendMenuComponent.vue` · `app/Http/Controllers/Admin/**`
  • test : (À CRÉER : `tests/Feature/Admin/MenuRouteParitySentinelTest.php`)
- `T-B1.1.3` **Pas de refus muet** — une permission refusée affiche un écran explicite FR, jamais un blanc ni un tableau vide. Succès = chaque refus produit un état visible et distinct de « aucune donnée ».
  • ancre : `app/Http/Middleware/**` · `resources/js/components/admin/components/**` (zone partagée — coordination)
  • test : (À CRÉER : `tests/Feature/Authz/AdminDeniedRendersExplicitStateTest.php`)
  • ⚠️ piège vérifié : `tests/TestCase.php` pose `Accept: application/json` partout → on teste le chemin JSON en croyant tester le navigateur. Forcer l'en-tête HTML.

### Sub 1.2 — Tableau de bord réellement exploitable
**Ancres** : `app/Http/Controllers/Admin/DashboardController.php` · `app/Services/DashboardService.php` · `tests/Feature/Dashboard/` (10 fichiers)
- `T-B1.2.1` **Chiffres justes et datés** — chaque tuile déclare sa fenêtre temporelle et sa population ; deux tuiles qui comptent la même chose donnent le même nombre. Succès = 0 divergence inter-tuiles.
  • test : `tests/Feature/Dashboard/SalesSummaryPerDayTest.php` + `SalesSummaryAvgPerDayDivisorSentinelTest.php` (existants) + (À CRÉER : `tests/Feature/Dashboard/DashboardCrossTileConsistencyTest.php`)
  • ⚠️ mémoire : « POS 77 web, tracker 0, health 484 » — populations incompatibles déjà mesurées (RQ-03).
- `T-B1.2.2` **Accès rapides opérants** — chaque raccourci du tableau de bord mène à un écran chargé avec le bon filtre pré-appliqué. Succès = 0 raccourci qui atterrit sur une liste non filtrée.
  • test : (À CRÉER : `tests/Feature/Dashboard/DashboardQuickActionTargetTest.php`)
  • visuel : `http://127.0.0.1:8000/admin/dashboard`
- `T-B1.2.3` **Isolation branche tenue** — `DashboardBranchScopeMatrixTest.php` étendu aux tuiles ajoutées. Succès = fuite inter-branche = 0.
  • test : `tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php` (existant, à étendre)

### Sub 1.3 — Historique, listes, filtres
**Ancres** : `OrderHistoryController.php` · `OnlineOrderController.php` · `tests/Feature/Admin/`
- `T-B1.3.1` **Filtres réellement appliqués** — chaque filtre de liste modifie la requête serveur, jamais seulement l'affichage. Succès = comptes serveur ≠ comptes non filtrés, prouvés en base.
  • test : (À CRÉER : `tests/Feature/Admin/AdminListFilterAppliesServerSideTest.php`)
- `T-B1.3.2` **Pagination sans limite silencieuse** — aucune liste ne tronque à 100 sans le dire. Succès = total annoncé = total en base.
  • test : (À CRÉER : `tests/Feature/Admin/AdminListNoSilentCapTest.php`)
  • ⚠️ jumeau : c'est le même défaut que `RQ-03` côté caisse — corriger **les deux** ou déclarer pourquoi non.
- `T-B1.3.3` **Le détail mène au bon objet** — un clic dans l'historique ouvre la commande cliquée (RQ-04 signale le contraire côté caisse). Succès = identifiant affiché = identifiant demandé, sur 100 % d'un échantillon.
  • test : (À CRÉER : `tests/Feature/Admin/OrderHistoryDetailIdentityTest.php`)

---

## §4 — B2 · STOCK & DISPONIBILITÉ

### Contrat
Le stock doit dire la vérité sur **toutes** les surfaces (caisse, borne, web, KDS, mobile) et converger après annulation, remboursement, perte et rejeu.

### Sub 2.1 — Vérité et convergence
**Ancres** : `app/Services/Stock/StockService.php` · `UnifiedStockViewService.php` · `tests/Feature/Stock/`
- `T-B2.1.1` **Échec physique + succès disponibilité reste détectable** — la disponibilité ne doit jamais avancer `released_qty` après un échec stock (RQ-16, `CONTRADICTED P1`). Succès = divergence détectée puis convergée ou mise en lettre morte, jamais silencieuse.
  • test : `tests/Feature/Stock/ReconcileOrderReleasesCommandTest.php` (existant) + (À CRÉER : `tests/Feature/Stock/StockAvailabilityDivergenceDeadLetterTest.php`)
- `T-B2.1.2` **Double annulation / remboursement / rejeu** — idempotent, y compris remboursement partiel. Succès = quantité finale identique quel que soit le nombre de rejeux.
  • test : (À CRÉER : `tests/Feature/Stock/StockDoubleCancelRefundIdempotencyTest.php`)
- `T-B2.1.3` **Aliment déjà préparé → perte, jamais retour en stock.** Succès = `stock_outflows` reçoit une perte, `on_hand` inchangé.
  • ancre : module repas/pertes `StockOutflow` (append-only)
  • test : (À CRÉER : `tests/Feature/Stock/PreparedFoodGoesToWasteNotOnHandTest.php`)
- `T-B2.1.4` **Concurrence à stock = 1** — deux commandes simultanées, une seule sert. **MySQL + processus réels obligatoires** (règle §0.5-5).
  • test : `tests/Feature/Stock/StockConcurrentDecrementTest.php` + `AvailabilityDecrementConcurrencyTest.php` (existants — à ré-éprouver sous MySQL)

### Sub 2.2 — Gestion utilisable au quotidien
**Ancres** : `StockRuptureDashboardController.php` · `UnifiedStockViewController.php` · `IngredientController.php` · `PosStockOutflowController.php`
- `T-B2.2.1` **Écran « Gestion Produits & Stock » complet** — rupture, réception, 86 manuel, seuils : chaque action a un retour visible et un effet prouvé en base. Succès = 0 action sans effet mesurable.
  • visuel : `http://127.0.0.1:8000/admin/stock/rupture` (route SPA réelle `admin.stock.rupture` — l'ancienne `/admin/stock-rupture-dashboard` renvoie 404)
  • test : `tests/Feature/Stock/ManualEightySixStickyThroughRestockTest.php` (existant) + (À CRÉER : `tests/Feature/Stock/StockDashboardActionsHaveEffectTest.php`)
- `T-B2.2.2` **Filtres et accès rapides du stock** — mêmes exigences que `T-B1.3.1`, appliquées ici (jumeau).
  • test : (À CRÉER : `tests/Feature/Stock/StockFilterAppliesServerSideTest.php`)
- `T-B2.2.3` **Matières premières ↔ produits finis cohérents** — la consommation de matière suit la vente. Succès = écart attendu/réel réconcilié ou signalé.
  • ancre : `app/Services/RawMaterials/RawMaterialStockService.php` · `RawMaterialConsumptionService.php`
  • test : (À CRÉER : `tests/Feature/Stock/RawMaterialConsumptionReconcilesTest.php`)

### Sub 2.3 — Parité inter-surfaces
- `T-B2.3.1` **Une rupture est vue partout** — caisse, borne, web, mobile `/m`. Succès = 4 surfaces, même verdict, dans le SLO.
  • test : `tests/Feature/Stock/StockCrossSurfaceSyncTest.php` (existant, à étendre au mobile)
- `T-B2.3.2` **Isolation branche du stock** — aucune fuite A/B.
  • test : `tests/Feature/Stock/StockBranchIsolationTest.php` (existant)
- `T-B2.3.3` **Minuit / bascule de journée** — pas de perte ni de double comptage à la bascule (Europe/Paris).
  • test : (À CRÉER : `tests/Feature/Stock/StockMidnightRolloverTest.php`)

---

## §5 — B3 · RAPPORTS & PILOTAGE

### Contrat
L'exploitant doit pouvoir répondre seul à : qu'ai-je vendu, à quel coût, où fuit l'argent, quoi commander demain.

### Sub 3.1 — Assainissement des rapports
**Ancres** : `SalesReportController.php` · `ItemsReportController.php` · `AnalyticController.php` · `AnalyticSectionController.php` · `CreditBalanceReportController.php`
- `T-B3.1.1` **Anomalie `tests/Feature/Report/` ET `tests/Feature/Reports/`** — deux dossiers coexistent. Diagnostiquer, fusionner, et corriger `SYSTEM_MAP.md` si la répartition en dépend. Succès = un seul dossier canonique, 0 test perdu (compte avant = compte après).
  • ancre : `tests/Feature/Report/` · `tests/Feature/Reports/`
  • test : (À CRÉER : `tests/Feature/Reports/ReportSuiteSingleCanonicalDirSentinelTest.php` — échoue si le dossier doublon réapparaît)
- `T-B3.1.2` **Chaque rapport déclare sa source et sa fenêtre** — et deux rapports qui comptent la même chose s'accordent. Succès = 0 divergence inter-rapports sur un même jour.
  • test : (À CRÉER : `tests/Feature/Reports/ReportCrossConsistencyTest.php`)
- `T-B3.1.3` **Export réellement produit** — CSV/PDF ouvrent, sont complets et respectent le filtre courant. Succès = fichier ouvert et compté, jamais « 200 donc OK ».
  • test : (À CRÉER : `tests/Feature/Reports/ReportExportContentTest.php`)
- `T-B3.1.4` **Aucun coût inventé** — `items` n'a **aucun prix d'achat** (fait vérifié 2026-08-10). Interdit d'afficher un « coût » ; dire « valeur offerte / prix de vente ». Succès = 0 libellé « coût » sans donnée d'achat réelle derrière.
  • test : (À CRÉER : `tests/Feature/Reports/NoFabricatedCostLabelSentinelTest.php`)

### Sub 3.2 — Pilotage et santé (converge avec chantier A `GLOB-OPS-19`)
- `T-B3.2.1` **Tableau de santé lisible par un humain** — fraîcheur inbox, plus vieil outbox, battement scheduler/janitor, 429, lettres mortes print/stock. Une erreur de sonde = `UNKNOWN/RED`, jamais vert.
  • test : (À CRÉER : `tests/Feature/Observability/HealthProbeErrorIsNotGreenTest.php`)
- `T-B3.2.2` **Une seule commande critique en souffrance suffit à alerter.** Succès = alerte levée à N=1.
  • test : (À CRÉER : `tests/Feature/Observability/SingleCriticalOrderPagesTest.php`)

### Sub 3.3 — Clôture fiscale et livre de caisse
**Ancres** : `app/Http/Controllers/Admin/Fiscal/{XReportController,ZReportController}.php` · `app/Services/Fiscal/{XReportService,ZReportService,ZReportCashEnrichmentService,FiscalChainValidator}.php` · `CashSessionReportController.php` · `tests/Feature/DailyBook/` (3 fichiers)
> ⚠️ `ZReportService.php` + `AuditLogService.php` + `FiscalSequenceService.php` sont **GELÉS** (`CLAUDE.md §7`). Lecture, tests et sentinelles autorisés ; **toute** modification passe par `lock-plan` + gate owner. `FiscalChainValidator.php` et `XReportService.php` ne figurent pas dans la liste gelée — **à confirmer avant toute édition** (`T-B3.3.1`).
- `T-B3.3.1` **Statut gelé/non-gelé tranché pour les 7 services fiscaux** — la liste `CLAUDE.md §7` en nomme 3 ; le dossier en contient 7. Statuer et écrire la règle. Succès = 7 fichiers classés, `CLAUDE.md §7` mis à jour si nécessaire (documentation seule, autorisée).
  • test : (À CRÉER : `tests/Feature/Sentinels/FiscalServiceFrozenListCoverageSentinelTest.php`)
- `T-B3.3.2` **X et Z lisibles et exacts** — le rapport de clôture réconcilie encaissements, tiroir et ventes ; un écart s'affiche comme écart, jamais absorbé en silence. Succès = X/Z d'une journée réelle = somme des ventes au centime.
  • test : `tests/Feature/DailyBook/DailyBookSummaryTest.php` (existant) + (À CRÉER : `tests/Feature/Reports/ZReportReconcilesToSalesTest.php`)
- `T-B3.3.3` **Chaîne NF525 vérifiable à la demande depuis l'admin** — `php artisan fiscal:verify-chain` doit avoir un équivalent lisible par l'exploitant, avec verdict franc. Succès = altération simulée ⇒ verdict rouge explicite.
  • ancre : `FiscalChainValidator.php`
  • test : (À CRÉER : `tests/Feature/Reports/FiscalChainVerdictSurfacedToOperatorTest.php`)
  • ⚠️ leçon 2026-08-08 : une alarme fiscale « connue et gatée » depuis des semaines était un **faux positif** (rotation de secret). Le verdict doit être **recalculable**, pas mémorisé.

---

## §6 — B4 · RÉGLAGES · CATALOGUE · PERSONNALISATION

### Contrat
Tout ce qui définit *le produit vendu* et *les règles de la maison* doit être modifiable depuis l'interface, sans développeur.

### Frozen zones au contact — **strictes**
`public/js/pos-wizard.js` · `public/css/pos-wizard.css` · `resources/views/admin-pos-v4.blade.php` = **no-touch absolu**. Les 3 composants Vue kiosk = lecture + tests autorisés, **édition = LOCK + gate**. `PricingService.php` = gelé. Toute tâche B4 qui aboutit à « il faut modifier le wizard » **s'arrête** et passe par `lock-plan`.

### Sub 4.1 — Cartographie des 32 réglages
**Ancres** : 32 dossiers `resources/js/components/admin/settings/` (Branch, Company, Currency, Fiscal, ItemAttribute, ItemCategory, KioskMachine, KioskSetup, Language, License, LoyaltySetup, Mail, Notification, OrderSetup, Otp, Page, PaymentGateway, PaymentTerminals, Printers, Role, Site, Slider, SmsGateway, SocialMedia, Tax, analytics, Cookies…) · `routes/api.php:412` et `:1589`
- `T-B4.1.1` **Matrice des 32 réglages** — pour chacun : écran atteignable ? écriture persistée ? effet observable sur une surface ? Et **corriger `SYSTEM_MAP.md:95`** (contradiction C3). Succès = 32 lignes prouvées, carte système corrigée.
  • test : (À CRÉER : `tests/Feature/Settings/SettingsSurfaceMatrixTest.php`)
- `T-B4.1.2` **Un réglage écrit est un réglage appliqué** — pas de valeur enregistrée que rien ne lit. Succès = 0 réglage orphelin, ou orphelin explicitement documenté.
  • test : (À CRÉER : `tests/Feature/Settings/NoOrphanSettingSentinelTest.php`)
  • ⚠️ précédent : deux interrupteurs de remise faux par défaut et absents du `.env` → codes émis puis refusés (2026-08-10).
- `T-B4.1.3` **`idempotency.enabled` reste hors interface** — protection NF525, à ne pas défaire (décision consignée). Succès = sentinelle qui échoue si le drapeau devient éditable.
  • test : (À CRÉER : `tests/Feature/Settings/IdempotencyFlagNotUserEditableSentinelTest.php`)

### Sub 4.2 — Catalogue, wizards, personnalisation
**Ancres** : `ItemController.php` · `config/menu.php` · `config/catalog_v15.php` · `config/product.php` · `tests/Feature/Catalog/` (26) · `tests/Feature/Items/` · `tests/Feature/Menu/`
- `T-B4.2.1` **Créer un produit complet depuis l'admin** — variations, extras, ingrédients, disponibilité, photo — et le voir arriver aux 4 surfaces. Succès = parcours complet sans SQL manuel.
  • test : (À CRÉER : `tests/Feature/Catalog/CreateFullItemFromAdminE2ETest.php`)
- `T-B4.2.2` **Règles de composition éditables** — min/max, obligatoire, inclus, gratuit, plafonné. Succès = une règle changée en admin change le prix calculé **backend**, jamais côté écran.
  • ancre : `app/Services/Pricing/PricingService.php` (**GELÉ — lecture seule**) · profils composer
  • test : (À CRÉER : `tests/Feature/Catalog/CompositionRuleEditChangesBackendPriceTest.php`)
- `T-B4.2.3` **Parité borne ↔ caisse ↔ web d'un même produit** — un menu est un **addon** à la borne, une **ligne dédiée** à la caisse, du **texte libre** sur les bols : n'en lire qu'un donne un résultat faux (fait vérifié 2026-08-10). Succès = les 3 lectures s'accordent.
  • test : (À CRÉER : `tests/Feature/Catalog/MenuRepresentationParityAcrossSurfacesTest.php`)
- `T-B4.2.4` **Aucun produit inventé** — sentinelle : tout produit cité par un test/fixture existe dans `items`. Succès = 0 produit fantôme.
  • test : (À CRÉER : `tests/Feature/Catalog/NoPhantomProductFixtureSentinelTest.php`)

### Sub 4.3 — Utilisateurs, rôles et permissions
**Ancres** : `RoleController.php` · `PermissionController.php` · `AdministratorController.php` · `EmployeeController.php` · `ChefController.php` · `WaiterController.php` · `CustomerController.php` · `DeliveryBoyController.php` · `resources/js/components/admin/settings/Role/`
- `T-B4.3.1` **Créer un rôle et voir son effet réel** — un rôle défini en admin change ce que l'utilisateur voit **et** ce que le serveur autorise. Succès = interface et serveur d'accord, sur toute la matrice écran × rôle de `T-B1.1.2`.
  • test : (À CRÉER : `tests/Feature/Authz/RoleEditChangesServerAuthorizationTest.php`)
  • ⚠️ piège vérifié : `$user->can()` sur la garde **`web`** ne trouve rien — les permissions sont enregistrées sous **`sanctum`**. Une garde qui a l'air de marcher et ne fait rien.
- `T-B4.3.2` **Aucune montée de privilège par l'écran** — un rôle ne peut jamais s'octroyer une permission qu'il n'a pas, ni éditer un compte de rang supérieur. Succès = tentative refusée **côté serveur**, tracée.
  • test : (À CRÉER : `tests/Feature/Authz/NoPrivilegeEscalationViaRoleEditorTest.php`)
- `T-B4.3.3` **Dérive d'autorisation des FormRequests** — la sentinelle existante plafonne les `return true;`. Baseline **69**, réel mesuré **66** : resserrer le plafond à 66 pour cliqueter le cran. Succès = plafond abaissé, suite verte.
  • ancre : `tests/Feature/Sentinels/` (sentinelle `FormRequestAuthzDrift`) · `CLAUDE.md §9`
  • test : sentinelle existante — **abaisser `RETURN_TRUE_BASELINE` à 66**

---

## §7 — B5 · SWAP MULTI-MARQUE + PILOTAGE IA

> **Exécution derrière la porte G3** (§G). Planifié maintenant, exécuté sur amendement constitutionnel signé. Périmètre : **paramétrage**, pas multi-tenant (cf. C2).

### Contrat
Une seule consigne (« voici le menu et les règles de la marque X ») doit suffire à une IA pour reconfigurer catalogue, règles, prix, personnalisations, site et gestion — **sans faute, sans invention, et sans jamais toucher aux invariants fiscaux**.

### Sub 5.1 — Sortir la marque du code
**Ancres** : **129 fichiers** citent « cayenne » (dont `app/Services/DashboardService.php`, `KitchenDisplaySystemOrderService.php`, `Hardware/OrderReceiptEscPosRenderer.php`, `Hardware/KitchenTicketSymbolicFormatter.php`, `Kitchen/MeatPortionCalculator.php`, `Menu/AvailabilityService.php`, `Promo/PromoFlyerService.php`) · `app/Console/Commands/MenuResetLeCayenneCommand.php` · table `settings`
- `T-B5.1.1` **Inventaire des 129 points en dur**, classés : `MARQUE` (nom, palette, mentions légales) · `RECETTE` (portions viande, symboles cuisine) · `RÈGLE MÉTIER` (disponibilité, promo) · `FAUX POSITIF` (commentaire, test). Succès = 129 lignes classées, aucune non classée.
  • test : (À CRÉER : `tests/Feature/Branding/NoHardcodedBrandInProductCodeSentinelTest.php` — baseline-lock : le compte **ne peut que baisser**)
- `T-B5.1.2` **Profil de marque en donnée** — schéma unique (identité, palette, locale, TVA, horaires, canaux, règles de composition, barème fidélité). Succès = `Le Cayenne` devient **un profil parmi d'autres**, comportement identique à l'octet près.
  • ancre : table `settings` · `config/*.php` (46 fichiers — arbitrer config vs donnée)
  • test : (À CRÉER : `tests/Feature/Branding/BrandProfileRoundTripTest.php`)
  • ⚠️ **gate schéma requis** si migration.
- `T-B5.1.3` **Recettes et symboles cuisine paramétrables** — `MeatPortionCalculator`, `KitchenTicketSymbolicFormatter`, `MeatMaterialResolver` lisent la donnée, pas des constantes. Succès = changer une recette en admin change le bandeau CUISSON et le ticket.
  • test : (À CRÉER : `tests/Feature/Branding/RecipeRulesAreDataDrivenTest.php`)
  • ⚠️ jumeau : parité PHP ↔ JS déjà outillée (`tests/Feature/Kitchen/CuissonPhpJsParityFixtureTest.php`, `tests/fixtures/cuisson/parity_cases.json`) — l'étendre, pas la contourner.

### Sub 5.2 — Le contrat d'importation IA
- `T-B5.2.1` **Schéma d'entrée strict et validé** — menu + règles en format déclaré, refus explicite sur écart. Succès = entrée invalide = refus lisible, **jamais** une importation partielle silencieuse.
  • test : (À CRÉER : `tests/Feature/Branding/BrandImportRejectsInvalidPayloadTest.php`)
- `T-B5.2.2` **Importation transactionnelle et réversible** — tout ou rien, avec instantané de retour arrière. Succès = échec au milieu ⇒ base inchangée.
  • test : (À CRÉER : `tests/Feature/Branding/BrandImportIsAtomicAndReversibleTest.php`)
- `T-B5.2.3` **Simulation avant application** — l'IA voit ce qui va changer (créations/modifs/suppressions, impact prix) avant d'écrire. Succès = simulation = résultat réel, au produit près.
  • test : (À CRÉER : `tests/Feature/Branding/BrandImportDryRunMatchesApplyTest.php`)
- `T-B5.2.4` **Frontières inviolables** — l'importation ne peut **jamais** écrire dans `audit_logs`, `z_reports`, la séquence fiscale, ni contourner `PricingService`. Succès = tentative = refus + trace.
  • test : (À CRÉER : `tests/Feature/Branding/BrandImportCannotTouchFiscalChainSentinelTest.php`)
  • ⚠️ **NF525 : non négociable.** Cette tâche est la garde-barrière de tout B5.

### Sub 5.3 — Preuve du swap
- `T-B5.3.1` **Marque témoin de bout en bout** — une marque fictive complète (≠ Le Cayenne : autre carte, autres règles, autre TVA, autre barème) importée par une seule consigne, puis commande réelle jouée borne → caisse → KDS → ticket → Z. Succès = parcours vert **sans** retouche de code.
  • test : (À CRÉER : `tests/Feature/Branding/SecondBrandFullOrderE2ETest.php`)
- `T-B5.3.2` **Retour à Le Cayenne sans perte** — restauration du profil d'origine, différence nulle. Succès = comparaison avant/après = 0 écart.
  • test : (À CRÉER : `tests/Feature/Branding/BrandSwapRoundTripNoDriftTest.php`)

---

## §A — ARMÉE D'AGENTS

### Rôles

| Rôle | Type | Outils | Mission |
|---|---|---|---|
| Architecte | `Plan` | lecture | cohérence des couches, frontières, dette structurelle |
| Sécurité | `general-purpose` | lecture | auth, permissions, isolation branche, secrets |
| DBA | `general-purpose` | lecture | schéma, index, N+1, `BranchScope` sur 24 modèles |
| SRE / Synchro | `general-purpose` | lecture | outbox, WS, polling, files, 429 |
| UX / A11y | `general-purpose` | lecture + navigateur | WCAG 2.1, clavier, focus, états vide/erreur/chargement |
| Implémenteur | session principale | édition | **jamais deux en parallèle** |
| RED-team | `general-purpose` | lecture | réfuter les constats et les correctifs |
| QA visuel | `general-purpose` | navigateur | capturer + analyser |
| RED visuel | `general-purpose` | lecture | ré-analyser les captures **indépendamment**, contester |

### Matrice de déclenchement

| Type de tâche | Arch | Séc | UX | DBA | SRE | Impl | RED | QA-V | RED-V |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Écran admin / navigation | x | x | x | . | . | x | x | x | x |
| Rapport / agrégat | x | . | . | x | . | x | x | x | . |
| Stock / saga | x | x | . | x | x | x | x | . | . |
| Réglage / catalogue | x | x | x | x | . | x | x | x | x |
| Multi-marque / import IA | x | x | . | x | . | x | x | . | . |
| Fiscal-adjacent | x | x | . | x | . | x | x | . | . |
| E2E inter-surfaces | x | x | x | x | x | x | x | x | x |

### Discipline
- Les spécialistes **lecture seule** partent en **un seul message**, en parallèle.
- **Un seul implémenteur** à la fois.
- **QA visuel + RED visuel toujours en parallèle** — un seul œil produit du biais de confirmation.
- **RED-team après le correctif, avant toute déclaration de fait.**
- Chaque agent **écrit sur disque** : `reports/goal-ops-swap-2026-08-12/<vague>/<role>.md`. La synthèse se fait **depuis le disque**, jamais depuis le contexte — c'est ce qui survit à une coupure de limite d'usage.
- **Anti-hallucination (CLAUDE.md §3ter)** : tout constat P0/P1 d'un agent sans `file:line` vérifié + reproduction est **rejeté**, jamais remonté à l'owner.
- Plafond ~1200-1500 mots par agent.

---

## §X — VAGUES DE CONVERGENCE

**Parallélisme** : séquentiel par défaut. Deux vagues en parallèle **uniquement** si contrôleurs, front et tests sont disjoints. **Jamais** de fenêtre concurrente sur paiement, impression ou stock. Le fan-out parallèle est autorisé **à l'intérieur** d'une vague, en phase d'audit lecture seule.

| Vague | Contenu | Parallélisme | Sortie |
|---|---|---|---|
| **W0** | Préflight : `verify:boucle`, `agent-activity-log tail 50`, attribution des 122 fichiers sales, gates, sauvegarde, baselines (compte PHPUnit, `audit_logs` count + dernier hash) | séquentiel | registre de collisions + décision §0.1 |
| **W1** | **Cartographie runtime** back-office (B1.1) + inventaire 32 réglages (B4.1.1) + inventaire 129 points marque (B5.1.1) — **lecture seule** | fan-out 5 agents | **matrice d'état** — dimensionne W3-W5 |
| **W2** | Chantier A — confinements P0 (`GLOB-OPS-01`→`06`) : CB POS manuelle, borne fail-closed, KDS WS, CSP/rate, tiroir, impression | séquentiel strict | 6 confinements + tests fail-first |
| **W3** | B1 — centrale, navigation, permissions, tableau de bord, listes | séquentiel | écrans classés → fonctionnels |
| **W4** | B2 stock (+ B3.2 santé) | séquentiel | convergence stock prouvée |
| **W5** | B3 rapports + B4 réglages/catalogue | 2 lots parallèles **si** disjoints après W1 | rapports justes, réglages appliqués |
| **W6** | Chantier A — durables (`GLOB-OPS-07`→`22`) : Inbox, attention, ledger paiement, print jobs, saga stock, santé, historique, mobile, Uber | séquentiel, sous-gates | RQ-01..RQ-18 vers `PROVEN` |
| **W7** | B5 swap multi-marque + import IA — **derrière G3** | séquentiel | marque témoin E2E verte |
| **W8** | Qualification : E2E naturel, chaos, hardware UAT, canary, dossier GO | séquentiel | paquet de décision owner |

### Checkpoint de fin de vague — les 6 points
1. Toutes les tâches en `PASS`, ou échec connu **documenté avec sa raison**.
2. Diff frozen-zone sur l'intervalle = **0 ligne** (`git diff --stat <début>..HEAD -- <liste §7 CLAUDE.md>`).
3. Chaîne NF525 attestée si la vague a touché le fiscal : `SELECT count(*), MAX(current_hash) FROM audit_logs` — inchangé ou **append-only**.
4. Gate visuel déclenché pour toute tâche front : captures **lues et analysées**, pas seulement prises.
5. Contestation RED terminée ; tout P0/P1 neuf soigné ou différé **avec motif écrit**.
6. `PROJECT_BRAIN.md` §2/§3 à jour avec le résumé de vague + les commits.

Un « non » ⇒ **la vague n'est pas close**. On soigne ou on documente ; on n'avance pas.

### Protocole d'interruption (limite d'usage, coupure, pause owner)
1. **Committer le partiel** — `wip(<vague>): partiel jusqu'à T-X.Y.Z`. Un WIP vaut mieux qu'un état perdu.
2. Écrire `reports/goal-ops-swap-2026-08-12/INTERRUPT_<vague>_<horodatage>.md` : dernier SHA vert · dernière tâche + état · tâche suivante en file · rapports d'agents déjà sur disque.
3. `PROJECT_BRAIN.md §2` reçoit l'état d'interruption.
4. **À la reprise** : lire le manifeste, `git status` + dernier commit, **re-jouer la dernière tâche en fumée** (ne jamais croire un vert périmé), puis continuer.

### Protocole de non-convergence (3 boucles de soin sans succès)
**STOP** — pas de 4ᵉ boucle silencieuse. Un agent `Plan` analyse la cause, écrit `reports/goal-ops-swap-2026-08-12/STUCK_<vague>_<horodatage>.md`, et je remonte à l'owner **4 options** : (A) accepter avec documentation · (B) pivot d'architecture · (C) reporter en V1.0.X · (D) gate humain. **Je ne choisis pas à sa place.**

---

## §G — PORTES OWNER

| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G1** | Décision arbre de travail : que fait-on des 122 fichiers sales non attribués ? | Owner | arbitrage écrit (inclure / geler / autre session) | registre W0 + `PROJECT_BRAIN.md §2` | **PENDING — bloque W2+** |
| **G2** | Inventaire matériel : imprimante caisse, tiroir, câble/port, OS, bridge, nom spooler — **photos des ports et étiquettes** | Owner physique | photos + modèles + n° de série | `reports/hardware/` | **PENDING — bloque W8 (pas W1-W7)** |
| **G3** | **Amendement constitutionnel** : `CONSTITUTION.md §1` dit « PAS un SaaS ». Le swap multi-marque le contredit. Confirmation d'une ligne requise. | Owner | ligne de décision + mise à jour `CONSTITUTION.md` | `docs/gates/GATE_LOG.md` | **PENDING — bloque W7 uniquement** |
| **G4** | Gate schéma pour toute migration (ledger paiement, print jobs, attention, profil marque) | Owner | sous-gate par migration + répétition/rollback | `docs/gates/` | **PENDING par migration** |
| **G5** | Frozen-zone : toute tâche aboutissant à modifier le wizard POS / composants kiosk / `PricingService` | Owner | document LOCK contresigné (`lock-plan`) | `docs/locks/` | **conditionnel** |
| **G6** | Recette matérielle signée (TPE manuel externe, imprimantes, tiroir, borne, KDS, réseau) | Owner physique + ops | grille signée ; tests « TPE intégré » marqués **N/A** — il n'existe aucune intégration | `reports/hardware/` | **PENDING — bloque le GO** |
| **G7** | **GO commercial** — jamais pris par moi | Owner | décision explicite après double PASS + G6 | `GATE_LOG.md` | **PENDING** |
| **G8** | Toute poussée / déploiement | Owner | « go push » explicite | message de chat | **PENDING** |

**Règle d'attente** : une porte `PENDING` bloque **seulement** les vagues qui en dépendent. W1 démarre immédiatement (lecture seule, aucune porte). G3 ne retarde ni W2, ni W3-W6.

---

## §R — RÉFÉRENCES

**Chantier A** (§2 les détaille) : `reports/planning/PLAN_GLOBAL_OPS_CLAUDE_ORCHESTRATION_2026-08-12.md` · `reports/audit/{AUDIT_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11,ADVERSARIAL_DECISION_RECORD_GLOBAL_OPS_2026-08-12}.md` · `reports/qa/GLOBAL_OPS_REQUIREMENTS_TRACEABILITY_2026-08-12.md` · `docs/gates/GATE_GLOBAL_OPERATIONS_RELIABILITY_2026-08-11.md` · `docs/architecture/OPERATOR_INBOX_ATTENTION_CONTRACT_PROPOSAL_2026-08-12.md` · 10 × `tasks/TASK_GLOB_OPS_*.md` · `missions/GLOBAL-OPS-RELIABILITY-OWNER-APPROVED-2026-08-12/`

**Projet** : `CONSTITUTION.md` · `PROJECT_BRAIN.md §2` · `SYSTEM_MAP.md` (⚠️ `:95` erroné, cf. C3) · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md` · `CLAUDE.md` §7 §8 §9

**Pipelines** : `ultra-audit-profond` · `test-e2e` · `lock-plan` · `superpower-gstack` · `checkpoint-commit`

**Outillage vérifié** : `npm run verify:boucle` (`package.json:32`) · `validate:active-cycle` (`:34`) · `scripts/agent-activity-log.sh {tail|start|done|collisions}` · dev `http://127.0.0.1:8000` (HTTP 200)

---

## §F — RÈGLE FINALE

Ce GOAL est terminé quand, **et seulement quand** :

1. Les **18 exigences** RQ-01..RQ-18 du chantier A sont `PROVEN` — sur preuve terrain, pas sur test unitaire vert.
2. **Chaque écran** du back-office est classé et, s'il devait fonctionner, **fonctionne** — avec sa preuve.
3. Le stock dit la même chose sur **les quatre surfaces**, et converge après annulation, remboursement et rejeu.
4. Les rapports **s'accordent entre eux** et n'affichent **aucun chiffre fabriqué**.
5. Les 32 réglages sont **appliqués** — aucun réglage écrit que rien ne lit.
6. *(derrière G3)* Une **seconde marque** vit de bout en bout depuis une seule consigne, et le retour à Le Cayenne ne laisse **aucun écart**.
7. Toutes les portes owner sont **fermées explicitement** — jamais contournées.

**Ce qui ne vaut pas « terminé »** : un test vert (il peut être vide — d'où le test-mutant) · un HTTP 200 (la page peut être vide) · un 202 (le papier n'est pas sorti) · un `git push` (le contenu servi peut être l'ancien) · « ça devrait marcher ».

**Production-parfait ou bloqué. Jamais « presque ».**

**GOAL_VERDICT: PRÊT À LANCER — W1 EXÉCUTABLE IMMÉDIATEMENT (lecture seule, aucune porte) · W2+ SUSPENDU À G1**
