# GOAL — Dashboard Admin · Test E2E Profond (technique + visuel, page-par-page, bouton-par-bouton)
**Date:** 2026-06-08 · **Branch cible:** `heal/cms-pr1-quickwins-2026-05-18` · **Statut:** PLAN ONLY — à lancer via « lance le GOAL ».
**Spine inventory (provable):** `plans/PAGE_INVENTORY_DASHBOARD_2026-06-08.md` (~58 pages V1-actives + ~12 hidden render-smoke).
**Per-task pipeline:** `~/.claude/skills/ultra-audit-profond/` (14-step) — NON ré-décrit ici. Dual visual loop: `~/.claude/skills/test-e2e/`.

---

## §0 — PRÉAMBULE (lire avant toute chose)

### §0.1 Objectif (verbatim owner, reformulé)
Tester **TOUT le dashboard admin** en profondeur — chaque page, chaque sous-page, **chaque bouton réellement exercé**, chaque redirection (sidebar **et** drill-down intra-page : ligne→détail, onglet→sous-vue, modal), chaque fonctionnalité — **du point de vue du responsable/gérant** qui lit et pilote le dashboard. Pour chaque page : **capture analysée** (visuel) + **vérif technique** + **proposition d'amélioration**. Pas la surface : la profondeur.

### §0.2 ⚠️ DEUX DÉCISIONS OWNER (défauts proposés — à valider à la revue du plan, AVANT « lance le GOAL »)
| # | Décision | Défaut recommandé | Pourquoi |
|---|---|---|---|
| D-1 | **Audit-seul vs Audit+Heal** | **Audit/capture/propose maintenant ; heal = piste séparée, gated, page-par-page déclenchée par l'owner** | « Améliorer » implique des heals, mais healer ~58 pages à l'aveugle est énorme et la plupart des améliorations UX sont des choix owner. Garde la discipline frozen-zone. |
| D-2 | **Profondeur POS-wizard / KDS / OSS** | **Deep-audit des surfaces *management/dashboard* uniquement ; POS-wizard (FROZEN) + KDS + OSS = render-smoke + renvoi à leurs audits séparés** | « TOUTES les pages » ; mais le wizard POS est strict-no-touch (§7) et KDS/OSS déjà audités le 2026-06-08. Ne pas ré-auditer les internes frozen. |

> Si l'owner veut healer en direct (D-1=heal) ou deep-auditer le wizard/KDS/OSS (D-2=deep), il le dit à la revue ; sinon ces défauts s'appliquent.

### §0.3 🔴 DB-SAFETY (BLOQUANT — un bouton cliqué = une écriture)
Auditer « tous les boutons » = **cliquer des boutons = écrire**. Donc :
- **TOUTES les actions mutantes (clic save/delete/toggle/confirm/open-Z/etc.) → UNIQUEMENT sur `:8766` (`foodking_e2e`, clone jetable).** JAMAIS sur `:8765`/`:8000` (= `foodking` OPÉRATIONNEL → chaîne NF525 réelle). Lecture seule (juste afficher) tolérée sur `:8765`.
- **`:8766` partage les creds providers avec l'opérationnel** (memory) → voir taxonomie §0.4 classe **EXTERNAL** : SMS/mail/push/webhook **JAMAIS live-fire**, même sur le clone.
- **Snapshot + reseed `foodking_e2e` au pré-flight ET à chaque frontière de wave** (sinon une suppression en wave N fait paraître « cassées » les pages dépendantes en wave N+1 → findings fictifs). Cmd pré-flight : `mysqldump foodking_e2e > reports/.../snap_e2e_<wave>.sql` ; restore au boundary. Ordre d'exécution **intra-cluster : read-only d'abord, destructif en dernier**.
- ⛔ Jamais `php artisan test`/`migrate*`/`config:cache`/`dump-autoload` (footguns documentés).

