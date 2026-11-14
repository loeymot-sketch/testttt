# GOAL — Validation absolue Grok (discipline ultra-architect)

**Date** : 2026-09-01  
**Slug** : `VALIDATION_ABSOLUE_GROK`  
**Mission source** : `docs/grok/MISSION_VALIDATION_ABSOLUE_GROK_2026-09-01.md`  
**Handoff** : `docs/grok/HANDOFF_CLAUDE_2026-09-01.md`  
**Validateur** : **Grok** (rejeu tests + lecture PNG). Caption hunter ≠ preuve.  
**Chef après tampon** : Claude (architecture, viande_2, BRAIN, deploy).  
**Pipeline tâche** : `ultra-audit-profond` + `.grok/skills/test-e2e`  
**Skip clarifying** : owner a demandé le GOAL dès la mission créée.

```
lance le GOAL
GO GOAL_VALIDATION_ABSOLUE
```

---

## §0 — Preamble

### 0.1 Working-tree
Tree **sale** (Grok 2026-08-28→09-01 + `PROJECT_BRAIN.md` conflit).  
**INCLUDE-IN-SCOPE.** Pas de stash. Pas de `git add .`. Pas de commit auto.  
BRAIN : **Claude** seulement.

### 0.2 Scope
**Inclus** : fermer les P1 qui **empêchent le tampon**, recapture 2 rounds, tests HTTP qui mordent, certificat ou refus daté.

**Exclus** : frozen §7, wipe 64 junk, worker 1490, flip `FEATURE_WIZARD_PER_ITEM_DEMO`, publish live wizard, inventer un produit, « Sandwichs parfait » sans GO viande_2.

### 0.3 Baseline (rejeu 2026-09-01 — ne pas re-ouvrir)

```
PHPUnit Grok  81 tests, 257 assertions  OK
Vitest        39 tests                  OK
Mix           lire `public/mix-manifest.json` **le jour du tampon**
              (HANDOFF : `admin-shell.304ba3ff.js` ; peut avoir bougé)
Frozen §7     git diff --stat = 0
```

Filtre PHPUnit **exact** (copier tel quel) :

```
vendor/bin/phpunit --filter='AuditPollutionCategoriesHiddenTest|CockpitHiddenFromCashierTest|ComposerMerchantLiesTest|DashboardControlAuditFixesTest|DashboardControlLoopOptimizationsTest|InactiveCategoryHiddenFromPublicShowTest|ItemAttributeDestroyGuardTest|ItemExtraGroupAndVisibilityPersistTest|ItemVariationMismatchedItemUpdateTest|MerchantScreenLieFixesTest|ProtectedRolePermissionsCannotBeWipedTest|RoleDestroyProtectsCashierTest|UniqueIgnoreSelfSaveTest|CashierCannotToggleInterrupteurTest|ComposerPreviewLeCayenneAvailableTest'
```

(Les 2 derniers noms n’existent que **après** W1/W2. W0 smoke = filtre **sans** eux, 81 tests.)

Vitest 6 fichiers HANDOFF + étendre `tests/js/itemPreviewProjection.spec.js` en W1.

Frozen `git diff --stat --` :

```
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskAppComponent.vue
resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
resources/js/components/admin/pos/PaymentComponent.vue
resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
public/js/pos-wizard.js
public/css/pos-wizard.css
resources/views/admin-pos-v4.blade.php
app/Services/Pricing/PricingService.php
app/Services/Fiscal/FiscalSequenceService.php
app/Services/Fiscal/ZReportService.php
app/Services/Fiscal/AuditLogService.php
```

Doit rester **vide**.

### 0.4 Advisor
Consulté (subagent `plan`) : 4 systèmes = Preview · Interrupteur · Recapture wizards · Certificat. Viande_2 = gate, pas T-fix.

### 0.5 DONE
`reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md` **ou** `REFUS_VALIDATION_ABSOLUE.md`.  
**Pas** `CONVERGENCE_FINAL` FoodKing entier.

### 0.6 Refus automatique (Axis 6)
PNG non lu · PHPUnit fail · Vitest fail · frozen non 0 · 2xx sans mutation · produit inventé · `git add .` · tampon sans 2 rounds.

---

## §1 — Map (4 systèmes)

Ancres **vérifiées** 2026-09-01 (`ls` / `grep` / `Read`).

| # | Système | Maturité | Ancre | Tests |
|---|---|---|---|---|
| 1 | Preview composeur | P1 ouvert | `ItemPreviewComponent.vue:296` `defaultBranchId` ; `ProductComposerEditorComponent.vue:239` `:item="item"` | `(to be created at tests/Feature/Grok/ComposerPreviewLeCayenneAvailableTest.php)` |
| 2 | Interrupteur RBAC | middleware OK, E2E manquant | `InterrupteurController.php:30` `role:Admin\|Tenant Admin` `update():41` abort 403 | `(to be created at tests/Feature/Grok/CashierCannotToggleInterrupteurTest.php)` + `ProtectedRolePermissionsCannotBeWipedTest.php` |
| 3 | Wizards rayons | 1 round post-Mix | PNG `cockpit-10j/round-4/` + `ComposerMerchantLiesTest.php` | recapture round-5 = round-4 |
| 4 | Tampon Grok | process | ce GOAL + HANDOFF | rejeu 81+39 + frozen |

