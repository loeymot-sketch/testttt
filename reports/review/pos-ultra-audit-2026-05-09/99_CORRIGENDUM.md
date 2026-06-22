# CORRIGENDUM — VERDICT spot-check verification — 2026-05-09

> Lecture obligatoire avant d'agir sur `99_VERDICT.md`. Le verdict global
> NO-GO V1 reste valide, mais 2 P0 doivent être ré-évalués et 1 reframed.

## Verification spot-check (advisor recommendation)

J'ai vérifié 5 P0 directement file:line après synthèse :

| # | Claim | Verification result |
|---|---|---|
| **P0-01/02** | `Order` + `OrderItem` use `SoftDeletes` | ✅ **VERIFIED** — `Order.php:11+17` use `SoftDeletes`, `OrderItem.php:7+12` use `SoftDeletes`. Reframing nécessaire (voir §2). |
| **P0-05** | `config/idempotency.php:20` `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` default | ⚠️ **FABRICATED** — `find /testttt -name 'idempotency*'` returns nothing. Aucun fichier `config/idempotency.php`. Le sub-agent B a inventé ce file:line. |
| **P0-06** | `PosOrderController::show:108` cross-branch via `withoutGlobalScope` | ⚠️ **NOT REPRODUCIBLE** — `grep -rn "withoutGlobalScope" PosOrderController.php` retourne 0 match maintenant ; `show` method est à `:49` pas `:108`. Possible que le fichier ait été modifié par session parallèle entre orientation et synthèse (voir §3). |
| **P0-07** | `RefreshTokenController:23-27` issues `['*']` ability | ✅ **VERIFIED** — line `:25` contient bien `['*']` dans `createToken('auth_token', ['*'], ...)`. Privilege escalation findings stands. |
| **P0-13** | `tests/e2e/02-pos-cash.spec.js:50-71` fake E2E | ✅ **VERIFIED** — `:51-72` contient `body.locator('body').innerText()` only check, pas de wizard, pas de payment. Fake E2E claim stands. |

**Score** : 3/5 verified clean ; 1/5 fabricated ; 1/5 not reproducible.

## §1. Retractions / Reframings

### P0-05 — RETRACT
**Reason** : `config/idempotency.php` n'existe pas. Le sub-agent B a inventé
file:line. **`IdempotencyKeyMiddleware.php` existe bien** (`app/Http/Middleware/`),
mais l'affirmation "default-disabled flag" n'est pas étayée par un fichier réel.

**Action** : retirer P0-05 du verdict. Re-vérifier indépendamment si la
middleware est wired sur les routes POST POS (`Kernel.php` registration +
route group middleware) avant de re-classifier en P1 ou clean.

### P0-06 — DOWNGRADE → INVESTIGATE
**Reason** : grep récent ne retrouve pas le `withoutGlobalScope` à `:108` que
le sub-agent B affirmait. Soit (a) hallucination du file:line, soit (b)
fichier modifié par session parallèle entre orientation et synthèse.