### §0.4 BUTTON TAXONOMY (colonne vertébrale sécurité — classer CHAQUE élément interactif avant de cliquer)
| Classe | Définition | Protocole |
|---|---|---|
| **READ-ONLY** | navigation, filtre, onglet, tri, pagination, ouverture modal sans submit, export-download | cliquer librement, vérifier le résultat |
| **REVERSIBLE-MUTATION** | toggle, save form, create simple | cliquer → vérifier → **restaurer** (ou compter sur le reseed boundary) |
| **DESTRUCTIVE** | delete/purge/clôture-Z/annulation | **clone-only**, exécuter **en dernier**, reseed après |
| **EXTERNAL-SIDE-EFFECT** | envoyer SMS/mail/push, notifier abonnés, webhook sortant, rotation secret | **NE JAMAIS live-fire** — vérifier le câblage en **statique** (route+controller@method+job dispatché) OU mock ; capture du formulaire OK, **pas** de submit réel |
> Le mapping élément→classe est une **obligation d'exécution** (DOM-snapshot puis classification), PAS une liste pré-cuite ici (anti-fiction + budget).

### §0.5 DEPTH CONTRACT (appliqué à CHAQUE page — défini une fois, référencé par toutes les tâches)
Pour chaque page auditée, le livrable est **7 blocs** :
1. **Inventory** — DOM-snapshot : énumérer TOUS les éléments interactifs (boutons, liens, onglets, filtres, toggles, row-actions, pagination, modals, dropdowns, submits). Classer chacun par taxonomie §0.4.
2. **Function** — par élément : ce qu'il fait + endpoint backend réel (`route → Controller@method`, anchor vérifié). Marquer tout élément **sans handler / sans effet** = finding « dead control ».
3. **Redirect map** — chaque cible de navigation (sidebar child **et** drill-down intra-page : row→`show/:id`, onglet→sous-vue, modal-flow) → **confirmer qu'elle rend** (pas de 404/blank/dead-link). Boucle retour OK.
4. **Technical** — console 0 erreur ; réseau 2xx/attendu ; gating RBAC correct ; états empty/loading/error cohérents ; i18n FR résolu (0 raw-label).
5. **Visual** — capture (`:8766`) **+ analyse via Read** : layout (responsive), raw-label/`0undefined`, branding Cayenne, contraste/a11y visible, débordement.
6. **Persona verdict (responsable/gérant)** — *en tant que gérant* : le chiffre est-il **fiable + decision-ready** ? les bonnes actions sont-elles **atteignables** ? quelle **friction** ? → **proposition d'amélioration concrète** (load-bearing, cf. §0.1 « améliorer chaque page »).
7. **Evidence** — chaque finding : `[P0-P3] file:line — titre — repro (click-path/cmd) — screenshot path`. 0 silent cap.

### §0.6 PERSONA LENS (le fil rouge)
Le gérant Le Cayenne ouvre le dashboard pour : lire les chiffres du jour (CA, commandes, ticket moyen), vérifier la caisse/encaissement, piloter le catalogue/stock, lire les rapports, gérer le staff, configurer. Chaque page est jugée **« est-ce qu'il comprend, fait-il confiance, peut-il agir ? »** — pas seulement « ça s'affiche ».

### §0.7 Working-tree decision
Audit read-only + captures + reports. **Aucun fix** (sauf D-1=heal validé). Reports → `reports/test-e2e/dashboard-deep-2026-06-08/`. Pré-flight : `backup/pre-dashboard-deep-2026-06-08` branch + snapshot `foodking_e2e`.

### §0.8 DONE = COVERAGE-COMPLETE + VERIFIED (PAS heal-convergence — c'est un audit)
Voir §F. Un audit **rapporte**, ne « converge » pas vers 0-finding. DONE = 100% de l'inventaire couvert avec les 7 blocs du DEPTH CONTRACT, chaque finding tracé file:line+repro, 0 silent cap.

---

## §1 — MAP PRINCIPAL : le dashboard en 10 clusters (= « systèmes » de ce GOAL)
Détail pages → `PAGE_INVENTORY_DASHBOARD_2026-06-08.md`. Anchors **vérifiés 2026-06-08** (`ls/find/grep` live branch).

