# PLAN_EXECUTOR_MASTER_v2 — Ultra Review POS+Kiosk
**Version :** 2.0 — Executor edition (delegated to Claude Opus sub-agent)
**Date :** 2026-05-07
**Audit chef d'orchestration :** Claude (this conversation)
**Exécuteur :** Claude Opus (next session, full autonomy under this plan)

---

## 0. CONTRAT D'EXÉCUTION — LIRE EN PREMIER

### 0.1 Identité de l'exécuteur

Tu es Claude Opus en mode exécuteur. Tu reçois ce master plan + 14 sub-plans `PLAN_AUDIT_F0XX_*.md`. Ton rôle :
- **N'invente RIEN.** Toutes les routes, méthodes, lignes de code citées ont été VÉRIFIÉES par l'orchestrateur. Le précédent agent Explore a halluciné des routes (`confirmCounterPayment`, `walkInCustomer`, `refundWithCounterEntry`, `collect-kiosk-cash`) — ces routes **N'EXISTENT PAS**. Si un sub-plan te demande de les chercher, c'est une erreur du sub-plan : remonter avant d'agir.
- **Suis le pipeline GSTACK à la lettre** sur CHAQUE finding : Think → Plan → Build → Review → Test → Ship → Reflect.
- **Stop checklist 6 questions avant tout code** (cf. `feedback_gstack_pipeline_methodology`).
- **Tests AVANT correction** : chaque finding a un test rouge à écrire d'abord. Le test échoue sur le bug. La correction le passe au vert.
- **Pas de scope drift** : si tu découvres un bug adjacent, tu l'enregistres dans une section "Discovered" du sub-plan, tu ne le corriges pas.
- **Frozen zones absolues** (cf §0.3 ci-dessous).
- **Reporting structuré** (cf §0.4 ci-dessous).

### 0.2 Décisions prises par l'orchestrateur (NON négociables)

| Décision | Valeur | Justification |
|---|---|---|
| **F-003 cash design** | **Option A — Cashier-supervised reconciliation** | (1) Compatible avec le stub actuel (pas de matériel requis). (2) Modèle restaurant FR aligné avec NF525 (Z report = clôture de service avec variance). (3) Rétro-compatible avec le code existant (`payment_status=PAID` reste valide pour le path nominal — on ajoute une couche de réconciliation). (4) Permet le branchement TPE/imprimante/tiroir réel SANS refactor du flow nominal. (5) Pas d'état intermédiaire `PENDING_CASH` qui casserait la state machine V1 (frozen). |
| **F-009 kiosk cash backend hook** | Couplé à F-003=A → endpoint `cash-acknowledge` post-drawer | Le kiosk pousse un signal backend après `kioskHardware.openDrawer()` réussi. La discrepancy "PAID sans cash-acknowledge" devient visible dans le Z. |
| **Approche simulation→production** | **Code production-ready dès maintenant, stubs derrière une couche `kioskHardware`** | Le code finalisé tournera identique en simulation et en réel. Le matériel se branche par implémentation Electron du contract `kioskHardware`, sans modifier l'application Vue/Laravel. |

### 0.3 Frozen zones (interdites de modification de logique)

