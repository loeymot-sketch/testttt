# MISSION — Validation absolue Grok (résultat max)

**Date** : 2026-09-01  
**Validateur** : **Grok** (pas un sous-agent, pas Claude). Le tampon « ABSOLU » n’existe que si **Grok** a relancé les tests **et** lu chaque PNG.  
**Architecte / deploy** : Claude **après** ce tampon.  
**Baseline** : `docs/grok/HANDOFF_CLAUDE_2026-09-01.md`  
**Reste à faire** : ce fichier.  
**GOAL (discipline)** : `plans/GOAL_VALIDATION_ABSOLUE_GROK_2026-09-01.md`  
**GOAL cockpit (contexte)** : `plans/GOAL_DASHBOARD_COCKPIT_MAXOPT_2026-08-31.md`

```
GO VALIDATION_ABSOLUE
```

---

## 0. Ce que « validation absolue » veut dire

Pas « ça a l’air bien ». Pas 20 cycles cosmétique. Un **certificat** :

`reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md`

Grok le signe seulement si **toutes** les cases §5 sont vraies **le jour du tampon** (rejeu, pas mémoire de session).

**Refus automatique** si une seule :

- PNG non lu par Grok (caption hunter ≠ preuve)
- PHPUnit Grok ≠ 81 OK (ou plus si nouveaux tests, **0 fail**)
- Vitest 6 fichiers ≠ 39 OK (ou plus, 0 fail)
- Frozen `git diff --stat` non vide sur kiosk Vue / `pos-wizard.js` / Payment / Pricing / FiscalSequence
- `CONVERGENCE_FINAL` écrit sans 2 rounds identiques P0+P1=0
- 2xx sans mutation composeur
- Produit inventé
- `git add .` / push / wipe / flip `FEATURE_WIZARD_PER_ITEM_DEMO`

---

## 1. Déjà prouvé (2026-09-01) — ne pas re-prouver pour se faire plaisir

Relancé **ce jour** :

```
PHPUnit Grok  81 tests, 257 assertions  OK
Vitest        39 tests                  OK
Mix           admin-shell.304ba3ff.js   OK (patch laravel-mix SizeFormatHelpers)
Frozen §7     0 ligne
```

PNG parent-lus (ne pas recapturer sauf régression) :

| Sujet | Fichier |
|---|---|
| Caissier ≠ cockpit 2+ rounds | `.../grok-dashboard-2026-08-29/CONVERGENCE_WAVE_E.md` |
| Studio 14 / KPI 55 | `CONVERGENCE_IMPROVE_34.md` |
| Dashboard audit 20 | `cockpit-10j/round-2/wave-A/01-dashboard.png` |
| Tacos wizard | `cockpit-10j/round-2/wave-D/` |
| Galette ≠ Tacos | `cockpit-10j/round-3/wave-D-galette/` |
| Burgers / Sandwichs / Bols / Frites | `cockpit-10j/round-4/` |

---

## 2. Trous qui **empêchent** le tampon aujourd’hui

Sans ça, Grok refuse « validation absolue ».

### P1-A — Preview « Article non disponible » / Le Cayenne
Même Filiale **Le Cayenne (principal)**, caisse+borne **Indisponible**. Mensonge : le gérant croit que Tacos/Burger n’est pas vendable.  
Ancre : `ItemPreviewComponent.vue` + API preview. SHARED à déclarer.  
Test : `(to be created)` HTTP preview cat 5 / item 234 / `branch_id=1` → **disponible** si item ACTIVE.  
E2E : recapture wizard Burgers **après** fix, 2 rounds.

### P1-B — 2e round post-Mix wizards
Round-4 = **1** capture. Absolu = **2e** round identique Sandwichs / Burgers / Bols / Frites / Galette (P0+P1=0, même set).

### P1-C — Interrupteur caissier
PHP pin rôles OK. Manque E2E+HTTP : `pos@lecayenne.fr` PUT interrupteur → **403**.  
Test : `(to be created at tests/Feature/Grok/CashierCannotToggleInterrupteurTest.php)` puis restore.