Hors édition : kiosk Vue, `pos-wizard.js`, `PricingService`, fiscal. Contrôle = admin.

---

## §2 — Separated

Mobile / web standalone : no wireup. `ItemController` never-touch.

---

## §3 — Système 1 : Preview « disponible » Le Cayenne

### Contract
Le gérant voit Tacos/Burger **vendable** sur Filiale **Le Cayenne (id=1, status=5)**. « Indisponible » seulement si l’item l’est vraiment.

### Commentaire déjà dans le code (ne pas ignorer)
`ItemPreviewComponent.vue:279-294` : `BranchService::list()` **id desc** → Collier (id=10) en premier. `MenuProjectionService::forChannel('pos', 1)` contient le produit. Défaut = `status===5`.

**PNG round-4** : Filiale déjà « Le Cayenne (principal) » **et** encore « Article non disponible sur cette succursale ». Donc P1 restant ≠ Collier : **item passé au preview** (catégorie sans SKU ? item sans dispo branche 1 ?).

### Frozen
Aucun §7.

### Sub 1.1 — Diagnostic
**T-1.1.1** Tracer `item.id` du category composer (cat 5 / 4 / 2) vs Tacos XL **234**.  
• anchor: `ProductComposerEditorComponent.vue:239-247`  
• test: `(to be created at tests/Feature/Grok/ComposerPreviewLeCayenneAvailableTest.php)` — GET projection pos `branch_id=1` item 234 **200 + disponible**  
• visual: `round-4/wave-D-burgers/02-wizard.png` (état rouge)  
• vitest existant à étendre : `tests/js/itemPreviewProjection.spec.js` (Collier id=10 vs Cayenne id=1)

**T-1.1.2** Si `item` null/faux sur wizard **catégorie** : binder le 1er item ACTIVE du rayon (Tacos XL 234, Galette Classique, pas inventé).  
• test: même fichier Feature + Vitest `(to be created at tests/js/composerPreviewBindsFirstActiveItem.spec.js)`

### Sub 1.2 — Heal + Mix
**T-1.2.1** Patch scope-minimal SHARED déclaré JOURNAL. Mix `npx mix` (patch laravel-mix déjà là).  
• test: Feature T-1.1.1 passe rouge→vert

**T-1.2.2** E2E Burgers wizard : badge **pas** Indisponible. 2 rounds.  
• visual: `reports/test-e2e/grok-dashboard-cockpit-10j/round-5/wave-D-burgers/`

### Sub 1.3 — Inactive steps preview
Till ignore déjà `is_active=false` (`ComposerProfileProjection.php` filter). Preview admin **liste encore** pain inactif.  
**T-1.3.1** Preview n’affiche que steps actifs **ou** les marque Inactive (pas comme parcours client).  
• test: `ComposerMerchantLiesTest.php` `test_inactive_step_is_absent_from_till_projection` (till) + Vitest preview  
P2 si P1-A non fermé en premier.

---

## §4 — Système 2 : Interrupteur caissier

### Contract
Admin GET+PUT. POS Operator **403** PUT **et** GET (middleware constructor:30).

### Anchors
`InterrupteurController.php:25-41`  
`SystemHealthComponent.vue` confirm

### Tasks
**T-2.1** HTTP Sanctum POS Operator PUT `actif` → 403, état inchangé.  
• test: `tests/Feature/Grok/CashierCannotToggleInterrupteurTest.php` **TO BE CREATED** (restore flag)

**T-2.2** Admin PUT + GET round-trip + restore.  
• test: même classe `test_admin_round_trip_restores`

**T-2.3** E2E caissier : pas de page interrupteur / 403 réseau.  
• visual: `/admin/observability/system` as `pos@lecayenne.fr` → dashboard (déjà Wave E) + network PUT 403 si URL forcée

---

## §5 — Système 3 : Recapture wizards (P1-B)

### Contract
Round-5 **identique** round-4 (sauf P1-A preview). Parent lit.

| Tâche | Rayon | PNG round-4 | round-5 |
|---|---|---|---|
| T-3.1 | Sandwichs + Viande 2 | `round-4/wave-D-sandwichs/02-wizard.png` | `round-5/wave-D-sandwichs/` |
| T-3.2 | Burgers 5 pages | `round-4/wave-D-burgers/02-wizard.png` | `round-5/wave-D-burgers/` |
| T-3.3 | Bols sauce bol | `round-4/wave-D-bols-frites/01-bols-wizard.png` | `round-5/wave-D-bols/` |
| T-3.4 | Frites PAGES 0 | `.../03-frites-wizard.png` | `round-5/wave-D-frites/` |
| T-3.5 | Galette 5 pages | `round-3/wave-D-galette/02-galette-wizard.png` | `round-5/wave-D-galette/` |

