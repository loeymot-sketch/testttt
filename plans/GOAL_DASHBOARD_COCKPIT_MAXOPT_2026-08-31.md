# GOAL — Dashboard = poste de commandement max-optimisé
**Date** : 2026-08-31  
**Slug** : `DASHBOARD_COCKPIT_MAXOPT`  
**Branche** : `pos/category-first-caisse-2026-06-23` (working tree Grok **inclus**)  
**Méthodologie** : `ultra-architect-planify` → exécution par tâche = `ultra-audit-profond` + `.grok/skills/test-e2e`  
**Voie** : GROK (Claude Big Boss, frozen `CLAUDE.md` §7 lecture seule)  
**Skip clarifying** : owner a dit lancer / optimiser au max  
**SSOT parallèle** : `docs/grok/MISSION_DASHBOARD_COCKPIT_10J.md`

---

## §0 — Preamble

### 0.1 Working-tree decision
Le tree est **sale** (mission Grok dashboard/catalogue/composeur 2026-08-29→31 : PHP + Vue + tests + Mix partiel). `PROJECT_BRAIN.md` en conflit merge — **ne pas** l’éditer depuis Grok.

**Décision** : **INCLUDE-IN-SCOPE**. Pas de stash. Pas de commit auto (owner gate G0). La mission continue sur le diff Grok existant. Interdit `git add .`.

### 0.2 Scope expansion (interdit)
- ❌ Éditer kiosk Vue / `pos-wizard.js` / PaymentComponent / fiscal services  
- ❌ `migrate:fresh` / wipe 64 catégories junk / commandes test NF525  
- ❌ Inventer des produits (SSOT `items` ACTIVE : Tacos XL/L/M, Galette Classique/Cayenne/Normale, Big Burger, …)  
- ❌ Flip `FEATURE_WIZARD_PER_ITEM_DEMO`  
- ❌ Recalculer un prix en Vue (`PricingService` lecture seule)

**Inclus** : Dashboard, catalogue studio, composeur admin, rôles/permissions, santé/interrupteurs **admin**. Contrôle borne/caisse = **API + interrupteurs + composeur publié**, pas les Vue frozen.

### 0.3 Advisor
Consulté 2026-08-31 (`plan` subagent) : 5 systèmes Grok/SHARED ; Mix webpack 5.110 `SizeFormatHelpers` = **gate G1** avant toute preuve écran=source ; Galette ≠ Tacos déjà prouvé E2E.

### 0.4 Pipeline par tâche
`~/.claude/skills/ultra-audit-profond/` (14 étapes). Ne pas recopier ici. Visual : `.grok/skills/test-e2e` — parent **lit** chaque PNG. Convergence : 2 rounds P0+P1=0, même set.

### 0.5 Convergence DONE
Pas « presque ». Une tâche DONE ssi : ancres réelles, test **nommé** PASS, E2E PNG lu, frozen diff 0, adversaire sans P0/P1 nouveau.

### 0.6 Anti-fiction — dumps vérifiés 2026-08-31 (Axis 1)

```
find Controllers Dashboard|Observability|Interrupteur|Healthz
→ DashboardController.php CashOverviewController.php
  Observability/ SyncOverviewController.php:606 systemHealth
  Pilotage/InterrupteurController.php:30 role:Admin|Tenant Admin
  HealthzController.php

find dashboard/*.vue observability/*.vue
→ Dashboard Overview RealtimeReport AuditTrail FeaturedItems
  MostPopularItems StockLowAlerts LastZReport SlaAlerts ChannelStats
  SystemHealth OutboxOverview

ls tests/Feature/Grok/*.php
→ AuditPollutionCategoriesHiddenTest ComposerMerchantLiesTest
  CockpitHiddenFromCashierTest ProtectedRolePermissionsCannotBeWipedTest
  DashboardControlAuditFixesTest DashboardControlLoopOptimizationsTest
  InactiveCategoryHiddenFromPublicShowTest UniqueIgnoreSelfSaveTest
  ItemExtraGroupAndVisibilityPersistTest ItemAttributeDestroyGuardTest
  RoleDestroyProtectsCashierTest MerchantScreenLieFixesTest

Composer : ComposerProfileProjection.php ComposerProfileService.php
  ComposerStepService.php ComposerTemplateService.php
  ProductComposerEditorComponent.vue ComposerStepFormPanel.vue

Live DB (tinker, pas inventé) :
  Tacos XL item_id=234 cat=5 ; profile publié cat id=38 v=4
  Galette cat=2 profile publié id=36 v=9 template=sandwich
  Branch 1=Le Cayenne ; 10=Collier and Sons (faker preview)
```

