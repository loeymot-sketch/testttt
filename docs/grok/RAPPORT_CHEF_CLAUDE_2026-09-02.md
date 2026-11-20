# RAPPORT CHEF — Grok → Claude Code

**Date du dossier :** 2026-09-02  
**Rôle :** Claude = **superviseur / architecte / deploy**. Grok = exécutant back-office + validateur ciblé.  
**Produit :** FoodKing V1 LOCAL Le Cayenne (`testttt`, branche `pos/category-first-caisse-2026-06-23`).

Ce fichier est le **paquet unique** à lire avant d’agir. Les autres docs sont des annexes.

| Annexe | Usage |
|---|---|
| `docs/grok/FRONTIERES.md` | Voie Grok vs never-touch / frozen |
| `docs/grok/HANDOFF_CLAUDE_2026-09-01.md` | Preuve tests 81 puis 83, PNG, gates |
| `reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md` | Tampon **ciblé** 2026-09-01 (pas ABSOLU 100 %) |
| `reports/grok/JOURNAL.md` | Journal daté 2026-08-28 → 09-01 |
| `docs/grok/MISSION_ULTRA_COCKPIT_DEEP_2026-08-31.md` | Mission composeur restante |
| `docs/grok/MISSION_VALIDATION_ABSOLUE_GROK_2026-09-01.md` | Contrat tampon Grok |
| `plans/GOAL_VALIDATION_ABSOLUE_GROK_2026-09-01.md` | DAG W0–W5 tampon |
| `plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md` | DAG 5 systèmes Dashboard cockpit (mois) |

**Grok n’a pas poussé git. Tree sale. `PROJECT_BRAIN.md` en conflit merge — Claude le tranche. Jamais `git add .`.**

---

## 1. Où Grok est arrivé (une phrase vraie)

Le **Dashboard admin** et le **catalogue / composeur catégorie** sont devenus moins **menteurs** (permissions, compteurs, projection till, Mix, preview Disponible, caissier ≠ cockpit).  
Ce n’est **pas** « tout le restaurant contrôlé depuis le Dashboard, 20 cycles × fonction, production-perfect, déployé ».

---

## 2. Ce que l’owner a demandé vs ce que Grok a livré

| Demande owner | Livré ? | Preuve |
|---|---|---|
| Overlay Grok sans toucher `~/.claude` / `CLAUDE.md` | **Oui** | `.grok/`, `docs/grok/` |
| Back-office « screens work » + tests qui mordent | **Partiel** | PHPUnit Grok + E2E PNG |
| Dashboard/contrôle P1-01…P1-06 | **Oui code+tests** | `DashboardControlAuditFixesTest` |
| Superviseur 50–100 agents × 10 loops × tout FoodKing | **Non** (borné, honnête) | JOURNAL 29/08 |
| `deeper x100` fail-open menu | **Oui PHP+JS** | Mix plus tard |
| test-e2e jusqu’à 2 rounds cockpit caissier | **Oui** | `CONVERGENCE_WAVE_E.md` |
| Catalogue 64 junk → 14 rayons / 55 articles | **Oui masque lecture** | pas de wipe SQL |
| Dashboard = poste de commandement 10 jours | **Vague 0–4 composeur** | pas 20 cycles |
| Ultra-architect GOAL | **Oui** | 2 GOAL files |
| Validation absolue Grok | **Ciblé** | certificat : manque 2e round preview |
| Deploy | **Non** | Claude |

---

## 3. Chronologie (ce qui s’est vraiment passé)

### 2026-08-28 — Composeur / rôles / extras
Mensonges 2xx : template version=1, `source_ref` vide, extra défaut, publish catégorie sans clones, PUT publié mute le live, PATCH publié 200.  
**Fix** `ComposerProfileService` (version max+1, fan-out, unpublish reverse, fork PUT, pickAdmin unpublished). Tests `ComposerMerchantLiesTest`.  
Unique self-save `optional($route)->id`. POS Operator protégé **par nom**. Addon `role` required.