| Cluster | Maturité | Anchor (vérifié) | Tests existants |
|---|---|---|---|
| C1 Pilotage/Dashboard | mûr | `Admin/DashboardController`+`DashboardService` ; `components/admin/dashboard/` | `tests/Feature/Dashboard/` (8 : RevenueNetting, TotalOrders×2, EodPdf, SalesSummary, AuditTrail, ChannelStats, BranchScopeMatrix) ✅ |
| C2 Catalogue | mûr | `Admin/ItemController` ; `components/admin/items/`,`ingredients/`,`settings/ItemAttribute|ItemCategory/` | `tests/Feature/Catalog/`, `Ingredients/`, `ItemAttribute*Test.php`, `ItemResourceAllergensTest` ✅ |
| C3 Stock | mûr (backend 90%) | `components/admin/stock/` ; AvailabilityService | `tests/Feature/Availability/` ✅ |
| C4 Commandes | mûr | `Admin/PosOrderController`,`OrderHistoryController` ; `posOrders/`,`orderHistory/` | `tests/Feature/` order* (DeliveryBoyOrderStatusOrdering, ConcurrentOrder) ✅ |
| C5 Caisse/Encaissement | mûr | `Admin/CashOverviewController`,`CashSessionReportController` ; `encaissement/`,`cashOverview/` | `tests/Feature/Cash/` ✅ |
| C6 Rapports/Analytics | mûr | `Admin/SalesReportController`,`ItemsReportController`,`TransactionController`,`Observability/` | `tests/Feature/Analytics/`, `Dashboard/SalesSummary*` ✅ |
| C7 Users/RBAC | mûr | `Admin/AdministratorController`,`EmployeeController`,`ChefController` ; `settings/Role/` | `tests/Feature/Admin/*AuthzSentinel*`, `FormRequestAuthzDriftSentinelTest` ✅ |
| C8 Communications | mûr ⚠EXTERNAL | `Admin/MessageController`,`PushNotificationController`,`SubscriberController` ; `SendFcmNotificationJob` | (push test TO BE CREATED at `tests/Feature/PushNotification/PushNoLiveFireSentinelTest.php`) |
| C9 Réglages (~27 sous-pages) | mûr ⚠EXTERNAL+destructif | `settingRoutes.js` ; `components/admin/settings/*` (28 dirs) | `tests/Feature/Settings/SettingsUpdatedBroadcastTest`, `Configuration/` ✅ |
| C10 Profil/Shell nav | mûr | `layouts/backend/BackendMenuComponent.vue` ; `Admin/ProfileController` | `tests/Feature/Admin/MgmtReadAuthzGateSentinelTest` ✅ ; (nav-render test TO BE CREATED at `tests/Feature/Nav/SidebarLinksRenderSentinelTest.php`) |