### P1-D — Viande 2 Sandwichs
**Pas un bug à « fixer » tout seul.** Méga/Terminator = 7 choix ; Cayenne = 0.  
Grok **ne tamponne pas** « Sandwichs parfait » tant que owner/Claude n’a pas tranché (garder 2 viandes + preview par SKU **ou** 1 viande + publish).  
Jusque-là : **documenté**, pas un fail du tampon global si le certificat liste ce BLOCK.

### P2 (n’empêchent pas le tampon si listés)

Login 1× `/storage/1/english.png`. Debugbar. 64 junk SQL. 1490 jobs. Même photo Tacos M/L/XL. Preview liste encore steps Inactive (till les ignore déjà).

---

## 3. Ordre d’attaque (max résultat, min théâtre)

```
V0  Rejeu PHPUnit 81 + Vitest 39 + frozen diff     ← smoke
V1  P1-A preview Le Cayenne (test rouge → patch → Mix → E2E ×2)
V2  P1-C interrupteur POS 403 (test rouge → patch → E2E)
V3  P1-B recapture 5 wizards round-5 = round-4
V4  Adversaire + reasoner sur V1–V3
V5  Rejeu COMPLET 81+39 + frozen + Grok LIT tous les PNG V1–V3
V6  Écrire CERTIFICAT_VALIDATION_ABSOLUE.md  ou  REFUS.md
```

Un writer. Hunters Playwright CLI isolés. BASE `http://127.0.0.1:8766`.  
Admin `admin@lecayenne.fr` · Caissier `pos@lecayenne.fr` · `123456`.  
**Pas** de commande NF525. **Pas** de publish live wizard.

---

## 4. Tests : durcir (pour que « 81 » reste un mensonge faible)

Avant tampon, **ajouter** (rouge d’abord) :

1. `CashierCannotToggleInterrupteurTest.php`  
2. Preview branch 1 disponible (Feature Grok)  
3. **Un** test HTTP dashboard `total-menu-items` live ≥ 1 (pas seulement string-contains) — déjà partiel dans `AuditPollutionCategoriesHiddenTest`  
4. Ne **pas** « réparer » `AuditTrailUsesAuditLogSentinelTest` en fail-open `dashboardBranchId`

`DashboardControlLoopOptimizationsTest` reste un **filet source**. Le tampon s’appuie sur HTTP + PNG, pas sur ce filet seul.

---

## 5. Checklist tampon (Grok coche le jour J)

- [ ] `phpunit --filter=<liste HANDOFF>` → OK, 0 fail  
- [ ] Vitest 6 fichiers → OK  
- [ ] Frozen 7 fichiers = 0  
- [ ] Mix hash `admin-shell` lu dans `mix-manifest.json`  
- [ ] PNG P1-A lus (preview **disponible** ou fail expliqué)  
- [ ] PNG round-5 wizards lus, identiques round-4 (sauf preview fix)  
- [ ] Interrupteur POS 403 HTTP **et** E2E  
- [ ] Viande 2 : BLOCK owner **écrit** dans le certificat (pas oublié)  
- [ ] Adversaire P0+P1 = 0 **hors** BLOCK documentés  
- [ ] JOURNAL mis à jour  
- [ ] **Pas** `CONVERGENCE_FINAL` produit entier (kiosk/POS frozen hors scope)

Tampon = **vert ciblé Dashboard + catalogue + composeur admin + RBAC**, pas « tout FoodKing ».

---

## 6. Après le tampon — Claude

Coller `HANDOFF` + `CERTIFICAT_VALIDATION_ABSOLUE.md`.  
Claude : viande_2, BRAIN conflict, commit fichier par fichier, deploy.  
Grok ne push pas.

---

## 7. Interdits (copie FRONTIERES)

Frozen §7. Wipe. Inventer un produit. Prix Vue. Worker 1490. Flip demo wizard. 4e fail-open menu.

---

## 8. DONE

Fichier `reports/grok/CERTIFICAT_VALIDATION_ABSOLUE.md` daté, commandes collées, chemins PNG, SHA mix, frozen 0.  
Sinon `reports/grok/REFUS_VALIDATION_ABSOLUE.md` avec **la** case qui a fait échouer.

```
GO VALIDATION_ABSOLUE
```
