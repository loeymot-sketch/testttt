# EXECUTE V4 #11 — P13_ADMIN_CROSS_BRANCH_DOC

TASK_ID: P13_ADMIN_CROSS_BRANCH_DOC
WAVE: V4 salve 4b (doc seule, zéro risque)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: PLAN_POST_VERIFY §1.4 cycles supp. + besoin "centralisation globale"

---

## Goal

Produire un document Markdown SSOT qui répond à la question : **quelles routes admin (`app/Http/Controllers/Admin/`) sont bornées par `branch_id` vs lesquelles sont volontairement cross-branch (rapports consolidés, super-admin), et quelles sont les non-classées (= risque) ?**

Pas de code applicatif — analyse statique + rédaction.

---

## Scope

| Fichier | Action |
|---|---|
| `docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md` | **NEW** — doc structurée. |
| `docs/centralisation/.gitkeep` (si dossier inexistant) | **NEW** si nécessaire. |

**SUBSYSTEMS_TOUCHED**: documentation only.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif. AUCUN fichier `app/`, `routes/`, `tests/`, `database/` ne doit être modifié — uniquement lus.
**INVARIANTS_AT_RISK**: aucun (docs).

---

## Méthode (analyse statique)

### Étape 1 — Inventaire des controllers admin
```bash
ls app/Http/Controllers/Admin/*.php
```
Liste des controllers à classer.

### Étape 2 — Pour chaque controller, classifier

Pour chaque controller, faire un grep ciblé :
```bash
grep -nE "branch_id|->branch\(\)|scopeBranch|currentBranch|forBranch" app/Http/Controllers/Admin/<Controller>.php app/Services/<MatchingService>.php 2>/dev/null
```

Classer dans **3 catégories** :
- **A) Bornée `branch_id`** : le controller / service filtre explicitement par `branch_id` (scope Eloquent, where, ou auth user → branch).
- **B) Volontairement cross-branch** : super-admin only, rapports consolidés, settings globaux (catalogue items partagés, rôles, …).
- **C) Non classé / ambigu** : pas de filter visible mais accessible aux non-super-admin → **RISQUE potentiel**, à investiguer dans un cycle ultérieur.

### Étape 3 — Vérifier `docs/AUTHZ_MATRIX.md` existant
Si le doc existe déjà (cf. mention dans `.cursor/skills/project-handoff/SKILL.md`), le **lire** et lister les écarts entre le code observé (étape 2) et le doc déclaré. Ne pas modifier `AUTHZ_MATRIX.md`, juste référencer.

### Étape 4 — Rédiger `ADMIN_CROSS_BRANCH_MAP_2026-04-20.md`

Structure attendue :

```markdown
# Admin cross-branch map — 2026-04-20

> Snapshot statique. Source : analyse `app/Http/Controllers/Admin/*.php` + services associés.
> Cible : centralisation globale FoodKing — cohérence multi-branch.

## A. Controllers bornés par `branch_id`
| Controller | Méthode de filter | Service associé | Note |
|---|---|---|---|

## B. Controllers volontairement cross-branch
| Controller | Justification | Rôles requis |
|---|---|---|

## C. Controllers non classés (à investiguer)
| Controller | Pourquoi ambigu | Action proposée |
|---|---|---|

## D. Écarts vs `docs/AUTHZ_MATRIX.md`
[liste à puces si AUTHZ_MATRIX existe ; sinon "AUTHZ_MATRIX.md non trouvé / non lisible"]

## E. Recommandations cycles futurs
- (catégorie C) → cycles à créer si risque confirmé
- (gaps AUTHZ_MATRIX) → cycle de mise à jour doc
```

### Borne du scope

- **Ne pas** lire chaque controller en entier. 30-50 lignes du haut + scan `grep` ciblé suffisent.
- **Ne pas** classer les ~80+ controllers admin en détail si trop volumineux : se limiter aux controllers les plus sensibles (orders, transactions, KDS, drawer, fiscal, items, settings). Lister les non-couverts en annexe "à classer plus tard".
- **Budget temps** : ~30 min de lecture + rédaction. Si dépasse 1h, **terminer et écrire `PARTIAL_COVERAGE` dans le RUN report**.

---

## VALIDATE

1. Le fichier `docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md` existe et fait **>= 200 octets** (sinon failed par notre nouveau script `scripts/check-audit-report-integrity.sh` — ouf, il ne couvre que `reports/`, mais bonne pratique).
2. Le fichier comporte les 5 sections A/B/C/D/E.
3. Chaque section A/B/C contient au moins 1 entrée OU mention explicite "aucun controller dans cette catégorie".
4. `git status --short` : `?? docs/centralisation/ADMIN_CROSS_BRANCH_MAP_2026-04-20.md` (+ `?? docs/centralisation/.gitkeep` si créé) + `?? reports/execution/RUN_P13_ADMIN_CROSS_BRANCH_DOC_2026-04-20.md`. Aucun fichier `app/` modifié.

---

## REPORT_FILE

`reports/execution/RUN_P13_ADMIN_CROSS_BRANCH_DOC_2026-04-20.md` — résumé chiffré (X controllers en A, Y en B, Z en C) + liste des cycles futurs recommandés (catégorie C confirmée).

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `docs/AUTHZ_MATRIX.md` existant.
- ❌ NE PAS modifier le moindre fichier `app/`, `routes/`, `tests/`, `database/`.
- ❌ NE PAS proposer de patch dans le doc — seulement analyse + recommandations de cycles futurs.
- ❌ NE PAS générer un fichier > 1500 lignes (signe d'over-engineering pour un cycle 0.5 j-h).
- ❌ Pas de `git add/commit`.