## §2 — HORS SCOPE (flag explicite, anti-silent-cap)
- **POS wizard** (`pos/`, `public/js/pos-wizard.js` FROZEN) → render-smoke seulement (D-2 défaut).
- **KDS / OSS** (`kitchenDisplaySystem/`, `orderStatusScreen/`) → render-smoke + renvoi audit 2026-06-08 (D-2 défaut).
- **Storefront client + standalone web/mobile** → autres lanes, hors dashboard.
- **9 modules V1-hidden** (customers/coupons/offers/creditBalanceReport/deliveryBoys/onlineOrders/tableOrders/waiters/diningTables) → render-smoke (vérifier qu'ils restent bien cachés du gérant), pas de deep-audit.

---

## §3..§12 — DÉCOMPOSITION PAR CLUSTER
> Chaque tâche T-x = « audit page P selon le DEPTH CONTRACT §0.5 ». Acceptance = bloc-7 livré + test path. Anchors = inventory appendix. Surfaces visuelles sur `:8766`.

### §3 — C1 Pilotage/Dashboard `(Wave 2)`
- **T-1.1** `/admin/dashboard` — DEPTH CONTRACT complet. Focus persona : les KPI (CA jour, commandes, ticket moyen, 45 articles) sont-ils justes (netting remises/refunds) et lisibles ? Audit-trail NF525 affiché=signé ? Accès-rapides chips → chaque cible rend ?
  • anchor: `app/Http/Controllers/Admin/DashboardController.php` + `DashboardService.php`
  • test: `tests/Feature/Dashboard/DashboardRevenueNettingSentinelTest.php` + `TotalOrdersRealVolumeSentinelTest.php` + `EodPdfRecapSentinelTest.php` (✅ existants)
  • visual: `http://127.0.0.1:8766/admin/dashboard` + chaque accès-rapide
  • depth-spécifique : "PDF Clôture du jour" (READ-ONLY download), 12 chips accès-rapides (REDIRECT map), SLA alert rows → order detail.

### §4 — C2 Catalogue `(Wave 3)`
- **T-2.1** `/admin/items` (liste + 4 onglets) — boutons Ajouter/Filtrer/Exporter(RO)/Importer(REVERSIBLE)/row view-edit-delete(DESTRUCTIVE)/pagination ; onglets Produits/Catégories/Offres/Disponibilités (REDIRECT intra-page).
  • anchor: `app/Http/Controllers/Admin/ItemController.php` • test: `tests/Feature/Catalog/` ✅ • visual: `/admin/items`
- **T-2.2** `/admin/items/studio` (Catalog Studio) — composer item, variations/extras/supplements, allergens, save/publish (REVERSIBLE).
  • anchor: `ItemController`/CatalogStudio • test: `tests/Feature/ItemExtraManagementTest.php`, `ItemResourceAllergensTest.php` ✅ • visual: `/admin/items/studio`
- **T-2.3** `/admin/ingredients` — list + CRUD.
  • anchor: `Admin/IngredientController.php` • test: `tests/Feature/Ingredients/` ✅ • visual: `/admin/ingredients`
- **T-2.4** `/admin/settings/item-attributes/list` + drill `show/:id` • test: `tests/Feature/ItemAttributeComposerResourceTest.php` ✅
- **T-2.5** `/admin/settings/item-categories/list` + drill `show/:id` • test: (TO BE CREATED at `tests/Feature/Catalog/ItemCategoryListRenderTest.php`)

### §5 — C3 Stock `(Wave 3, parallèle-OK avec C2 ? NON — partage Item/Availability → séquentiel)`
- **T-3.1** `/admin/stock/rupture` — toggles dispo (REVERSIBLE clone-only), mark rupture/restock, filtres branche/délai. Persona : le gérant voit-il vite quoi est en rupture ?
  • anchor: AvailabilityService + `components/admin/stock/` • test: `tests/Feature/Availability/` ✅ • visual: `/admin/stock/rupture`
- **T-3.2** `/admin/stock` — levels + movements + adjust (REVERSIBLE).

### §6 — C4 Commandes `(Wave 4)`
- **T-4.1** `/admin/pos-orders` — row→detail (REDIRECT), filters, reprint(EXTERNAL→print job, static-verify), status.
  • anchor: `Admin/PosOrderController.php` • test: `tests/Feature/` order tests ✅ • visual: `/admin/pos-orders`
- **T-4.2** `/admin/historique` — date/channel filters, export(RO), row→detail, reprint receipt(EXTERNAL static).
  • anchor: `Admin/OrderHistoryController.php` • test: (TO BE CREATED at `tests/Feature/OrderHistory/HistoriqueFilterRenderTest.php`)
- **T-4.3** `/admin/pos-orders-tracker` — live tracker, status drill.

### §7 — C5 Caisse/Encaissement `(Wave 4)`
- **T-5.1** `/admin/encaissement` — collect modal, méthode Espèces/TR/Terminal-manuel, confirm (REVERSIBLE, clone-only). Persona : flux d'encaissement clair ?
  • anchor: encaissement (perm pos-orders) • test: `tests/Feature/Cash/` ✅ • visual: `/admin/encaissement`
- **T-5.2** `/admin/cash-overview` — session cards→detail, open/close drawer (DESTRUCTIVE clone-only), movements.
  • anchor: `Admin/CashOverviewController.php` • test: `tests/Feature/Cash/` ✅
- **T-5.3** `/admin/cash-session-report` — session→report, Z-link, export.
  • anchor: `Admin/CashSessionReportController.php` • test: `tests/Feature/Cash/` ✅
- **T-5.4** `/admin/delivery-boy-cash-sessions` — session→detail, reconcile.

### §8 — C6 Rapports/Analytics `(Wave 5)`
- **T-6.1** `/admin/sales-report` — date range, channel, export(RO), chart drill. Persona : décision-ready ?
  • anchor: `Admin/SalesReportController.php` • test: `tests/Feature/Dashboard/SalesSummaryAvgPerDayDivisorSentinelTest.php` ✅
- **T-6.2** `/admin/items-report` • anchor: `Admin/ItemsReportController.php` • test: `tests/Feature/Analytics/` ✅
- **T-6.3** `/admin/transactions` — row→detail, filters, export • anchor: `Admin/TransactionController.php`
- **T-6.4** `/admin/observability` — SLI/SLO/outbox/health panels (perm-gated) • anchor: `Admin/Observability/*` • test: `tests/Feature/HealthControllerTest.php` ✅
- **T-6.5** `/admin/settings/analytics/list` + drill `show/:id`

### §9 — C7 Users/RBAC `(Wave 5)`
- **T-7.1** `/admin/administrators` — CRUD + role assign (REVERSIBLE clone-only). RBAC : un non-admin voit-il moins ?
  • anchor: `Admin/AdministratorController.php` • test: `tests/Feature/Admin/MgmtReadAuthzGateSentinelTest.php` ✅
- **T-7.2** `/admin/employees` • anchor: `Admin/EmployeeController.php`
- **T-7.3** `/admin/chefs` • anchor: `Admin/ChefController.php`
- **T-7.4** `/admin/settings/roles/list` + permission matrix drill • test: `tests/Feature/Admin/FormRequestAuthzDriftSentinelTest.php` ✅

### §10 — C8 Communications `(Wave 6 — ⚠ EXTERNAL : static-verify only, NEVER live-fire)`
- **T-8.1** `/admin/messages` — thread→detail ; reply = **EXTERNAL** (static-verify câblage, pas de send).
  • anchor: `Admin/MessageController.php` • visual: `/admin/messages`
- **T-8.2** `/admin/push-notification` — compose (capture form OK) ; **Send = EXTERNAL `SendFcmNotificationJob` → NE PAS cliquer**, vérifier route+job en statique.
  • anchor: `Admin/PushNotificationController.php` + `app/Jobs/SendFcmNotificationJob.php:67` • test: (TO BE CREATED at `tests/Feature/PushNotification/PushNoLiveFireSentinelTest.php`)
- **T-8.3** `/admin/subscribers` — list/export(RO) ; notify = **EXTERNAL** static-verify.

### §11 — C9 Réglages (~27 sous-pages) `(Wave 7 — la plus lourde ; EXTERNAL+destructif ; reseed après)`
- **T-9.0** Root `/admin/settings` — `MenuComponent.vue` : énumérer TOUTES les entrées de réglages rendues (REDIRECT map exhaustive).
- **T-9.1..n** Une tâche **par sous-page** (cf. inventory C9 ; ~27) — DEPTH CONTRACT chacune. Classes critiques :
  - **EXTERNAL (static-verify, pas de send/rotation) :** `mail` (test-send), `sms-gateway` (test-send), `otp`, `notification`, `payment-gateway` (secrets — ne pas révéler/roter), `social-media`.
  - **REVERSIBLE (clone-only + reseed) :** `company`,`site`,`order-setup`,`kiosk-setup`,`loyalty-setup`,`theme`,`time-slots`,`tax`,`currencies`,`languages`,`cookies`.
  - **Drill-downs list→show/:id :** `branches`,`analytics`,`sliders`,`item-categories`,`item-attributes`,`payment-terminals`,`kiosk-machines`,`pages`.
  • anchor: `resources/js/router/modules/settingRoutes.js` + `components/admin/settings/<Entity>/` • test: `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` ✅ (+ per-page render TO BE CREATED at `tests/Feature/Settings/SettingsPagesRenderSentinelTest.php`)
  • persona : le gérant peut-il configurer sans casser le fiscal/kiosk ? champs dangereux balisés ?

### §12 — C10 Profil/Shell nav `(Wave 2, en même temps que pré-flight smoke)`
- **T-10.1** Sidebar `BackendMenuComponent.vue` — **chaque lien du menu rendu → cible rend** (REDIRECT map du menu entier, incl. children + RBAC gating). Vérifier 9 modules hidden bien **absents**.
  • anchor: `resources/js/components/layouts/backend/BackendMenuComponent.vue` (`V1_PRIMARY_SIDEBAR_MENUS:92`) • test: (TO BE CREATED at `tests/Feature/Nav/SidebarLinksRenderSentinelTest.php`)
- **T-10.2** `/admin/profile` — edit profil, **changement mot de passe** (REVERSIBLE clone-only, re-login après).
  • anchor: `Admin/ProfileController.php`
- **T-10.3** Header/topbar — branch-switch, langue (FR-lock), logout, cloche notifications.
- **T-10.4** Launch-points POS/KDS/OSS/Suivi-client — **render-smoke only** (D-2), confirmer que les liens partent + renvoi audits séparés.

---

## §A — AGENT ARMY MAP + FAN-OUT (visual-heavy car c'est le cœur de la demande)
| Rôle | Subagent | Tools | Brief |
|---|---|---|---|
| Cartographe DOM | `general-purpose` | Read + Playwright(`:8766`) | par page : DOM-snapshot → énumérer+classer (taxonomie §0.4) tous les éléments |
| QA Visual | `general-purpose` | Read + Playwright | capture + analyse les 7 blocs DEPTH CONTRACT |
| RED Visual | `general-purpose` | Read | **ré-analyse indépendante** des captures QA, dispute les défauts cachés (anti confirmation-bias) |
| Tech/Backend | `general-purpose` | Read + Bash(read-only) | mapper chaque bouton→`route→Controller@method` (anchor vérifié) ; détecter dead-controls ; RBAC |
| Security/External | `general-purpose` | Read | flag EXTERNAL/destructif ; vérifier qu'aucun live-fire ; secrets non exposés |
| Persona-gérant | `general-purpose` | Read | par page : verdict décision-readiness + proposition d'amélioration |
| Synthèse/verify | (main) | verify-before-report | dédup, drop hallucinés, GREEN/YELLOW/RED + counts |

**Playwright = navigateur unique partagé → JAMAIS parallélisé entre sous-agents.** Captures = **specs headless scriptés via Bash sur `:8766`** (pattern specs-in-job-tmp) pour la largeur ; MCP réservé aux spot-checks **série**. Les lanes statiques (Tech/Security/anchor) parallélisent via Agent. Reports persistés disk : `reports/test-e2e/dashboard-deep-2026-06-08/<cluster>/wave-<W>-<role>.json` (schéma : `[P0-P3] file:line — titre / repro / evidence(screenshot) / reco`).

## §X — WAVES (séquentiel par défaut ; reseed `foodking_e2e` à chaque frontière ; read-only→destructif)
| Wave | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| **W1 Pré-flight** | backup branch + **snapshot `foodking_e2e`** ; baselines (route count, audit_logs hash) ; confirmer `:8766`→foodking_e2e ; valider D-1/D-2 | — | snapshot pris, ports mappés |
| **W2 Shell + Dashboard** | C10 nav (T-10.x) + C1 (T-1.1) | audit-phase fan-out parallèle | tous liens menu rendent ; KPI vérifiés ; reseed |
| **W3 Catalogue + Stock** | C2 + C3 | séquentiel (partage Item) | CRUD exercé clone-only ; reseed |
| **W4 Commandes + Caisse** | C4 + C5 | séquentiel (partage Order) | encaissement+Z clone-only ; **NF525 chain hash inchangé sur `:8765`** ; reseed |
| **W5 Rapports + Users** | C6 + C7 | C6∥C7 (controllers disjoints) | RBAC vérifié ; exports RO ; reseed |
| **W6 Communications** | C8 | séquentiel | **0 live-fire** attesté (static-verify) ; reseed |
| **W7 Réglages** | C9 (~27 sous-pages) | séquentiel, destructif en dernier | toutes sous-pages couvertes ; secrets non exposés ; **reseed obligatoire** |
| **W8 Synthèse** | consolidation + verify-before-report + rapport final + liste améliorations priorisée | — | coverage 100% inventory ; §F satisfait |

**Checkpoint (6 points, fin de chaque wave) :** (1) toutes tâches = bloc-7 livré ou baseline-known documenté ; (2) frozen-diff=0 (`git diff --stat` sur §7) ; (3) NF525 hash inchangé si fiscal touché ; (4) visual gate tiré (captures Read+analysées) ; (5) RED Visual dispute faite ; (6) reseed `foodking_e2e` + BRAIN §2/§3 mis à jour.
**Interrupt-resume :** commit WIP `wip(dashboard-deep): through T-x` + manifeste `reports/.../INTERRUPT_<wave>.md` (last green SHA, dernière tâche, prochaine, snapshot courant) + BRAIN §2. Reprise : lire manifeste, restore snapshot, smoke 1 tâche, continuer.

## §G — OWNER GATES (WHO/WHAT/WHERE)
| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G-D1 | Choisir Audit-seul vs Audit+Heal (§0.2) | Owner | réponse à la revue du plan | ce doc §0.2 | PENDING |
| G-D2 | Profondeur POS-wizard/KDS/OSS (§0.2) | Owner | réponse à la revue | ce doc §0.2 | PENDING |
| G-EXT | Confirmer que **aucun** SMS/mail/push réel n'est toléré pendant l'audit | Owner | confirmation | §0.4 EXTERNAL | défaut = NEVER-fire |
| G-HEAL | (si D-1=heal) tout heal frozen-zone (PaymentComponent/wizard) | Owner | LOCK doc countersign | `plans/LOCK_*` §10 | conditionnel |
> Gates non bloquants pour W1–W8 audit-only (le défaut s'applique) ; G-HEAL ne bloque que la piste heal séparée.

## §R — RÉFÉRENCES
- Spine : `plans/PAGE_INVENTORY_DASHBOARD_2026-06-08.md`
- Pipeline tâche : `~/.claude/skills/ultra-audit-profond/` · Dual-visual : `~/.claude/skills/test-e2e/` · Fan-out : `superpowers:dispatching-parallel-agents`
- Verify gate : `~/.claude/skills/verify-before-report/`
- `CLAUDE.md` §6 (visual mandate) §7 (frozen) §8 (NF525) §9 (RBAC) · `CONSTITUTION.md` · `SYSTEM_MAP.md`
- Audit infra 2026-06-08 : `reports/audit/full-audit-2026-06-08/FULL_AUDIT_REPORT.md` (port-map, NF525 OK, frozen 0-drift)
- Memory : `reference_e2e_harness_foodking_e2e_2026-06-07` (port map), `feedback_shared_infra_devdb_footgun` (DB safety)

## §F — DONE (coverage-complete + verified — c'est un AUDIT, pas un heal)
Le GOAL est LIVRÉ quand :
1. **100% de l'inventaire** (`PAGE_INVENTORY`, ~58 pages V1-actives + ~12 hidden render-smoke) est couvert — 0 silent cap ; toute page rendue non-listée = ajoutée comme finding inventory-gap.
2. **Chaque page** porte les **7 blocs** du DEPTH CONTRACT : inventory+classification, function+endpoint, redirect-map confirmé-rendant, technical (console/réseau/RBAC/états/i18n), visual (capture analysée), persona-verdict + **proposition d'amélioration**, evidence file:line+repro.
3. **Chaque bouton** classé + exercé selon sa classe (EXTERNAL static-verify, jamais live-fire ; destructif clone-only).
4. **Sécurité prouvée :** 0 écriture sur `foodking` opérationnel (NF525 hash `:8765` inchangé) ; 0 live-fire SMS/mail/push ; reseed à chaque wave.
5. **Rapport final** `reports/test-e2e/dashboard-deep-2026-06-08/FINAL_REPORT.md` : par cluster GREEN/YELLOW/RED + counts P0-P3 + **liste d'améliorations priorisée par page** (le livrable « améliorer chaque page »).
6. Findings vérifiés (verify-before-report) ; frozen-diff=0 ; BRAIN §2/§3 à jour.
> PAS de « presque » : une page sans capture analysée ou sans persona-verdict = NON couverte.