| Zone | Status | Note exécution |
|---|---|---|
| `public/js/pos-wizard.js` (5769 LOC) | **🔒 Frozen — AUCUNE modification** | Visuel/design parfait (mémoire `feedback_wizard_popup_pos_protected`). Lecture OK pour audit. |
| `public/css/pos-wizard.css` (1987 LOC) | **🔒 Frozen** | Idem |
| **Kiosk wizard Vue** : `KioskWizardComponent.vue`, `KioskPosWizardComponent.vue`, `KioskCartComponent.vue`, `KioskCategoriesComponent.vue`, `KioskUpsellComponent.vue`, `KioskPromoCarouselComponent.vue`, `KioskOrderSummaryComponent.vue` | **🔒 Frozen — AUCUNE modif code, TESTS AUTORISÉS** | Confirmé par owner 2026-05-07 : "elle est presque parfaite, ne modifie pas c'est vrai mais en terme de test tu peux passer des tests dessus". Tu peux écrire/runner Playwright + Vitest sur ces composants ; tu ne touches pas leur code source. Les fixes touchent backend/services/non-frozen Vue uniquement. |
| Gateways de paiement externes (`Stripe`, `Paypal`, `Credit`, `Razorpay`, `Paystack`) | **🔒 Frozen** | Standby phase SaaS V2 |
| `app/Services/PushNotificationService` | **🔒 Frozen** | Lié à Guzzle hérité |
| `Admin/DashboardController` analytics avancés | **🔒 Frozen** | Pas dans scope core |
| Delivery Boy logic | **🔒 Frozen** | V2 |
| `App\Domain\Order\OrderStateMachine` (le domaine, pas les call sites) | **🔒 Frozen** | Pattern V1 strict |

### 0.4 Format de rapport obligatoire

À la fin de chaque finding traité, tu produis dans `reports/execution/audit_2026-05-07/REPORT_F0XX_<short-name>.md` :

```markdown
# REPORT F-0XX — <title>
**Date :** YYYY-MM-DD
**Branch :** audit/F-0XX-<slug>
**Commit(s) :** <hash1>, <hash2>
**Decision :** continue | heal | block | escalate

## Pré-test (red)
- Path : tests/...
- Command : ./vendor/bin/phpunit ...
- Résultat avant fix : X failed (expected red)

## Modifications
- File : path:line — diff resumé
- File : path:line — diff resumé

## Post-test (green)
- Command : ./vendor/bin/phpunit ...
- Résultat après fix : ALL GREEN
- Couverture suite complète : X tests, 0 fail, 0 risky

## Vérifications anti-régression
- ./vendor/bin/phpunit tests/Feature/Pos/ : ✅
- ./vendor/bin/phpunit tests/Feature/Fiscal/ : ✅
- ./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php : ✅
- npm run test : ✅
- npm run lint : ✅

## Acceptance criteria validés
- [x] Critère 1
- [x] Critère 2
...

## Edge cases testés
- Cas X : OK
- Cas Y : OK

## Discovered (out of scope, NOT fixed)
- Bug Z noté, à traiter dans plan séparé.

## Graphiti push
- Episode UUID : <uuid>
- Group : foodking
```

### 0.5 Anti-drift checklist (avant CHAQUE commit)

Coche oui à TOUTES avant `git commit` :

- [ ] Test rouge écrit AVANT le fix ?
- [ ] Test rouge confirme le bug actuel (échoue) ?
- [ ] Fix passe le test au vert ?
- [ ] Suite POS complète verte ?
- [ ] Suite Fiscal complète verte ?
- [ ] Suite Kiosk complète verte ?
- [ ] Aucune zone frozen modifiée ?
- [ ] Diff inférieur à 200 lignes (sinon split commit) ?
- [ ] Commit message : `audit(F-0XX): <résumé>` ?
- [ ] Pas de `--no-verify`, pas d'amend ?
- [ ] Hooks pre-commit verts ?
- [ ] Branch isolée `audit/F-0XX-<slug>` ?

### 0.6 Stop checklist 6 questions avant tout code

(Réf. `feedback_gstack_pipeline_methodology`)

1. **Why** ce code (lien finding) ?
2. **What** changement minimal pour passer le test rouge ?
3. **Where** (file:line, scope) ?
4. **Who** est impacté (autres call sites, agents, intégrations) ?
5. **How** valider (test name, command) ?
6. **When** rollback (critère explicite) ?

---

## 1. PLAN GLOBAL D'ORCHESTRATION GSTACK

### 1.1 Pipeline détaillé par finding