### 0.7 Déjà VERT (ne pas re-ouvrir)

KPI 55 · studio 14 · caissier deep-link dashboard · extra_group `step_key` ·
Galette 5 pages ≠ Tacos 7 · featured sans E2E · audit 20.

### 0.8 État Mix
`webpack@5.110.1` : `webpack/lib/SizeFormatHelpers.js` **absent**. `npx mix` exit 2.
Dernier shell compilé connu : `admin-shell.8468e026.js`. Wave 1 = débloquer ça.

---

## §1 — Map principal (5 systèmes)

| # | Système | Maturité | Ancre vérifiée 2026-08-31 | Tests existants |
|---|---|---|---|---|
| 1 | Dashboard cockpit | HIGH (KPI 55, audit 20, featured clean) | `DashboardController.php` `DashboardService.php:60` `DashboardComponent.vue` | `tests/Feature/Grok/DashboardControlLoopOptimizationsTest.php` + `tests/Feature/Dashboard/*` |
| 2 | Catalogue studio | HIGH (14 rayons, 55 articles) | `ItemCategoryController.php` `ItemCategoryService.php` `CatalogStudioComponent.vue` | `AuditPollutionCategoriesHiddenTest.php` `catalogStudioRouting.spec.js` |
| 3 | Composeur | MED (projection OK, UI Mix stale) | `ComposerProfileProjection.php` `ComposerProfileController.php` `ProductComposerEditorComponent.vue` | `ComposerMerchantLiesTest.php` `composerEditorV2.spec.js` |
| 4 | Accès RBAC | HIGH (caissier ≠ cockpit) | `PermissionController.php` `RoleService.php` `AppLibrary.php` | `CockpitHiddenFromCashierTest.php` `ProtectedRolePermissionsCannotBeWipedTest.php` |
| 5 | Pilotage santé | MED (honnête 1490/25j, Mix interrupteurs) | `SyncOverviewController.php:606` `InterrupteurController.php` `SystemHealthComponent.vue` | `DashboardControlAuditFixesTest.php` |

**Hors édition (contrôle via §5 uniquement)** : POS wizard frozen, Kiosk wizard frozen, KDS, OSS, fiscal HMAC. Ancres existent ; tâches = **lecture API + E2E admin**, jamais patch Vue frozen.

---

## §2 — Map separated

| Système | Chemin | Lien V1 |
|---|---|---|
| Mobile RN | `mobile/` | STANDALONE, no API wireup |
| Web standalone | hors `testttt` | STANDALONE |
| `ItemController` fiche produit | never-touch Grok FRONTIERES | republish post-create = BLOCK owner |

---

## §3 — Système 1 : Dashboard cockpit

### Contract
Le gérant voit **la vérité** (KPI, Z, stock, featured, audit métier) et ouvre **chaque** fonction du restaurant depuis les accès rapides, sans tuile menteuse (0,00 € si API morte).

### Frozen
Aucun fichier §7. Lecture `audit_logs` seule — **pas** `AuditLogService` mutate.

### Anchors (find 2026-08-31)
- `app/Http/Controllers/Admin/DashboardController.php` (`featuredItems`, `withoutCatalogPollution`)  
- `app/Services/DashboardService.php:60` `dashboardBranchId` ; `auditTrail` `limit(20)`  
- `resources/js/components/admin/dashboard/{Dashboard,Overview,RealtimeReport,AuditTrail,FeaturedItems,MostPopularItems,StockLowAlerts,LastZReport,SlaAlerts,ChannelStats}Component.vue`