**Acceptance** : notes JSON step_keys inchangés. Filiale Le Cayenne. **Pas** de publish.

Viande_2 : round-5 **confirme** la page, ne la supprime pas.

---

## §6 — Système 4 : Certificat

### Tasks
**T-4.1** V0 smoke : phpunit filtre HANDOFF + vitest 6 + `git diff --stat -- <frozen>`  
**T-4.2** Adversaire + reasoner après V1–V3  
**T-4.3** Rejeu **complet** 81+39 (ou N+ si nouveaux tests, 0 fail)  
**T-4.4** Grok écrit certificat **avec commandes collées** + mix hash + PNG paths + BLOCK viande_2  
• livrable: `reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md`  
**T-4.5** Si une case §0.6 → `REFUS_VALIDATION_ABSOLUE.md` (la case, pas un roman)

---

## §A — Agents

| Rôle | type | Quand |
|---|---|---|
| Cartographe | `explore` | T-1.1.1 item.id preview |
| Implementer | parent / `code-editor` | 1 writer |
| e2e | `e2e-hunter` | isolé 8766 |
| Parent | Grok | **lit PNG** |
| adversary | `adversary` | après heal |
| reasoner | `reasoner` | avant tampon |

Reports : `reports/test-e2e/grok-dashboard-cockpit-10j/round-5/`

---

## §X — Waves

Checkpoint Axis 3 à chaque fin. Interrupt : `INTERRUPT_VA_<ts>.md`.

| Wave | Scope | Parallel | Checkpoint |
|---|---|---|---|
| **W0** | T-4.1 smoke | none | 81+39 + frozen 0 |
| **W1** | §3 Preview P1-A | explore ∥ puis writer | HTTP vert + Mix + PNG Burgers disponible ×2 |
| **W2** | §4 Interrupteur | none | Cashier 403 test + E2E |
| **W3** | §5 Recapture 5 wizards | hunters **disjoint** | round-5 = round-4 keys |
| **W4** | adversary + reasoner | ∥ read | P0+P1=0 hors P1-D |
| **W5** | T-4.3 rejeu + T-4.4 certificat | none | CERTIFICAT ou REFUS |

Heal max 3 / cluster. 4e → `reports/test-e2e/grok-dashboard-cockpit-10j/STUCK_VA_<ts>.md` + owner A/B/C/D.

### Checkpoint (6 cases, chaque vague)

- [ ] Tâches PASS ou fail baseline documenté
- [ ] Frozen list §0.3 = 0
- [ ] `audit_logs` non truncaté
- [ ] PNG lus par **Grok**
- [ ] adversary P0+P1=0 hors P1-D
- [ ] JOURNAL (pas BRAIN)

### Interrupt

`reports/test-e2e/grok-dashboard-cockpit-10j/INTERRUPT_VA_<ts>.md` :

```
wave: W<n>
last_task: T-x.y.z
status: PASS|FAIL|IN-PROGRESS
next_task:
phpunit: <N OK | fail>
mix: <hash admin-shell>
pngs_parent_read:
```

Reprise : smoke W0 → last_task → next. **Pas** de 4e heal silencieux.

---

## §G — Gates

| Gate | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|
| G-SHARED | Grok | claim JOURNAL avant write `ItemPreviewComponent.vue` | `reports/grok/JOURNAL.md` | PENDING W1 |
| G0 commit | owner | fichiers listés | git | PENDING |
| G-V2 viande_2 | owner/Claude | tranche 1 ou 2 | HANDOFF §3 | **BLOCK Sandwichs parfait** — n’empêche pas le tampon **si cité** |
| G-demo | owner | pas de flip | `.env` | false |
| G-1490 | owner | pas worker | queue | — |
| G-wipe | owner | pas DELETE junk | DB | — |
| G-frozen | — | LOCK si besoin | `docs/locks/` | n/a |

---

## §R

- Mission : `docs/grok/MISSION_VALIDATION_ABSOLUE_GROK_2026-09-01.md`  
- HANDOFF, JOURNAL, FRONTIERES  
- GOAL cockpit : `plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md`  
- `patches/laravel-mix+6.0.49.patch`  
- Comptes : `admin@lecayenne.fr` / `pos@lecayenne.fr` / `123456` · BASE 8766

---

## §F — Final rule

Grok tamponne **Dashboard + catalogue + composeur admin + RBAC** seulement.  
Kiosk/POS frozen = hors tampon.  
Claude reçoit CERTIFICAT + HANDOFF puis déploie.

**Discipline** : test rouge d’abord · 1 writer · parent lit PNG · 2 rounds · frozen 0 · pas de théâtre 20×.

```
lance le GOAL
```