```
┌─────────┐
│  THINK  │  Lire le sub-plan F-0XX entièrement
│         │  Vérifier que les line numbers cités matchent le code actuel (drift detection)
│         │  Stop checklist 6 questions
└────┬────┘
     │
┌────▼────┐
│  PLAN   │  Lister les sous-tâches concrètes (TodoWrite ≥ 3 items)
│         │  Vérifier dépendances avec autres findings (cf §3.2 ci-dessous)
│         │  Identifier rollback path
└────┬────┘
     │
┌────▼────┐
│  BUILD  │  Étape 1 : écrire le test rouge
│         │  Étape 2 : runner le test → confirme red
│         │  Étape 3 : implémenter le fix minimal
│         │  Étape 4 : runner le test → confirme green
│         │  Étape 5 : runner les suites adjacentes (no regression)
└────┬────┘
     │
┌────▼────┐
│ REVIEW  │  Self-review avec checklist anti-drift §0.5
│         │  Diff < 200 lignes ? sinon split
│         │  Lint + style passes ?
└────┬────┘
     │
┌────▼────┐
│  TEST   │  Suite POS complète : ./vendor/bin/phpunit tests/Feature/Pos/
│         │  Suite Fiscal : ./vendor/bin/phpunit tests/Feature/Fiscal/
│         │  Suite Kiosk : ./vendor/bin/phpunit tests/Feature/KioskFrontendComprehensiveTest.php
│         │  Vue tests : npm run test
└────┬────┘
     │
┌────▼────┐
│  SHIP   │  Commit isolé : audit(F-0XX): <résumé>
│         │  Push branch audit/F-0XX-<slug>
│         │  Open PR avec template (cf §1.3)
│         │  Label : audit-2026-05-07
└────┬────┘
     │
┌────▼────┐
│ REFLECT │  Écrire reports/execution/audit_2026-05-07/REPORT_F0XX_*.md
│         │  Push Graphiti episode (foodking group)
│         │  Update master plan : checkbox "F-0XX done"
└─────────┘
```

### 1.2 Critères de gate Claude orchestrateur

L'orchestrateur (moi) review le PR et décide :

| Decision | Critère | Action exécuteur |
|---|---|---|
| `continue` | Test rouge→vert + 0 régression + diff propre | Merger, passer au F-suivant |
| `heal` | Test partiellement vert OU régression non-critique sur test périphérique | Bloquer ce finding, créer sub-plan correctif (max 3 cycles) |
| `block` | Régression sur Z report / fiscal chain / pricing SSOT / state machine | Stop session, escalade owner |
| `escalate` | Décision business floue OU ambiguïté de spec | Demander input owner avant continuer |

### 1.3 Template PR

```
Title: audit(F-0XX): <one-line summary>
Branch: audit/F-0XX-<slug>
Base: main

## Summary
- Finding F-0XX (severity Pn) — <one sentence>
- Files modified : <count>
- LOC delta : +X / -Y

## Acceptance criteria
- [x] Critère 1
- [x] Critère 2

## Test plan
- [x] tests/<path> : red → green
- [x] Suite POS : 0 fail
- [x] Suite Fiscal : 0 fail
- [x] Suite Kiosk : 0 fail
- [x] Vue tests : 0 fail

## Risk register
- Risque 1 : <description> → mitigation : <action>

## Anti-drift
- [x] No frozen zone touched
- [x] Pricing SSOT not bypassed
- [x] State machine guards intact
- [x] BranchScope intact

🤖 Audit Ultra Review 2026-05-07
```

---

## 2. INVENTAIRE DES 14 FINDINGS

### 2.1 Tableau d'orchestration

