# HANDOFF GROK → CLAUDE CODE — 2026-09-01

**Claude = chef.** Grok a **exécuté** (back-office, dashboard, catalogue, composeur admin, tests, E2E). Claude **architecte, tranche, déploie**. Ce fichier = ce qui est **prouvé**, pas ce qui a été promis.

Grok **n’a pas** fait 20 cycles × chaque fonction (protocole 10 jours). Grok **n’a pas** `CONVERGENCE_FINAL`. Grok **n’a pas** poussé git.

---

## 0. Comment Claude doit lire ça

1. `docs/grok/FRONTIERES.md` — voie Grok vs never-touch.  
2. Ce HANDOFF.  
3. `docs/grok/MISSION_ULTRA_COCKPIT_DEEP_2026-08-31.md` — mission restante.  
4. `plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md` — DAG 7 vagues.  
5. `reports/grok/JOURNAL.md` — journal daté.  
6. PNG : chemins ci-dessous. **Ouvre-les.** Un caption agent ≠ preuve.

Frozen `CLAUDE.md` §7 : **0 ligne** dans le diff Grok sur kiosk Vue, `pos-wizard.js`, `PaymentComponent`, `PricingService`, `FiscalSequenceService` (vérifié `git diff --stat` 2026-09-01).

Working tree **sale** (mission Grok + thumbs menu + `PROJECT_BRAIN.md` en conflit merge). **Ne pas** `git add .`. Ne pas résoudre BRAIN depuis Grok.

---

## 1. Preuve tests — relancés 2026-09-01 (cette session)

```
vendor/bin/phpunit --filter='AuditPollutionCategoriesHiddenTest|CockpitHiddenFromCashierTest|ComposerMerchantLiesTest|DashboardControlAuditFixesTest|DashboardControlLoopOptimizationsTest|InactiveCategoryHiddenFromPublicShowTest|ItemAttributeDestroyGuardTest|ItemExtraGroupAndVisibilityPersistTest|ItemVariationMismatchedItemUpdateTest|MerchantScreenLieFixesTest|ProtectedRolePermissionsCannotBeWipedTest|RoleDestroyProtectsCashierTest|UniqueIgnoreSelfSaveTest'

OK (81 tests, 257 assertions)
```

```
npx vitest run tests/js/catalogStudioRouting.spec.js tests/js/permissionMatchResolver.spec.js tests/js/composerEditorV2.spec.js tests/js/composerStepFormHelp.spec.js tests/js/backendNavbarFlags.spec.js tests/js/gestionAccessibleSentinel.spec.js

Test Files  6 passed · Tests  39 passed
```

### Honnêteté sur la qualité des tests

| Classe | Type | Fiabilité |
|---|---|---|
| `ComposerMerchantLiesTest` (19) | HTTP + projection PHP | **Mord** : 2xx sans mutation, `source_ref` vide, addon id, extra `step_key`, step inactive hors till |
| `ProtectedRolePermissionsCannotBeWipedTest` | HTTP PUT 422 | **Mord** : dummy ids, Chef/Waiter pins |
| `CockpitHiddenFromCashierTest` | HTTP + `AppLibrary` | **Mord** : menu PHP |
| `AuditPollutionCategoriesHiddenTest` | HTTP list + featured | **Mord** : Aliquam/E2E masqués, `total_menu_items===2` fixtures, featured sans E2E_ |
| `ItemVariationMismatchedItemUpdateTest` | HTTP 422 vs 200 | **Mord** (rouge historique 200/202) |
| `DashboardControlAuditFixesTest` | HTTP dates/roles | **Mord** branch 403, dates 422 |
| `DashboardControlLoopOptimizationsTest` | **string contains** source | **Faible** : prouve le code, pas le runtime. Complété par E2E PNG |
| Vitest composer/studio/navbar | source + mount | **Moyen** : UI Mix séparée |

**Baseline connue ROUGE** (ne pas « réparer » en fail-open) :  
`tests/Feature/Dashboard/AuditTrailUsesAuditLogSentinelTest.php` — `actingAs` user `branch_id=0` **sans** rôle Admin → 403 `dashboardBranchId`. Documenté, pas un trou Grok à rouvrir.

---

## 2. Ce qui est VRAI en production locale (PNG parent-lus)

### Accès
- Caissier `pos@lecayenne.fr` : barre **sans** « État du système ». Deep-link `/admin/observability/system` → **dashboard**. 2 rounds + reconfirm opt-3.  
  `reports/test-e2e/grok-dashboard-2026-08-29/CONVERGENCE_WAVE_E.md`

### Catalogue / KPI
- Studio **14** rayons, « Toutes les catégories » **55** = KPI dashboard **55**. Interne dans le rail, hors badge.  
  `CONVERGENCE_IMPROVE_34.md` (improve-3 = improve-4).  
  Masque lecture `AUDIT-`/`E2E%`/21 faker — **64 lignes toujours en SQL** (pas de wipe).

### Dashboard
- Palette Cayenne, Ticket moyen seul, featured **sans** `E2E_PLAYWRIGHT`, populaires **sans** Technique interne (filtre API).  
- Audit trail **20** lignes, logins dépriorisés (`round-2/wave-A/01-dashboard.png` cockpit-10j).  
- Santé : file **1490**, backup **~25 j**, scheduler muet — **honnête**. Interrupteurs **lus, pas basculés**.

### Mix
- Cassé webpack 5.110 `SizeFormatHelpers`.  
- Patch **`patches/laravel-mix+6.0.49.patch`** (`formatSize` local + WebpackBar déjà off).  
- Rebuild OK : **`admin-shell.304ba3ff.js`**.