### Sub 1.1 — KPI + accès rapides
**Anchors** : `OverviewComponent.vue` `#F4501E` ; `RealtimeReportComponent.vue` Ticket moyen seul ; `DashboardComponent.vue` `quickAccessLinks`  
**Tasks** :
- T-1.1.1 Recapture KPI 55 + Ticket moyen — pas de doublon CA  
  • test: `tests/Feature/Grok/DashboardControlLoopOptimizationsTest.php`  
  • visual: `http://127.0.0.1:8766/admin/dashboard`
- T-1.1.2 Chaque accès rapide (sauf POS payment) ouvre la **bonne** route  
  • test: `(to be created at tests/js/dashboardQuickAccessRoutes.spec.js)`  
  • visual: Dashboard → Catalogue / Historique / Cuisine / Stock
- T-1.1.3 Fail-closed tuiles si perms vides  
  • test: `DashboardControlLoopOptimizationsTest.php` (`quickAccessLinks` / Z widget)

### Sub 1.2 — Audit trail lisible
**Anchors** : `DashboardService.php` `auditTrail` ; `AuditTrailComponent.vue` `max-h-80`  
**Tasks** :
- T-1.2.1 20 lignes, login dépriorisé (déjà) — 2 rounds identiques  
  • test: `DashboardControlLoopOptimizationsTest.php` `test_audit_trail_caps_at_20_and_deprioritizes_logins`  
  • sentinel connu ROUGE : `tests/Feature/Dashboard/AuditTrailUsesAuditLogSentinelTest.php` (actingAs sans rôle Admin) — **documenter baseline**, ne pas fail-open `dashboardBranchId`
- T-1.2.2 i18n `user.device_revoked` (clé brute vue PNG)  
  • test: `(to be created at tests/js/auditTrailI18n.spec.js)` si prefix `label.audit_event_*` autorisé SHARED

### Sub 1.3 — Featured / populaires / stock / Z
**Anchors** : `DashboardController.php` `withoutCatalogPollution` ; `LastZReportWidget.vue`  
**Tasks** :
- T-1.3.1 Featured + popular sans E2E_ ni interne  
  • test: `AuditPollutionCategoriesHiddenTest.php` `test_featured_carousel_hides_e2e_item_names`
- T-1.3.2 Z widget fail-closed  
  • test: `DashboardControlLoopOptimizationsTest.php` `test_z_report_widget_fail_closed`
- T-1.3.3 Stock bas empty state  
  • visual: dashboard « Aucune alerte… »

### Sub 1.4 — PDF clôture
**Anchors** : `DashboardComponent.vue` bouton EOD ; `tests/Feature/Dashboard/EodPdfRecapSentinelTest.php`  
**Tasks** :
- T-1.4.1 Click PDF ne 500 pas (pas de commande test)  
  • test: `tests/Feature/Dashboard/EodPdfRecapSentinelTest.php`

---

## §4 — Système 2 : Catalogue studio

### Contract
14 rayons Le Cayenne, compteur **55** aligné KPI, photos réelles, wizard rayon **sans** coller Tacos sur Galette.

### Frozen
`ItemController` never-touch. Photos `public/images/menu/thumbs/*` = data.

### Anchors
- `app/Services/ItemCategoryService.php` `FAKER_LATIN_CATEGORY_NAMES` `constrainVisibleCatalog` `constrainCustomerFacing`  
- `resources/js/components/admin/items/CatalogStudioComponent.vue` `catalogProducts` `customerFacingProducts`  
- `app/Http/Controllers/Admin/ItemCategoryController.php`

### Sub 2.1 — Liste + masque pollution
**Tasks** :
- T-2.1.1 Préfixes AUDIT/E2E + 21 faker — `AuditPollutionCategoriesHiddenTest.php`  
- T-2.1.2 Interne reste dans le rail, hors badge « toutes » — `catalogStudioRouting.spec.js`  
- T-2.1.3 2 rounds E2E studio 14/55 (`CONVERGENCE_IMPROVE_34.md` déjà ; reconfirm post-Mix)

### Sub 2.2 — Photos par produit
**Tasks** :
- T-2.2.1 Tacos M/L/XL : aujourd’hui **même** `tacos-cayenne.webp` — décision owner G4 (upload 3 thumbs) **ou** accepter P2 documenté  
  • visual: `round-2/wave-D/01-tacos-rail.png`  