| ID | Sev | Surface | Title | Sub-plan | Sprint | Estimated | Blocks | Blocked-by |
|---|---|---|---|---|---|---|---|---|
| F-001 | P0 | Kiosk | Kiosk fiscal_sequence_no never allocated | `PLAN_AUDIT_F001_*.md` | S1 | 1 j | `Z report kiosk completeness` | — |
| F-002 | P0 | Kiosk | TPE amount echo not verified | `PLAN_AUDIT_F002_*.md` | S1 | 1 j | `Real TPE rollout` | — |
| F-003 | P0 | POS+Kiosk | Cash reconciliation (Option A) | `PLAN_AUDIT_F003_*.md` | S2 | 5 j | F-009 | — (decision taken) |
| F-004 | P1 | Kiosk+POS | Cancel without reason | `PLAN_AUDIT_F004_*.md` | S3 | 1 j | — | — |
| F-005 | P1 | Both | Queue number fallback collision | `PLAN_AUDIT_F005_*.md` | S3 | 0.5 j | — | — |
| F-006 | P1 | POS | POS idempotency parity | `PLAN_AUDIT_F006_*.md` | S3 | 1 j | — | — |
| F-007 | P1 | Kiosk | Lock branch fallback to 0 | `PLAN_AUDIT_F007_*.md` | S3 | 0.5 j | — | — |
| F-008 | P1 | Kiosk | Payment-confirm reconciliation queue | `PLAN_AUDIT_F008_*.md` | S4 | 2 j | — | F-001, F-002 |
| F-009 | P1 | Kiosk | Cash backend hook | `PLAN_AUDIT_F009_*.md` | S4 | 1 j | — | F-003 |
| F-010 | P2 | Transverse | BranchScope queue context | `PLAN_AUDIT_F010_*.md` | Backlog | 1 j | — | — |
| F-011 | P2 | Both | SSOT fallback duplication | `PLAN_AUDIT_F011_*.md` | Backlog | 2 j | — | — |
| F-012 | P2 | POS | God classes refactor | `PLAN_AUDIT_F012_*.md` | Backlog | 5 j | — | — |
| F-013 | P3 | Kiosk | finalizePaidKioskOrder state guard | `PLAN_AUDIT_F013_*.md` | Backlog | 0.5 j | — | F-001 |
| F-014 | P3 | Kiosk | TPE stub QA toggle | `PLAN_AUDIT_F014_*.md` | S5 | 0.5 j | — | F-002 |

### 2.2 Dependency graph

```
F-001 (kiosk fiscal seq)  ─────┐
F-002 (TPE amount echo)   ─────┼─→ F-008 (reconcile queue)
                                │
                                └─→ F-014 (stub QA toggle)

F-003 (cash design A)     ─────┬─→ F-009 (cash backend hook)
                                └─→ F-013 (state guard) [optional]

F-004, F-005, F-006, F-007 : independent — peuvent être traités en parallèle au sein du sprint S3

F-010, F-011, F-012 : backlog post-P0/P1

F-001 sealing migration (backfill) : GATED OWNER — ne pas merger sans gate
```

### 2.3 Sprint timeline

```
Day 1-2   : S1 = F-001 + F-002 [P0, kiosk fiscal + payment integrity]
Day 3-7   : S2 = F-003 [P0, cash reconciliation Option A]
Day 8-10  : S3 = F-004, F-005, F-006, F-007 [P1 cluster, parallèle si pair-of-claudes]
Day 11-13 : S4 = F-008, F-009 [P1, cash hook + reconcile queue]
Day 14    : S5 = F-014 [P3 QA toggle] + cleanup
Backlog   : F-010, F-011, F-012, F-013 (rolling)
```

---

## 3. RÈGLES NON-NÉGOCIABLES

### 3.1 Frozen zones (cf §0.3)

Si un sub-plan te demande de modifier un fichier frozen → **STOP**. Ce serait une erreur du plan. Remonter à l'orchestrateur.

### 3.2 Tests sont obligatoires

Aucun commit sans test. Le test doit :
1. Échouer AVANT le fix (prouver le bug actuel).
2. Passer APRÈS le fix.
3. Couvrir au moins 1 happy path + 1 edge case par finding.

Les tests doivent être DÉTERMINISTES — pas de race conditions, pas de timing flaky.

### 3.3 Migration & data sensible