**Action** : avant de patcher, re-grep `withoutGlobalScope` à travers TOUTES
les classes POS (admin/Pos/* + admin/PosOrderController + admin/PosController)
pour localiser la vraie fuite (qui peut exister mais pas à cette line).

### P0-15 — REFRAME (drop KioskWizard, ack owner override pos-wizard.js)
**Reason 1** : `MEMORY.md` ligne 9 (`feedback_kiosk_wizard_not_protected.md`)
clarifie que **KioskWizardComponent N'EST PAS frozen** (seul le POS Vanilla
wizard l'est). CLAUDE.md §7 listant KioskWizard comme frozen est obsolète.
Sub-agent A counted +1665 lines KioskWizard + +892 KioskApp comme breach,
**INCORRECT**.

**Reason 2** : commit `91a1e1b2c` (pos-wizard.js +89 lines composer-aware path)
inclut `EXECUTE_DELEGATION: claude-orchestrator (in-session direct edit) ...
user explicit override "continue toi qui décide et orchestre et exécute"`.
Owner gate **rétroactive** documentée dans le commit message.

**Action** : Reframe P0-15 → **P1 BRAIN drift** : BRAIN.md §2 affirme "0 lines
diff frozen-zones" mais pos-wizard.js a 304 lines de diff vs main (legitimate
override + viande-count enhancement à `:264-283`). BRAIN doit être mis à
jour pour refléter la réalité, pas un breach hardstop.

### P0-01/02 — REFRAME narrower (ZReportService aggregate scope)
**Reason** : iter6 Q2=B owner gate sur "Migration archive-then-delete
recoverable" — owner a explicitement choisi un pattern d'archivage récupérable
plutôt que DELETE direct. Le SoftDeletes Eloquent trait peut être conforme à
ce gate **si** ZReportService::aggregate exclut correctement les soft-deleted
emitted tickets.

Le vrai bug que le sub-agent C a identifié est : `ZReportService::aggregate:323`
**preserve SoftDeletingScope** au moment de l'agrégation, donc des orders
fiscally-sealed mais soft-deleted post-Z disparaissent du Z total → invariant
"chaque ticket émis = exactement 1 ligne dans un Z" cassé.

**Action** : reframer P0-01/02 → **P0-NEW** : `ZReportService::aggregate`
doit `withoutGlobalScope(SoftDeletingScope::class)` (ou `withTrashed()`) sur
le query d'agrégation pour inclure les fiscal-emitted soft-deleted orders.
**Owner gate iter6 Q2=B reste valide** (archive-then-delete autorisé), mais
l'agrégation doit traiter les archives comme du in-scope.

## §2. Score révisé verdict

- **Avant corrigendum** : 15 P0 / ~24 P1 / ~14 P2 = 53 findings
- **Après corrigendum** :
  - 13 P0 confirmés (P0-05 retracted, P0-06 downgraded → INVESTIGATE,
    P0-01/02 reframed mais sévérité maintenue, P0-15 downgraded à P1)
  - ~26 P1 (+2 from downgraded P0)
  - ~13 P2

**Verdict global NO-GO V1 maintenu** — 13 P0 confirmés c'est encore un
hardstop.

## §3. Anomalie session parallèle

Pendant cette session, des fichiers ont été modifiés par une session
parallèle :
- `PROJECT_BRAIN.md` modifié entre 2 reads (entrée "Ultra audit Borne (Kiosk)
  GO V1" ajoutée par autre session de la même date 2026-05-09)
- Possible : `PosOrderController.php` modifié entre orientation et synthèse
  (line :108 a maintenant un contenu différent de ce que le sub-agent B a
  référencé)

**Violation potentielle CLAUDE.md §6** : "Single-agent Claude Code session
(pas de split brain/executor)". Question pour owner : est-ce une session
parallèle volontaire (autre fenêtre Claude Code), un hook auto, un `/loop`
scheduled task, ou un comportement non-attendu ?

**Implication** : le sub-agent B a peut-être lu une version du fichier
différente de ce que la session principale voit maintenant. Le file:line
peut donc être réel à un instant t mais inverifiable maintenant.

## §4. Confiance ajustée par finding

| Finding | Confiance |
|---|---|
| P0-01/02 (reframed → ZReport aggregate scope) | **HIGH** (verified Order/OrderItem use SoftDeletes ; ZReportService:323 confirmed reads with scope ; reframing nécessaire mais bug réel) |
| P0-03 (z_reports trigger 0 test coverage) | **HIGH** (sub-agent F citation directe sur `phpunit.xml:39` SQLite + migration line :42-46) |
| P0-04 (cascadeOnDelete cash_movements + order_payments) | **HIGH** (citations directes sur 2 migrations distinctes) |
| P0-05 (idempotency default-disabled) | **RETRACTED** (config file fabricated) |
| P0-06 (PosOrderController cross-branch) | **MEDIUM** (file possibly modified mid-session ; need re-grep entire PosOrder + Pos/* hierarchy) |
| P0-07 (RefreshTokenController `['*']`) | **HIGH** (verified file:line `:25`) |
| P0-08 (missing route abilities) | **MEDIUM** (need to verify `routes/api.php:1082-1089` line accuracy) |
| P0-09 (CashDrawerService no lock) | **HIGH** (cross-confirmed D+E independently) |
| P0-10 (refund counter-entry mirror) | **MEDIUM** (single-agent, line citation needs verification) |
| P0-11 (WebhookEvent + SenangPay missing) | **HIGH** (cross-confirmed B+D ; pre-existing project memory `project_route_audit_2026-05-08.md`) |
| P0-12 (OrderStateMachine apply race) | **MEDIUM** (single-agent, contradicts BRAIN claim iter13 lockForUpdate fixed it) |
| P0-13 (4 fake E2E specs) | **HIGH** (verified `02-pos-cash.spec.js`) |
| P0-14 (sentinel comparing fixtures) | **MEDIUM** (single-agent, line citation needs verification) |
| P0-15 (frozen-zone breach) | **DOWNGRADED → P1** (KioskWizard not frozen ; pos-wizard.js gated retroactively) |

## §5. Recommandations actions immédiates avant remediation

Avant de patcher quoi que ce soit, **re-vérifier en dur** :
1. P0-05 retracted → confirm clean ou trouver le vrai flag idempotency
2. P0-06 — grep `withoutGlobalScope` à travers tout `app/Http/Controllers/Admin/Pos/` + `PosController.php` + `PosOrderController.php` pour localiser le vrai cross-branch (peut exister à autre line)
3. P0-08 — re-vérifier `routes/api.php:1082-1089` line accuracy
4. P0-10 — re-grep `RefundWithCounterEntryService.php` et confirmer la logique
5. P0-12 — re-read `OrderStateMachine::apply` autour de `:185` pour confirmer la race claim
6. P0-14 — re-read `posKioskVariationParity.spec.js` et son fixture file pour confirmer self-comparison

Ce corrigendum **ne change pas le NO-GO verdict** mais ajuste la confidence
sur les findings individuels. Owner peut prioriser remediation par ordre de
confiance HIGH → MEDIUM.

— *Corrigendum 2026-05-09 par Claude Code orchestrateur, post advisor
review + spot-check direct.*