- T-2.2.2 Extra image_path : pas de 404 silencieux  
  • test: `(to be created at tests/Feature/Grok/ExtraImageFallbackTest.php)`

### Sub 2.3 — Duplication catégorie
**Tasks** :
- T-2.3.1 Clone Tacos ↛ partage `source_ref` Galette  
  • test: `(to be created at tests/Feature/Grok/CategoryWizardCloneDoesNotSharePublishedRefTest.php)`  
  • **pas** de clone live sans GO owner (G-DATA)
- T-2.3.2 Publish catégorie vide ≠ 200  
  • test: `ComposerMerchantLiesTest.php` (étendre)

### Sub 2.4 — CRUD attributs/extras/variations
**Anchors** : `ItemExtraController.php` `ItemVariationController.php` `ItemAttributeController.php`  
**Tasks** :
- T-2.4.1 Unique self-save — `UniqueIgnoreSelfSaveTest.php`  
- T-2.4.2 Extra group persist — `ItemExtraGroupAndVisibilityPersistTest.php`  
- T-2.4.3 Attribute destroy guard — `ItemAttributeDestroyGuardTest.php`

---

## §5 — Système 3 : Composeur

### Contract
Chaque rayon a **sa** logique. `source_ref` vide n’est plus « tout ». Extra 1re sauce gratuite = attribut prix 0 + extra sauce 0,50 (preuve projection, **pas** PricingService write). Publish = clones ; PUT publié = fork.

### Frozen
PricingService, kiosk Vue, POS wizard. Preview API peut lire menu.