### Composeur (Dashboard → Catalogue → Wizard) — round-4 PNG **après Mix**

| Rayon | Profil live | PNG | Till / UI |
|---|---|---|---|
| Tacos cat 5 | pub **id38 v4** | `cockpit-10j/round-2/wave-D/` | XL item **234** : viande 7, sauce 14, supp **10**, taille 0, garnitures 0 |
| Galette cat 2 | pub **id36 v9** | `round-3/wave-D-galette/` | **5** pages, **pas** viande_2 |
| Burgers cat 4 | pub **id37 v2** | `round-4/wave-D-burgers/` | 5 pages, pas viande_2, Filiale **Le Cayenne**, banner brouillon |
| Sandwichs cat 1 | pub **id34 v2** | `round-4/wave-D-sandwichs/` | **Viande 2 présente** |
| Bols cat 6 | pub **id39 v2** | `round-4/wave-D-bols-frites/01-bols-wizard.png` | Sauce bol + supplement_bol ; pain/viande/garnitures Inactive |
| Frites cat 7 | **pas de profil** | `.../03-frites-wizard.png` | PAGES **0**, achat direct — **pas** clone tacos |

Projection : `source_ref` attribut vide = 0 (pas viande+sauce). Extra vide → `step_key` + aliases. Step `is_active=false` **hors** till. Addon id = cette ligne.

---

## 3. Viande 2 Sandwichs — GATE OWNER (ne pas dépublish)

Explore 2026-08-31 : **pas** un tacos collé sur tout le rayon.

- Template PHP `sandwich` **n’a pas** `viande_2`. Data id34 l’a.  
- **Méga / Terminator** : **7** variations Viande 2 → page till **réelle**.  
- **Cayenne / Suprême** : **0** → till **saute**.  
- POS lit **clone item publié**, pas le profil catégorie.

**Ne pas** retirer `viande_2` sans GO.  
Tranche Claude/owner : (1) garder 2 viandes Méga + preview **par SKU**, ou (2) 1 viande partout = draft+publish **et** strip attr 2.

---

## 4. PAS FAIT (Claude reprend ici)

| Item | Pourquoi Grok s’est arrêté |
|---|---|
| 20 cycles × fonction | Protocole 10j ; exécuté ~2 cycles cockpit + 1 vague wizards |
| `CONVERGENCE_FINAL` | P0/P1 encore (preview « indisponible » même Le Cayenne ; viande_2 ; pain preview) |
| Sandwichs/Burgers/Boissons 2e round identique | 1 capture post-Mix |
| PUT interrupteur POS 403 E2E | test PHP pas E2E |
| Clone catégorie live | G-DATA interdit |
| 3 thumbs Tacos M/L/XL | même `tacos-cayenne.webp` |
| Worker 1490 | owner |
| Login `/storage/1/english.png` 1× | page login ≠ navbar |
| Debugbar | `APP_DEBUG` owner |
| Kiosk/POS Vue | frozen |
| `PROJECT_BRAIN.md` | conflit merge — Claude |

---

## 5. Fichiers Grok à revoir (pas une PR)

**PHP** : `ComposerProfileProjection.php`, `ComposerProfileService.php`, `ItemCategoryService.php`, `DashboardService.php`, `DashboardController.php`, `PermissionController.php`, `AppLibrary.php`, `HealthzController.php`, `SyncOverviewController.php`, `InterrupteurController.php`, FormRequests unique `optional($route)->id`.

**Vue** : `CatalogStudioComponent.vue`, `ProductComposerEditorComponent.vue`, `ComposerStepFormPanel.vue`, `BackendNavbarComponent.vue`, `AuditTrailComponent.vue`, `OverviewComponent.vue`, `RealtimeReportComponent.vue`, `permission-match.js`.

**Build** : `patches/laravel-mix+6.0.49.patch`.

**Tests** : `tests/Feature/Grok/*.php` (13 fichiers), 6 specs Vitest listées.

---

## 6. Prompt à coller dans Claude Code

```
Tu es le chef. Grok a exécuté le back-office Dashboard/catalogue/composeur admin.
Lis docs/grok/HANDOFF_CLAUDE_2026-09-01.md EN ENTIER puis FRONTIERES.md.

Preuve tests 2026-09-01 : PHPUnit 81/81 257 assertions (filtre Grok) ; Vitest 39/39.
Pas CONVERGENCE_FINAL. Frozen §7 non touché.

Mission restante : docs/grok/MISSION_ULTRA_COCKPIT_DEEP_2026-08-31.md
GOAL : plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md

Gate owner Viande 2 Sandwichs : Méga/Terminator 7 choix, Cayenne 0 — ne pas dépublish.
Preview wizard « Article non disponible » même Filiale Le Cayenne = P1 UX à architecturer.
Mix déjà vert admin-shell.304ba3ff.js via patch laravel-mix.

Ne pas wipe SQL. Ne pas inventer de produits. Ne pas flip FEATURE_WIZARD_PER_ITEM_DEMO.
Tu décides architecture + deploy. Grok a exécuté, tu valides et tu pousses selon tes gates.
```

---

## 7. Verdict Grok (honnête)

**VERT ciblé** : mensonges commerçant back-office (permissions, compteurs, projection till, Mix, wizards disjoints Galette/Burgers/Frites).  
**PAS** « tout le restaurant contrôlé depuis le Dashboard, 20× chaque fonction ».  
**PAS** deploy. **PAS** production-perfect global.

Claude : tranche viande_2, preview succursale, BRAIN, commit fichier par fichier, deploy.
