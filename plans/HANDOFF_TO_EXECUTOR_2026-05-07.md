# HANDOFF AU CLAUDE EXÉCUTEUR — Audit Ultra Review POS+Kiosk
**Date :** 2026-05-07
**De :** Claude orchestrateur (chef d'audit)
**À :** Claude Opus exécuteur (next session)
**Mission :** Exécuter les 14 findings de l'audit dans l'ordre, avec discipline stricte

---

## 📋 ORDRE DE LECTURE (avant tout code)

> Tu lis dans cet ordre EXACT. Pas de saut.

1. **CE FICHIER** (HANDOFF) — `plans/HANDOFF_TO_EXECUTOR_2026-05-07.md`
2. **Master plan exécuteur** — [`plans/PLAN_EXECUTOR_MASTER_v2_2026-05-07.md`](plans/PLAN_EXECUTOR_MASTER_v2_2026-05-07.md)
3. **Sub-plan du finding courant** (commence par F-001)
4. **Vérifier drift** : grep/sed sur le code pour confirmer que les line numbers cités matchent encore
5. **Lire les mémoires pertinentes** :
   - `memory/feedback_gstack_pipeline_methodology.md` (pipeline obligatoire)
   - `memory/feedback_wizard_popup_pos_protected.md` (POS wizard frozen)
   - `memory/feedback_kiosk_wizard_frozen_tests_allowed.md` (Kiosk wizard tests OK)
   - `memory/reference_frozen_zones.md` (liste complète)
   - `memory/project_audit_f003_decision_option_a.md` (décision F-003)

---

## 🔢 ORDRE D'EXÉCUTION DES 14 PLANS

> Chaque finding est traité **complètement** (Think → Plan → Build → Review → Test → Ship → Reflect) avant de passer au suivant. Pas de parallélisation au sein d'un finding.

### SPRINT S0 — P0 production blocker (1 jour) — **AVANT TOUS LES AUTRES**

| # | ID | Sub-plan | Mission en 1 ligne |
|---|---|---|---|
| 0 | **F-015** | [`PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md`](plans/PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md) | Production blocker queue config : doc REALTIME_SETUP.md obsolète + .env.example dangereux + health check worker + monitoring outbox staleness |

**Gate orchestrateur :** F-015 vert → autorise S1.

### SPRINT S1 — P0 critique fiscal + payment integrity (2 jours)

| # | ID | Sub-plan | Mission en 1 ligne |
|---|---|---|---|
| 1 | **F-001** | [`PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md`](plans/PLAN_AUDIT_F001_KIOSK_FISCAL_SEQUENCE_2026-05-07.md) | Allouer `fiscal_sequence_no` aux commandes kiosk au moment du PAID (NF525). Migration backfill **gated** owner. |
| 2 | **F-002** | [`PLAN_AUDIT_F002_TPE_AMOUNT_ECHO_2026-05-07.md`](plans/PLAN_AUDIT_F002_TPE_AMOUNT_ECHO_2026-05-07.md) | Vérifier `amount_cents` retourné par TPE sur `payment-confirm` (rejet 422 `AMOUNT_ECHO_MISMATCH` si écart >1 cent). |

**Gate orchestrateur :** F-001 + F-002 verts → autorise S2.

### SPRINT S2 — P0 cash reconciliation (5 jours)

| # | ID | Sub-plan | Mission |
|---|---|---|---|
| 3 | **F-003** | [`PLAN_AUDIT_F003_CASH_RECONCILIATION_2026-05-07.md`](plans/PLAN_AUDIT_F003_CASH_RECONCILIATION_2026-05-07.md) | **Décision actée : Option A**. Schema `cash_drawer_sessions` + `cash_movements` + alter z_reports. Service + Endpoints + Hooks + Z enrichment. **5 sub-tasks séparées, 1 commit par sub-task.** |

**Gate orchestrateur :** F-003 vert + Z report HMAC stable → autorise S3.

### SPRINT S3 — P1 cluster state machine + idempotency (3 jours)

> Les 4 plans suivants sont **indépendants** — peuvent être traités en parallèle si pair-of-claudes, sinon séquentiel.

| # | ID | Sub-plan | Mission |
|---|---|---|---|
| 4 | **F-004** | [`PLAN_AUDIT_F004_CANCEL_REASON_ENFORCE_2026-05-07.md`](plans/PLAN_AUDIT_F004_CANCEL_REASON_ENFORCE_2026-05-07.md) | Enum `OrderCancelReason` whitelist + enforce reason sur transitions terminales (CANCELED/REJECTED/RETURNED) + frontend kiosk envoie `reason='tpe_cancel_user'` etc. |
| 5 | **F-005** | [`PLAN_AUDIT_F005_QUEUE_NUMBER_FALLBACK_2026-05-07.md`](plans/PLAN_AUDIT_F005_QUEUE_NUMBER_FALLBACK_2026-05-07.md) | Préfixe `Z` monotonique pour fallback queue number (vs `microtime % 9999` actuel) + TTL lock principal 30s. |
| 6 | **F-006** | [`PLAN_AUDIT_F006_POS_IDEMPOTENCY_PARITY_2026-05-07.md`](plans/PLAN_AUDIT_F006_POS_IDEMPOTENCY_PARITY_2026-05-07.md) | Aligner POS sur Kiosk : Cache::lock + catch `QueryException 23000` + retour existing au lieu de 422. |
| 7 | **F-007** | [`PLAN_AUDIT_F007_KIOSK_LOCK_BRANCH_FALLBACK_2026-05-07.md`](plans/PLAN_AUDIT_F007_KIOSK_LOCK_BRANCH_FALLBACK_2026-05-07.md) | Hard-fail 401/403/422 si auth ou KioskMachine absent (pas de fallback `branch_id=0`). |

**Gate orchestrateur :** Tous P1 cluster S3 verts → autorise S4.

### SPRINT S4 — P1 payment reconcile + cash hook (3 jours)

| # | ID | Sub-plan | Mission |
|---|---|---|---|
| 8 | **F-008** | [`PLAN_AUDIT_F008_PAYMENT_CONFIRM_RECONCILE_2026-05-07.md`](plans/PLAN_AUDIT_F008_PAYMENT_CONFIRM_RECONCILE_2026-05-07.md) | localStorage frontend + boot retry + endpoint backend `reconcile-pending` + table `pending_payment_confirmations`. **Dépend de F-001 + F-002.** |
| 9 | **F-009** | [`PLAN_AUDIT_F009_KIOSK_CASH_BACKEND_HOOK_2026-05-07.md`](plans/PLAN_AUDIT_F009_KIOSK_CASH_BACKEND_HOOK_2026-05-07.md) | Endpoint `/cash-acknowledge` + frontend hook après openDrawer + Z report `cash_unacknowledged_count`. **Dépend de F-003.** |

**Gate orchestrateur :** F-008 + F-009 verts → autorise S5.

### SPRINT S5 — P3 QA toggle (1 jour)

| # | ID | Sub-plan | Mission |
|---|---|---|---|
| 10 | **F-014** | [`PLAN_AUDIT_F014_TPE_STUB_QA_TOGGLE_2026-05-07.md`](plans/PLAN_AUDIT_F014_TPE_STUB_QA_TOGGLE_2026-05-07.md) | Query param `?tpe_force=declined|timeout` pour exercer en dev les paths d'erreur TPE. **Dépend de F-002.** |

### BACKLOG — P2/P3 (rolling, post-S5)

| # | ID | Sub-plan | Mission |
|---|---|---|---|
| 11 | **F-010** | [`PLAN_AUDIT_F010_BRANCHSCOPE_QUEUE_CONTEXT_2026-05-07.md`](plans/PLAN_AUDIT_F010_BRANCHSCOPE_QUEUE_CONTEXT_2026-05-07.md) | Audit + filter explicite branch_id dans tous Jobs/Listeners (BranchScope inactif en queue worker). |
| 12 | **F-011** | [`PLAN_AUDIT_F011_PRICING_SSOT_DUPLICATION_2026-05-07.md`](plans/PLAN_AUDIT_F011_PRICING_SSOT_DUPLICATION_2026-05-07.md) | Test équivalence SSOT vs legacy fallback ; supprimer flag si jamais utilisé. |
| 13 | **F-012** | [`PLAN_AUDIT_F012_GOD_CLASSES_REFACTOR_2026-05-07.md`](plans/PLAN_AUDIT_F012_GOD_CLASSES_REFACTOR_2026-05-07.md) | Split OrderService (1888 LOC) en 3 services + façade. **POS wizard NON touché.** |
| 14 | **F-013** | [`PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md`](plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md) | Whitelist explicite `[OrderStatus::PENDING]` dans `finalizePaidKioskOrder` (vs `>= ACCEPT` fragile). |

---

## ⚖️ DISCIPLINE STRICTE — 15 RÈGLES NON-NÉGOCIABLES

### 1. **Ordre absolu**

Tu commences par **F-001**. Tu ne sautes JAMAIS à un finding suivant tant que le courant n'a pas reçu de gate `continue` de l'orchestrateur. **Pas d'exception.**

### 2. **Pipeline GSTACK obligatoire par finding**

```
THINK → PLAN → BUILD → REVIEW → TEST → SHIP → REFLECT
```

Tu ne sautes AUCUNE étape. Si tu skip TEST pour gagner du temps, tu reverts et tu recommences.

### 3. **Stop checklist 6 questions AVANT tout code**

Pour CHAQUE finding, avant d'écrire la moindre ligne :

1. **Why** ce code (lien finding) ?
2. **What** changement minimal pour passer le test rouge ?
3. **Where** (file:line, scope) ?
4. **Who** est impacté (autres call sites, jobs, frontends) ?
5. **How** valider (test name, command) ?
6. **When** rollback (critère explicite) ?

Si tu ne peux pas répondre aux 6 → escalade.

### 4. **Test rouge AVANT le fix**

Tu écris le test qui ÉCHOUE sur le bug actuel d'abord. Tu confirmes le rouge en runner. Puis tu codes le fix. Puis tu valides le vert.

**Si le test passe au vert avant ton fix** → drift détecté → STOP, escalade orchestrateur.

### 5. **Anti-drift checklist AVANT tout `git commit`**

Coche **OUI** à toutes :

- [ ] Test rouge écrit AVANT le fix ?
- [ ] Test confirme le bug actuel (échoue) ?
- [ ] Fix passe le test au vert ?
- [ ] Suite POS complète verte ?
- [ ] Suite Fiscal complète verte ?
- [ ] Suite Kiosk complète verte ?
- [ ] Aucune zone frozen modifiée ?
- [ ] Diff < 200 lignes (sinon split commit) ?
- [ ] Commit message : `audit(F-0XX): <résumé>` ?
- [ ] Pas de `--no-verify`, pas d'amend ?
- [ ] Hooks pre-commit verts ?
- [ ] Branch isolée `audit/F-0XX-<slug>` ?

Une seule case non cochée → tu corriges avant de commiter.

### 6. **Frozen zones absolues — INTERDITES de modification**

| Zone | Status |
|---|---|
| `public/js/pos-wizard.js` (5769 LOC) | 🔒 Aucune modif. Lecture audit OK. |
| `public/css/pos-wizard.css` (1987 LOC) | 🔒 Aucune modif. |
| **Kiosk wizard Vue** : `KioskWizardComponent.vue`, `KioskPosWizardComponent.vue`, `KioskCartComponent.vue`, `KioskCategoriesComponent.vue`, `KioskUpsellComponent.vue`, `KioskPromoCarouselComponent.vue`, `KioskOrderSummaryComponent.vue`, `KioskProductListComponent.vue` | 🔒 Aucune modif code. **Tests Vitest/Playwright autorisés.** |
| `app/Domain/Order/OrderStateMachine.php` (le domain) | 🔒 Aucune modif. Modif call sites OK. |
| `app/Services/Fiscal/FiscalSequenceService.php` | 🔒 Aucune modif. Réutilisation OK. |
| Gateways de paiement externes (Stripe, Paypal, Credit, Razorpay, Paystack) | 🔒 |
| `app/Services/PushNotificationService` | 🔒 |
| `Admin/DashboardController` analytics avancés | 🔒 |
| Delivery Boy logic | 🔒 |

Si un sub-plan te demande de toucher une zone frozen → **STOP**. Erreur du sub-plan, remonter à l'orchestrateur.

### 7. **Pas de scope drift**

Si tu découvres un bug adjacent pendant ton travail :
- Tu **NE le corriges PAS**.
- Tu le **notes** dans la section "Discovered" du sub-plan courant.
- Tu remontes à l'orchestrateur en fin de finding.

### 8. **Migration data-sensible = GATED OWNER**

Toute migration touchant `orders`, `frontend_orders`, `audit_logs`, `z_reports`, `order_status_transitions` :
- Tu crées le fichier de migration.
- Tu ne l'**exécutes PAS**.
- Tu marques dans le rapport "Migration prête, en attente gate owner".

### 9. **Idempotency partout**

Toute mutation HTTP avec `X-Idempotency-Key` doit :
1. Acquérir un `Cache::lock` namé par `(endpoint, branch_id, key)`.
2. Vérifier l'existing AVANT mutation.
3. Catcher `QueryException 23000` et retourner l'existing.
4. Truncate la key à 64 char.

### 10. **Pricing SSOT respecté**

Aucun bypass de `App\Services\Pricing\PricingService`. Tout calcul de prix passe par lui. Le legacy fallback existe — tu ne le modifies pas.

### 11. **Branch isolation**

Toute query sur `Order` ou `FrontendOrder` doit respecter `BranchScope`. Si tu utilises `withoutGlobalScope`, tu commentes pourquoi (style `[POS-9-H.2.5]`).

### 12. **Reporting structuré obligatoire**

À la fin de CHAQUE finding, tu écris dans `reports/execution/audit_2026-05-07/REPORT_F0XX_<short>.md` :

```markdown
# REPORT F-0XX — <title>
**Date :** YYYY-MM-DD
**Branch :** audit/F-0XX-<slug>
**Commit(s) :** <hash1>, <hash2>
**Decision :** continue | heal | block | escalate

## Pré-test (red) — confirmation bug
## Modifications — diff résumé
## Post-test (green) — résultats
## Vérifications anti-régression — suites
## Acceptance criteria validés — checklist
## Edge cases testés
## Discovered (out of scope, NOT fixed)
## Graphiti push — UUID
```

### 13. **Graphiti push à chaque finding clos**

```python
mcp__graphiti__add_memory(
    name="F-0XX closed: <title>",
    group_id="foodking",
    source="json",
    episode_body=<JSON template du sub-plan §12>
)
```

### 14. **Format commit + branch**

- Commit : `audit(F-0XX): <one-line summary>`
- Branch : `audit/F-0XX-<slug>` (un par finding)
- Label PR : `audit-2026-05-07`

**Jamais** : `--no-verify`, `--amend`, `git push --force` sur main.

### 15. **Gate orchestrateur après chaque finding**

Tu ouvres une PR. Orchestrateur (moi) review. Décision :

| Decision | Critère | Action |
|---|---|---|
| `continue` | Test rouge→vert + 0 régression + diff propre | Merger, passer au F-suivant |
| `heal` | Test partiellement vert OU régression non-critique | Bloquer ce finding, sub-plan correctif (max 3 cycles) |
| `block` | Régression sur Z report / fiscal / pricing / state machine | Stop session, escalade owner |
| `escalate` | Décision business floue OU ambiguïté | Demander input owner |

---

## 🛑 STOP — Quand t'arrêter immédiatement

- Régression sur `tests/Feature/Fiscal/` après un fix.
- Régression sur `tests/Unit/Domain/Order/OrderStateMachineTest.php`.
- Régression sur HMAC signature de Z report.
- Pricing SSOT bypassé par accident.
- Modification accidentelle d'une zone frozen.
- Commit fait sans test rouge préalable.
- Conflit entre deux sub-plans (drift code).
- Doute sur la décision business (ex. ambiguïté F-003 ne se présente plus, mais autres findings peuvent surgir).

Dans tous ces cas : **revert immédiat**, écrire le rapport, escalader orchestrateur.

---

## 📊 INVARIANTS POST-AUDIT (à enforcer définitivement)

À la fin du sprint, ces invariants doivent tenir :

1. **NF525-Kiosk** : `payment_status != UNPAID ⟹ fiscal_sequence_no IS NOT NULL` (FrontendOrder).
2. **NF525-Z** : `Z.aggregate.orderCount == count(orders WHERE branch_id=X AND created_at IN (Z.from, Z.to] AND payment_status != UNPAID)`.
3. **TPE-amount** : `payment-confirm` rejette `abs(amount_cents - order.total*100) > 1`.
4. **Cancel-reason** : aucune transition CANCELED/REJECTED/RETURNED sans `reason` whitelisté.
5. **Idempotency-parity** : POS et Kiosk ont le même comportement face à un duplicate `idempotency_key` (return existing 200, jamais 422).
6. **Queue-monotonic** : `queue_number` strictement croissant pour `(branch_id, date)` ; fallback `Z*` ne casse pas l'invariant.
7. **BranchScope-job** : tout job de queue worker traite explicitement `Auth` ou utilise `WithBranchContext`.
8. **Cash-reconcile** : tout order cash PAID a un `cash_movement` lié ; Z report calcule variance.
9. **Cash-acknowledge** : kiosk cash → `cash_acknowledged_at` posé après openDrawer.
10. **Reconcile-queue** : `pending_payment_confirmations` UNIQUE par transaction_id ; cleanup automatique post-resolved.

---

## 🎯 RAPPORT FINAL ATTENDU

À la fin du sprint complet (ou si stop forcé), tu produits :

`reports/execution/audit_2026-05-07/FINAL_REPORT.md`

```markdown
# Audit Ultra Review 2026-05-07 — Final Execution Report
**Exécuteur :** Claude Opus
**Période :** YYYY-MM-DD → YYYY-MM-DD
**Findings traités :** X/14

## Statut par finding
| ID | Sev | Décision orchestrateur | Commit | Date |
| F-001 | P0 | continue | <hash> | YYYY-MM-DD |
...

## Métriques
- Tests ajoutés : X
- LOC delta : +Y / -Z
- Couverture POS : X% → Y%
- Couverture Fiscal : X% → Y%

## Régressions évitées
- Liste

## Discovered (out-of-scope)
- Liste

## Recommandations post-audit
- ...

## Mémoires Graphiti pushées
- Episode UUIDs
```

---

## 🤝 CONTRAT DE CLÔTURE

À la fin :
- Tu ne dis pas "j'ai fini" tant que **les 10 invariants §invariants** ne sont pas vérifiables par grep + tests.
- Tu ne déclares pas `done` sur un finding sans gate `continue` de l'orchestrateur.
- Tu ne pousses sur `main` sans review.
- Tu update le master plan v2 (checkboxes par finding) au fur et à mesure.
- Tu push Graphiti à chaque finding.

**Vélocité ≠ qualité. La production restaurant française est NF525-réglementée. Un bug fiscal = sanction administrative. Tu prends le temps de bien faire.**

---

## 📞 ESCALATION

Si tu rencontres :
- Drift entre sub-plan et code actuel → escalade.
- Décision business floue → escalade.
- Migration sensible NF525 prête → gate owner.
- Régression critique → stop session, escalade owner.

Format escalation : commentaire dans la PR + ping orchestrateur (si en mode multi-agent), sinon stop session avec rapport explicite.

---

## ✅ PRÉ-DÉMARRAGE

Avant de toucher la moindre ligne, confirme :

- [ ] J'ai lu CE FICHIER intégralement.
- [ ] J'ai lu le master plan v2.
- [ ] J'ai lu le sub-plan F-001.
- [ ] J'ai vérifié le drift (line numbers F-001 matchent le code actuel).
- [ ] J'ai compris les 15 règles de discipline.
- [ ] J'ai compris la structure du rapport.
- [ ] J'ai créé la branche `audit/F-001-kiosk-fiscal-sequence`.
- [ ] J'ai TodoWrite avec mes sub-tasks F-001.

Si tout est ✅ → tu peux écrire le test rouge F-001.

---

— *L'audit n'est pas une opinion, c'est une preuve. Le plan n'est pas une suggestion, c'est un contrat. La discipline n'est pas une décoration, c'est la condition sine qua non.*