### Anchors
- `app/Services/Composer/ComposerProfileProjection.php` (extra_group `step_key`, attribute fail-closed)  
- `app/Services/Composer/ComposerProfileService.php` `ComposerTemplateService.php` `EXTRA_GROUP_ALIASES`  
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` `previewBranches` `ComposerStepFormPanel.vue`

### Sub 3.1 — Projection till
**Tasks** :
- T-3.1.1 `source_ref` vide attribut ≠ viande+sauce — `ComposerMerchantLiesTest.php` `test_empty_source_ref_does_not_mix_viande_and_sauce_on_till`  
- T-3.1.2 extra_group vide + `step_key=supplements` → Cheddar pas Salade — `test_empty_extra_group_ref_uses_step_key_not_every_extra`  
- T-3.1.3 addon id numérique — `test_addon_numeric_source_ref_is_that_row_not_every_addon`  
- T-3.1.4 Live Tacos XL + profile cat `id=38` : viande 7, sauce 14, supplements 10, taille 0, garnitures 0 (data)

### Sub 3.2 — UI honnête (dépend G1 Mix)
**Tasks** :
- T-3.2.1 Option vide = « Aucune source — page vide en caisse » — `composerEditorV2.spec.js`  
- T-3.2.2 Preview **uniquement** branche `id=1` Le Cayenne — `(to be created at tests/js/composerPreviewBranchLeCayenne.spec.js)`  
- T-3.2.3 Brouillon : phrase caisse lit encore publié — `data-testid=admin-composer-draft-not-till`  
- T-3.2.4 Pain Galette Inactive : soit activer (data) soit masquer step inactif en till — `(to be created at tests/Feature/Grok/InactiveComposerStepHiddenOnTillTest.php)`

### Sub 3.3 — Rayons disjoints (E2E Dashboard → Catalogue → Wizard)
Produits réels seulement.

| Tâche | Rayon | Attendu | Preuve |
|---|---|---|---|
| T-3.3.1 | Tacos (cat 5) | 7 steps taille+3 viandes+sauce+garnitures+menu | `round-2/wave-D/` |
| T-3.3.2 | Galette (cat 2) | 5 steps pain/viande/sauce/crudite/supplement **sans** viande_2 | `round-3/wave-D-galette/` **DONE cycle 1** |
| T-3.3.3 | Sandwichs | template sandwich, pas tacos | `(E2E to capture)` |
| T-3.3.4 | Burgers | pas 3 viandes tacos | `(E2E to capture)` |
| T-3.3.5 | Boissons / Frites | 0 step viande | `(E2E to capture)` |

**Acceptance** : `ComposerMerchantLiesTest.php` + PNG parent-lus 2 rounds par rayon.

Surfaces E2E (toutes via Dashboard, BASE 8766) :

| Route | Usage |
|---|---|
| `/admin/dashboard` | hub A1–A11 |
| `/admin/observability/system` | A12/A13 |
| `/admin/items/studio` | catalogue |
| `/admin/categories/5/composer` | Tacos iframe |
| `/admin/categories/2/composer` | Galette iframe |
| `/login` | flag 404 leftover |

Preuves déjà disque :  
`reports/test-e2e/grok-dashboard-cockpit-10j/round-1/wave-A/01-dashboard.png`  
`.../round-1/wave-A13/01-health.png`  
`.../round-2/wave-A/01-dashboard.png`  
`.../round-2/wave-D/02-tacos-wizard.png`  
`.../round-3/wave-D-galette/02-galette-wizard.png`

### Sub 3.4 — Publish / fork / version
**Tasks** :
- T-3.4.1 PUT publié = fork — `ComposerMerchantLiesTest.php`  
- T-3.4.2 PATCH step publié 422  
- T-3.4.3 POST publish envoie `version` (409 concurrent) — `(to be created at tests/Feature/Grok/ComposerPublishVersionConflictTest.php)` si absent de `composerEditorVersionConflict.spec.js`

---

## §6 — Système 4 : Accès RBAC

### Contract
Admin pilote. Caissier encaisse. Chef cuisine. Waiter tables. Stuff KDS. Deep-link cockpit caissier → dashboard.

### Frozen
Aucun §7. `AppLibrary` / `permission-match.js` SHARED déjà claimés.

### Anchors
- `PermissionController.php` exists ids + Chef/Waiter/Stuff pins  
- `resources/js/shared/permission-match.js` unknown=false  
- `app/Libraries/AppLibrary.php` `menuRowForbidden`

### Sub 4.1 — Caissier vs Admin
**Tasks** :
- T-4.1.1 Sidebar sans État du système — `CockpitHiddenFromCashierTest.php`  
- T-4.1.2 Deep-link 2 rounds — `CONVERGENCE_WAVE_E.md` reconfirm post-Mix  
- T-4.1.3 PUT interrupteur caissier 403 — `(to be created at tests/Feature/Grok/CashierCannotToggleInterrupteurTest.php)`

### Sub 4.2 — Pins métiers
**Tasks** :
- T-4.2.1 POS `pos`, Chef/Stuff KDS, Waiter `table-orders` — `ProtectedRolePermissionsCannotBeWipedTest.php`  
- T-4.2.2 Dummy id 422  
- T-4.2.3 Role destroy cashier — `RoleDestroyProtectsCashierTest.php`

### Sub 4.3 — Pages CMS
**Tasks** :
- T-4.3.1 index `permission:settings` — `DashboardControlLoopOptimizationsTest.php` `test_page_index_requires_settings_permission`

---

## §7 — Système 5 : Pilotage (santé + interrupteurs)

### Contract
Le gérant **voit** file/backup/scheduler **vrais**. Il bascule borne/caisse **après confirm**, journal serveur pas NF525. Caissier ne bascule pas.

### Frozen
`AuditLogService`, fiscal chain. Queue 1490 = **ne pas** lancer worker sans G3.

### Anchors
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:606` `systemHealth`  
- `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php:30` `role:Admin|Tenant Admin`  
  Whitelist PNG 2026-08-30 : `split_payment` `wheel` `remise_manuelle` `fidelite` `kiosk_promo` `impression_ticket_client_auto`.  
  **Pas** `idempotency.enabled`. Service : `app/Services/Pilotage/InterrupteurService.php` `CATALOGUE`.  
- `resources/js/components/admin/observability/SystemHealthComponent.vue`  
- `app/Http/Controllers/HealthzController.php`