Toute migration qui touche :
- `orders` table
- `frontend_orders` (alias same table)
- `audit_logs`
- `z_reports`
- `order_status_transitions`

→ **GATED OWNER**. Ne PAS merger sans validation explicite. Ces tables sont fiscal-sensitives NF525.

### 3.4 Pricing SSOT

Aucun bypass de `App\Services\Pricing\PricingService`. Tout calcul de prix passe par lui (ou par le legacy fallback si flag = false, mais sans modifier le legacy).

### 3.5 Branch isolation

Toute query sur `Order` ou `FrontendOrder` doit respecter `BranchScope` OU explicitement `withoutGlobalScope` AVEC un commentaire justificatif documenté (genre POS-9-H.2.5/F-B5).

### 3.6 Idempotency

Toute mutation HTTP avec un `X-Idempotency-Key` doit :
1. Acquérir un `Cache::lock` namé par `(endpoint, branch_id, key)`.
2. Vérifier l'existing AVANT mutation.
3. Catcher `QueryException 23000` et retourner l'existing.
4. Truncate la key à 64 char (UNIQUE constraint).

### 3.7 Observability

Chaque finding ajoute au moins 1 metric ou log structuré. Cf §6 du master plan v1 pour la liste cible.

### 3.8 GSTACK — pas négociable

Tu ne sautes PAS d'étape du pipeline. Si tu skip Test pour gagner du temps, tu reverts et recommence.

---

## 4. RAPPORT FINAL ATTENDU (post-audit)

À la fin du sprint complet, tu produits `reports/execution/audit_2026-05-07/FINAL_REPORT.md` :

```markdown
# Audit Ultra Review 2026-05-07 — Final Execution Report
**Exécuteur :** Claude Opus
**Période :** YYYY-MM-DD → YYYY-MM-DD
**Findings traités :** 14/14

## Statut par finding
| ID | Sev | Décision orchestrateur | Commit | Date |
|---|---|---|---|---|
| F-001 | P0 | continue | <hash> | YYYY-MM-DD |
...

## Métriques
- Tests ajoutés : X
- LOC delta : +Y / -Z
- Couverture POS : X% (avant) → Y% (après)
- Couverture Fiscal : X% → Y%

## Régressions évitées
- Liste des près-de-régression détectées et bloquées.

## Discovered (out-of-scope, à planifier)
- Liste.

## Recommandations post-audit
- ...
```

---

## 5. INSTRUCTIONS POUR L'EXÉCUTEUR — VERBATIM

> Tu reçois ce master plan + 14 sub-plans. Tu commences par lire CE FICHIER intégralement. Puis tu lis le sub-plan F-001 et tu démarres le pipeline GSTACK.
>
> Tu ne pivotes PAS sur des findings non-listés (sauf via la section Discovered).
> Tu ne casses PAS les zones frozen.
> Tu suis le format de rapport et de commit à la lettre.
> Tu pousses Graphiti à chaque finding clos.
>
> Si tu rencontres une ambiguïté qui ne peut pas être résolue par lecture du code : `escalate` immédiat avec question précise.
>
> En cas de drift détecté entre un sub-plan et le code actuel (ex. line numbers ne matchent plus) : remonter immédiatement avant d'agir.
>
> Vélocité ≠ qualité. La production restaurant française est NF525-réglementée. Un bug fiscal = sanction administrative. Tu prends le temps de bien faire.

---

## 6. SIGNATURE

Orchestrateur : Claude (current session)
Décisions actées : F-003=A, kiosk wizard frozen-with-tests
Évidence : 100% vérifiée par lecture directe (purge des hallucinations Explore agent)
Graphiti : `Ultra Review POS+Kiosk Audit 2026-05-07` poussé au group `foodking`
Mémoire : `project_audit_ultra_review_2026-05-07.md` créée

— *L'audit n'est pas une opinion, c'est une preuve. Le plan n'est pas une suggestion, c'est un contrat.*