### 2026-08-29 — Dashboard P1 + fail-open
POS GET cockpit 200 → middleware Admin. `branch_id<=0` 403. Dates inversées 422. Backup `*.sql.gz` 26h. Queue `unknown` ≠ 0.  
Fail-open : `hasPermissionAccess` clé inconnue **false** (liste vide = cold start). `AppLibrary` children + alias `observability/*` → settings.  
E2E Mix stale : caissier **voit encore** le Vue cockpit en deep-link jusqu’au rebuild.

### 2026-08-29/30 — Catalogue KPI + Mix
Masque `AUDIT-`/`E2E%` puis 21 faker latin. KPI 123→59→55. Studio « toutes » 59→58→**55**. Featured sans `E2E_`. Title-case CSS. Flag `/images/language/english.png`.  
Cockpit caissier : 2 rounds propres (`CONVERGENCE_WAVE_E`).  
Adversaire opt : PASS P0+P1=0 sur masque prefix ; latin ensuite fermé.

### 2026-08-30 — Mission cockpit 10j Vague 1+4
Dashboard cycle 1–2 : audit **20** lignes, plus de mur Connexion. Santé 1490/25j **honnête**.  
Tacos : profil cat **id=38 v4**, item XL **234**. Extra `source_ref` vide → `step_key` (supplements 10). Taille 0 (M/L/XL = 3 SKU). Garnitures 0 (data).

### 2026-08-31 — Mix cassé puis VERT + wizards
`webpack@5.110` plus `SizeFormatHelpers`. Patch `patches/laravel-mix+6.0.49.patch`. Mix OK `admin-shell.304ba3ff` puis `57f379b6`.  
E2E : Galette ≠ Tacos ; Burgers 5 pages ; Sandwichs **Viande 2** ; Bols sauce bol ; Frites **0 pages**.  
Steps `is_active=false` **hors** projection till.

### 2026-09-01 — GOAL validation + preview
W0 : 81 tests. W1 : preview catégorie passait **l’id rayon** comme item → Indisponible. Bind 1er item ACTIVE.  
PNG round-5 : Burgers/Sandwichs/Galette **Disponible**. Caissier PUT interrupteur **403**. PHPUnit **83/263**.  
Certificat **ciblé** (pas 2e round preview identique).

---

## 4. Problèmes trouvés → corrigés (fichier)

| Mensonge | Cause | Correctif |
|---|---|---|
| POS voit « État du système » | API + menu + router fail-open | `InterrupteurController`/`SyncOverview` Admin ; `permission-match.js` unknown=false ; `AppLibrary::menuRowForbidden` |
| Deep-link caissier monte le Vue | Mix stale | Mix rebuild après patch webpack |
| PUT `{permissions:[999999]}` vide Chef | pas d’`exists` | `PermissionRequest` ; pin Chef/Waiter/Stuff |
| Dates ventes inversées 500 | `/0` | `assertSalesDateWindow` 422 |
| Queue probe → 0 | catch swallow | `unknown` + fail |
| Backup 30h vs 26h + decoy `.gz` | glob trop large | `*.sql.gz` |
| 123 articles | `Item::count()` | ACTIVE + `constrainCustomerFacing` |
| 64 rayons junk à l’écran | E2E/faker en SQL | masque `list()`, **pas DELETE** |
| Featured E2E_ | ItemService non touché | `DashboardController::withoutCatalogPollution` |
| Extra step vide = 0 supp | fail-closed trop sec | `step_key` + aliases `supplements→supplement` |
| Pain inactif en caisse | projection ignorait `is_active` | filter `is_active` |
| Wizard « Indisponible » Le Cayenne | `this.item = catégorie` | `item_id` sources + `item/show` |
| Mix crash | webpack 5.110 | patch laravel-mix `formatSize` |
| Collier and Sons preview | branches id desc | `previewBranches` id=1 + `defaultBranchId` status=5 |
| « Toutes les options » | copy menteuse | « Aucune source — page vide en caisse » |
| Flag `/storage/1/english.png` navbar | Spatie media mort | `/images/language/english.png` |
| Unique save 422 | `route('x.id')` null | `optional($route)->id` |