### Sub 5.1 — Santé honnête
**Tasks** :
- T-5.1.1 Queue unknown ≠ 0 ; backup `*.sql.gz` 26h — `DashboardControlAuditFixesTest.php`  
- T-5.1.2 403 fetch ≠ « aucune sauvegarde » — `test_system_health_failed_fetch_is_not_no_backup`  
- T-5.1.3 Recapture 1490 / 25j / scheduler muet — `round-1/wave-A13/01-health.png`

### Sub 5.2 — Interrupteurs
**Tasks** :
- T-5.2.1 `window.confirm` + `aria-live` — `DashboardControlLoopOptimizationsTest.php`  
- T-5.2.2 Copy « journal serveur, pas NF525 »  
- T-5.2.3 Après PUT Admin, GET relit `actif` — `(to be created at tests/Feature/Grok/InterrupteurRoundTripTest.php)` **sans** laisser un flag prod cassé (restore in test)

### Sub 5.3 — Login flag leftover
**Tasks** :
- T-5.3.1 Page `/login` 404 `/storage/1/english.png` — fallback public PNG  
  • test: `(to be created at tests/js/loginLanguageFlag.spec.js)`  
  • visual: `http://127.0.0.1:8766/login`

---

## §A — Armée d’agents (Grok types)

| Rôle | `subagent_type` | Outils |
|---|---|---|
| Architect | `plan` | read |
| Cartographe | `explore` | read |
| Implementer | `code-editor` / parent | write séquentiel |
| RED | `adversary` | read |
| Reasoner | `reasoner` | read |
| QA visual | `e2e-hunter` | Playwright CLI Node 22 BASE 8766 |
| UX | `ux-critic` | PNG+DOM |
| Orchestrateur | parent | **lit chaque PNG** |

Fan-out : lectures parallèles ; **1 writer**. Reports : `reports/test-e2e/grok-dashboard-cockpit-10j/<round>/wave-<W>-<role>.json`.

Prompt type tâche frontend : ancre file:line, frozen list, test path, « ne pas inventer de produit », comptes `admin@lecayenne.fr` / `pos@lecayenne.fr`.

---

## §X — Waves

Checkpoint 6 points (skill Axis 3) à **chaque** fin de vague. Interrupt : `reports/test-e2e/grok-dashboard-cockpit-10j/INTERRUPT_W<n>_<ts>.md`.

### Wave 1 — Preflight Mix (séquentiel, BLOQUE le visuel composeur)
**Scope** : webpack 5.110 vs Mix `SizeFormatHelpers`  
**Budget** : 1 tâche  
**Parallelism** : none  
**Tasks** : T-W1.1 Réparer Mix **sans** `npm install` aveugle (pin webpack compatible Mix 6 **ou** patch local BuildOutputPlugin)  
**Checkpoint** : `npx mix` exit 0 ; `admin-shell.*.js` hash **nouveau**  
**Resume** : log Mix + `public/mix-manifest.json`  
**Gate** : G1

### Wave 2 — Dashboard recapture (séquentiel après W1 ou parallèle lecture)
**Scope** : §3 T-1.*  
**Parallelism** : e2e-hunter A + A13  
**Checkpoint** : KPI 55, audit ≤20, popular sans interne, 2 rounds

### Wave 3 — Catalogue (disjoint composeur UI)
**Scope** : §4 T-2.1 / T-2.4 (T-2.3 clone = G5)  
**Parallelism** : PHPUnit + studio E2E  
**Checkpoint** : 14/55 identique improve-4

### Wave 4 — Composeur rayons (séquentiel par rayon, 1 writer)
**Scope** : §5 T-3.3.* Sandwichs → Burgers → Boissons après Galette déjà cycle 1  
**Parallelism** : none on same Vue  
**Checkpoint** : PNG wizard + projection counts ; Mix W1 required for T-3.2

### Wave 5 — RBAC + interrupteurs
**Scope** : §6 + §7 T-5.2  
**Parallelism** : tests Feature Grok vs E2E cashier  
**Checkpoint** : deep-link caissier dashboard ; PUT interrupteur 403 POS

### Wave 6 — Login flag + santé recapture
**Scope** : T-5.3 T-5.1  
**Parallelism** : login E2E + health GET

