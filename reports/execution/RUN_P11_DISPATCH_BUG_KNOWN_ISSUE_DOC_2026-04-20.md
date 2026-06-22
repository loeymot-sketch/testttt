# RUN — P11_DISPATCH_BUG_KNOWN_ISSUE_DOC — 2026-04-20

**Task:** Formal KNOWN_ISSUE doc for dispatch-after-commit (docs + execution report only).

## Paths des fichiers créés

| Path | Role |
|---|---|
| `docs/known-issues/.gitkeep` | Initialise le dossier `docs/known-issues/` (suivi Git) |
| `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` | KI-001 documentation complète |
| `reports/execution/RUN_P11_DISPATCH_BUG_KNOWN_ISSUE_DOC_2026-04-20.md` | Ce rapport d’exécution |

## Vérification croisée 1 — call-sites (`bash scripts/check-invariants.sh -v` → grep `"4/6 App"`)

**Résultat:** `FAIL (8 hit(s))` — **8 hits**, aligné avec le plan sur le volume.

**Divergence vs table du plan (copiée depuis le plan initial):** une seule ligne.

- Le plan listait `FrontendOrderService.php:848` comme `OrderCreated::dispatch(...)`.
- **Sortie réelle:** `app/Services/FrontendOrderService.php:848` → `OrderStatusChanged::dispatch($frontendOrder, $oldStatus, $newStatus);`

Toutes les autres lignes correspondent au plan :

- `OrderService.php` : 541, 961, 1266 (`OrderCreated`), 1423, 1478, 1575 (`OrderStatusChanged`)
- `FrontendOrderService.php` : 842 (`OrderCreated`)

Le document `KI_001_*` a été rédigé avec la ligne **848 = `OrderStatusChanged::dispatch(...)`** (valeurs réelles).

## Vérification croisée 2 — classes Event

| Fichier | `implements ShouldDispatchAfterCommit` ou `use ...ShouldDispatchAfterCommit` |
|---|---|
| `app/Events/OrderCreated.php` | **Non** — classe sans interface ; pas d’import du contrat |
| `app/Events/OrderStatusChanged.php` | **Non** — idem |
| `app/Events/ItemAvailabilityChanged.php` | **Non** — idem |

**SURPRISE:** aucune — comportement conforme au plan (tous ❌ NO dans la table Affected events). Pas de réouverture V4 #8 / V5 #3 requise pour ce point.

## Extraits clés du KI (3–5 sections)

### TL;DR (extrait)

> Three broadcast events (`OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`) are dispatched **immediately** during a database transaction, instead of **after the transaction commits**. If the transaction subsequently rolls back, the broadcast already left the application — KDS/OSS/Kiosk surfaces show "ghost" orders/status changes that don't exist in the database.

### Table — Affected events (résumé)

| Event class | Implements `ShouldDispatchAfterCommit` ? |
|---|---|
| `OrderCreated` | ❌ NO |
| `OrderStatusChanged` | ❌ NO |
| `ItemAvailabilityChanged` | ❌ NO |

### Table — Confirmed call-sites (8 lignes, dont correction 848)

| File | Line | Pattern (abrégé) |
|---|---|---|
| `OrderService.php` | 541, 961, 1266 | `OrderCreated::dispatch` |
| `OrderService.php` | 1423, 1478, 1575 | `OrderStatusChanged::dispatch` |
| `FrontendOrderService.php` | 842 | `OrderCreated::dispatch` |
| `FrontendOrderService.php` | 848 | `OrderStatusChanged::dispatch` ← **corrigé vs plan** |

### Active sentinels (résumé)

- Runtime: `tests/Feature/DispatchAfterCommitTest.php` — volontairement rouge jusqu’à remediation.
- Static: `scripts/check-invariants.sh` [4/6] — 8 hits, exit 1, usage local (pas CI).

## Confirmation périmètre

- **Aucun fichier modifié en dehors de** `docs/known-issues/` **et** `reports/execution/` (création uniquement : `.gitkeep`, `KI_001_*`, ce RUN).
- **Pas** de `git add` / **pas** de `git commit`.
- Classes Event, services, tests, scripts : **non modifiés** (conforme aux interdits).

---

**Statut final:** **SUCCESS**

**EXECUTE_DELEGATION:** foodking-routine-implementer (Composer)

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Fichiers créés | `docs/known-issues/.gitkeep` + `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` (107 lignes) |
| 2 | Structure conforme au plan | 10 sections : TL;DR, Affected events, Call-sites, Production impact, Active sentinels, Remediation plan, Workarounds, Detection, Closure criteria + headers |
| 3 | Vérification croisée 1 effectuée | call-sites réels vs plan : 8/8 hits, **1 divergence corrigée** (FrontendOrderService:848 = `OrderStatusChanged::dispatch`, pas `OrderCreated::dispatch`) |
| 4 | Vérification croisée 2 effectuée | 3 events × `ShouldDispatchAfterCommit` = 3 NON, pas de surprise |
| 5 | Cross-check ligne 848 par orchestrateur | `sed -n '845,850p' app/Services/FrontendOrderService.php` confirme `OrderStatusChanged::dispatch($frontendOrder, $oldStatus, $newStatus)` — doc cohérent |
| 6 | Aucun fichier hors `docs/known-issues/` + `reports/execution/` | confirmé via `git status` |

**Découverte significative** : la divergence line 848 montre que **`OrderStatusChanged` est aussi affecté côté frontend (FrontendOrderService)**, pas seulement OrderCreated comme estimé initialement V4 #8. Les commandes kiosk publiques sont concernées par le bug ghost-status, pas seulement les commandes admin/POS.

**Impact pour V5 #1** : le scope FILES TOUCHED des 3 Event classes reste correct, mais le doc V5 #1 mérite d'être mis à jour pour préciser que `FrontendOrderService` (kiosk public flow) fait partie des call-sites impactés.

**Valeur produite** :
- KI-001 = première entrée formelle d'un système de tracking known-issues
- Onboarding facilité : un dev nouveau peut comprendre le bug en 5 min de lecture
- Référence stable pour le gate humain C9 et les ops production
- Pattern reproductible pour futurs known-issues