**Disputes adversaire (Grok a donné raison) :** Mix = P1 écran ; fail-open 3 couches ; « 18 COUNT » n’était pas le bug dashboard ; Viande 2 n’est **pas** un tacos collé sur tout Sandwichs (Méga/Terminator 7, Cayenne 0).

---

## 5. Preuve tests (dernier rejeu Grok)

```
2026-09-01 PHPUnit --filter Grok+CashierCannotToggle
OK (83 tests, 263 assertions)

Vitest 6 specs HANDOFF : 39 OK
productComposerEditor.spec.js : 7 OK
```

**Faible :** `DashboardControlLoopOptimizationsTest` = *string contains*. Runtime = PNG.  
**Ne pas fail-open :** `AuditTrailUsesAuditLogSentinelTest` (actingAs sans Admin → 403).

Frozen §7 : **0 ligne** (spot-check 2026-09-01).

---

## 6. PNG à ouvrir (parent / Claude)

```
reports/test-e2e/grok-dashboard-2026-08-29/CONVERGENCE_WAVE_E.md
reports/test-e2e/grok-dashboard-2026-08-29/CONVERGENCE_IMPROVE_34.md
reports/test-e2e/grok-dashboard-cockpit-10j/round-2/wave-A/01-dashboard.png
reports/test-e2e/grok-dashboard-cockpit-10j/round-2/wave-D/02-tacos-wizard.png
reports/test-e2e/grok-dashboard-cockpit-10j/round-3/wave-D-galette/02-galette-wizard.png
reports/test-e2e/grok-dashboard-cockpit-10j/round-4/wave-D-{sandwichs,burgers,bols-frites}/
reports/test-e2e/grok-dashboard-cockpit-10j/round-5/wave-D-{burgers,sandwichs,galette}/02-wizard.png  ← Disponible
reports/test-e2e/grok-dashboard-cockpit-10j/round-5/wave-E/02-observability.png  ← caissier dashboard
```

---

## 7. Live wizards (ne pas inventer)

| Cat | Nom | Publié | Till / UI |
|---|---|---|---|
| 1 | Sandwichs | id34 v2 | **Viande 2** ; Cayenne 0 choix / Méga 7 |
| 2 | Galette | id36 v9 | 5 pages, pas viande_2 |
| 4 | Burgers | id37 v2 | 5 pages |
| 5 | Tacos | id38 v4 | item 234 XL ; 3 viandes ; taille 0 |
| 6 | Bols | id39 v2 | Sauce bol + supplement_bol |
| 7 | Frites | aucun | wizard vide = achat direct |
| 3 | Sandwich Classique | id35 | **0 items** |

1re sauce gratuite = attribut prix 0 + extra 0,50. **Pas** de prix Vue. `PricingService` frozen lecture.

---

## 8. Ce que Grok n’a PAS fait (Claude attaque)

1. **2e round** preview Disponible identique (GOAL W1 ×2).  
2. **Viande 2** : tranche owner (garder Méga 2 viandes + preview par SKU **ou** 1 viande + publish). **Ne pas dépublish aveugle.**  
3. Preview liste encore steps **Inactive** (till les ignore).  
4. Thumbs wizard « No Image Found ».  
5. Login 1× `/storage/1/english.png`.  
6. Debugbar / APP_DEBUG.  
7. 64 junk SQL (masque seulement).  
8. 1490 jobs notifications — ne pas lancer le worker.  
9. Clone catégorie live.  
10. 20 cycles × chaque tuile Dashboard.  
11. PUT interrupteur **E2E UI** (HTTP 403 oui ; page jamais montée).  
12. Frozen kiosk/POS/KDS/fiscal — **hors Grok**.  
13. Commit / push / BRAIN.  
14. `CONVERGENCE_FINAL`.