### Wave 7 — Convergence globale
**Scope** : Dashboard → chaque accès (sauf POS pay) × 2 rounds  
**Parallelism** : hunters par surface, 1 browser isolé chacun  
**Checkpoint** : P0+P1=0 identique ; frozen `git diff --stat -- <§7>` = 0  
**Resume** : `CONVERGENCE_FINAL` seulement ici

Heal max 3 / cluster → STUCK doc + owner A/B/C/D.

### Interrupt-resume (copier tel quel)

Fichier : `reports/test-e2e/grok-dashboard-cockpit-10j/INTERRUPT_W<n>_<ts>.md`

```
wave:
last_green_sha:
last_task: T-x.y.z
status: PASS|FAIL|IN-PROGRESS
next_task:
pngs_parent_read:
mix_hash:
notes:
```

Reprise : lire manifest → `git status` → smoke `phpunit --filter=ComposerMerchantLiesTest` → 1 E2E dashboard → continuer next_task. **Pas** de 4e heal silencieux.

### Critères checkpoint (toutes waves)

- [ ] Tâches PASS ou fail baseline documenté (ex. AuditTrail sentinel actingAs)
- [ ] `git diff --stat -- resources/js/components/frontend/kiosk public/js/pos-wizard.js` = 0
- [ ] `audit_logs` count/hash append-only si lecture seule
- [ ] PNG lus par parent
- [ ] adversary P0+P1=0 ou deferred
- [ ] JOURNAL mis à jour (BRAIN conflict → skip, JOURNAL Grok only)

---

## §G — Owner gates

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G0 | Commit / pas commit du tree Grok | Physical owner | `git add` fichiers listés | JOURNAL | PENDING |
| G1 | Mix rebuild (SizeFormatHelpers) | Grok **ou** owner `npm` pin | `npx mix` 0 | `mix-manifest.json` | **BLOCKING visuel composeur** |
| G2 | `FEATURE_WIZARD_PER_ITEM_DEMO` | Physical owner | GO écrit | `.env` | false — ne pas flipper |
| G3 | Worker `notifications` 1490 | Physical owner | GO drain | queue Redis | ne pas lancer |
| G4 | 3 thumbs Tacos M/L/XL | Physical owner | fichiers image | `public/images/menu/thumbs/` | P2 |
| G5 | Clone catégorie live / wipe junk | Physical owner | GO G-DATA | DB | no wipe |
| G6 | Debugbar / `APP_DEBUG` | Physical owner | `.env` | login overlay | P2 |
| G7 | LOCK frozen POS/kiosk | Physical owner | LOCK countersign | `docs/locks/` | n/a si on n’édite pas |

Waves 3–5 PHPUnit **peuvent** tourner sans G1. T-3.2 et Wave 7 visual composeur **attendent G1**.

---

## §R — References

- `docs/grok/MISSION_DASHBOARD_COCKPIT_10J.md`  
- `docs/grok/FRONTIERES.md` `reports/grok/JOURNAL.md`  
- `reports/test-e2e/grok-dashboard-2026-08-29/CONVERGENCE_*.md`  
- `reports/test-e2e/grok-dashboard-cockpit-10j/round-{1,2,3}/`  
- `~/.claude/skills/ultra-audit-profond/SKILL.md`  
- `.grok/skills/test-e2e/SKILL.md`  
- `CLAUDE.md` §7 §8 (read-only)  
- `plans/GOAL_PRODUCTION_READINESS_LECAYENNE_2026-05-18.md` (forme)

Comptes : `admin@lecayenne.fr` / `pos@lecayenne.fr` / `123456`. BASE `http://127.0.0.1:8766`.

---

## §F — Final rule

**DONE** = Wave 7 deux rounds propres **et** G1 Mix vert **et** frozen 0 **et** pas de 2xx sans mutation composeur **et** Galette/Tacos/Sandwichs/Burgers logiques **disjointes** prouvées PNG+PHPUnit.

Sinon : **pas** production-perfect. Partial > wrong. `lance le GOAL` = Wave 1 Mix d’abord.

```
GO GOAL_DASHBOARD_COCKPIT_MAXOPT
```