---

## 9. Mission mois — Claude superviseur (discipline)

**Objectif owner :** Dashboard = poste de commandement ; chaque fonction vraie ; UX ; puis deploy.

**Interdits :** frozen §7 sans LOCK ; wipe ; inventer un produit ; `git add .` ; flip `FEATURE_WIZARD_PER_ITEM_DEMO` ; prix frontend ; worker 1490.

### Semaine 1 — Fermer le tampon
- Recapture preview Burgers/Galette **2e round** Disponible.  
- Trancher viande_2 (architecture).  
- Preview : ne pas vendre un parcours avec pain Inactive.  
- Relancer filtre PHPUnit 83 + Vitest. Frozen diff.

### Semaine 2 — Dashboard accès rapides
Chaque tuile A1–A13 (sauf paiement POS wizard) : geste Admin → écran → 2 rounds PNG.  
Stock rupture, Z, PDF clôture, historique, cuisine (lecture).

### Semaine 3 — Composeur rayons restants
Boissons, Desserts, Menu enfant, Bols till counts, Sandwich Classique 0 items (masquer wizard ou 422).  
Publish version 409. Pas de publish live sans GO.

### Semaine 4 — Contrôle borne/caisse **sans** Vue frozen
Interrupteurs round-trip Admin + restore. Matrice POS vs Admin.  
Si un geste exige `pos-wizard.js` / kiosk Vue → LOCK + owner, sinon **BLOCK**.

### Semaine 5+ — Deploy
BRAIN conflict. Commit **fichier par fichier**. CI. `fiscal:verify-chain`. Tag. Owner push.

**Pipeline par tâche :** `ultra-audit-profond` + test-e2e. 2 rounds P0+P1=0. Parent Claude **lit** les PNG. Heal max 3. Un writer.

Comptes : `admin@lecayenne.fr` / `pos@lecayenne.fr` / `123456`. BASE `http://127.0.0.1:8766` (proxy Host) ou `:8000` selon Mix `APP_URL`.

---

## 10. Prompt à coller dans Claude Code

```
Tu es le chef de projet (superviseur). Grok a exécuté le back-office Dashboard/catalogue/composeur.

Lis EN ENTIER :
  docs/grok/RAPPORT_CHEF_CLAUDE_2026-09-02.md
  docs/grok/FRONTIERES.md
  reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md

Preuve Grok 2026-09-01 : PHPUnit 83/83 (263), Vitest 39+7, frozen §7 = 0.
Preview wizard catégorie : Disponible (round-5 PNG). Caissier PUT interrupteur 403.
PAS CONVERGENCE_FINAL. PAS deploy. Tree sale. PROJECT_BRAIN.md en conflit — tu tranches.

Gate Viande 2 Sandwichs : Méga/Terminator 7 choix, Cayenne 0. NE PAS dépublish.

Mission mois : RAPPORT §9. GOAL cockpit : plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md
GOAL tampon restant : 2e round preview.

Ne pas wipe SQL. Ne pas inventer de produits. Ne pas flip FEATURE_WIZARD_PER_ITEM_DEMO.
Ne pas git add .  Commit fichiers listés. Frozen §7 = LOCK owner.

Tu vérifies le Dashboard de bout en bout, tu testes, tu valides, tu déploies selon tes gates.
```

---

## 11. Verdict Grok

**VERT ciblé** back-office (mensonges commerçant listés §4).  
**JAUNE** tampon (1 round preview, pas 2).  
**ROUGE** « tout le système contrôlé / 20× / deploy ».  
Claude termine. Grok ne se déclare pas génie sur ce qui n’est pas relu.
