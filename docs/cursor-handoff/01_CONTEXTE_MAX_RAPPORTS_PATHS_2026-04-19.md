# Passation Cursor — Fichier 1/3 : contexte maximum, rapports, chemins

> **Objectif** : injecter le maximum de contexte factuel dans une **nouvelle** session Cursor
> (même dossier projet ou worktree aligné). Lire **ce fichier en premier** pour la cartographie
> complète ; le **fichier 3** sert à **démarrer** l’action suivante.

---

## 1. Deux arborescences à connaître

| Rôle | Chemin absolu | Branche typique |
|------|-----------------|-----------------|
| **Worktree K-series complet** (K-1→K-10, audits 110, rapports RUN/VERIFY) | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93` | `feat/kiosk-phase-9-3` |
| **Clone principal** (ex. correctifs Phase1 / allergènes) | `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` | `feat/ton-sujet` (variable) |

Beaucoup de livrables K / audit **n’existent que** sous `testttt-kiosk-p93`. Si tu n’ouvres
que `testttt`, utilise ce fichier comme **index** et ouvre les chemins absolus ou fusionne
les branches / worktrees.

---

## 2. Index des « alimentations » récentes (rapports, ADR, audits)

### 2.1 Cycle K (tracker + plans + RUN/VERIFY)

| Artefact | Path (kiosk-p93) |
|----------|------------------|
| Tracker K-1→K-10 | `tasks/k-hardening/K_TRACKER.md` |
| Plans K1–K10 | `tasks/k-hardening/PLAN_K*_*.md` |
| ADR K-8, K-9, K-10 scope | `tasks/k-hardening/ADR_K8_MULTIBRANCH_STRATEGY_2026-04-18.md`, `ADR_K9_OBSERVABILITY_STRATEGY_2026-04-18.md`, `ADR_K10_ACCEPTANCE_SCOPE_2026-04-19.md` |
| RUN / VERIFY | `reports/execution/RUN_K*_*.md`, `VERIFY_K*_*.md`, `RUN_K10_ACCEPTANCE_2026-04-19.md`, `VERIFY_K10_ACCEPTANCE_2026-04-19.md` |
| Handoff K-10 | `reports/execution/HANDOFF_K10_ACCEPTANCE_FINAL_2026-04-18.md` |
| Acceptance K-10 | `reports/acceptance/ACCEPTANCE_KIOSK_FINAL_2026-04-19.md` |
| Evidence pack K-10 | `reports/acceptance/EVIDENCE_PACK_2026-04-19/README.md` |
| Onboarding opérateur | `docs/kiosk/OPERATOR_ONBOARDING_K10_2026-04-19.md` |
| Rapport logique global K | `reports/review/REPORT_K1_K10_GLOBAL_LOGIC_2026-04-19.md` |

### 2.2 Audit kiosk 110 % (post-K)

| Artefact | Path (kiosk-p93) |
|----------|------------------|
| Executive | `reports/review/AUDIT_KIOSK_110_EXECUTIVE_2026-04-19.md` |
| Tracker findings (60) | `reports/review/AUDIT_KIOSK_110_FINDINGS_TRACKER.md` |
| Axes 1–16 (fichiers dédiés) | `reports/review/AUDIT_KIOSK_110_ARCHITECTURE_2026-04-19.md`, `…_SYNC_PRICING_…`, `…_ISOLATION_STATE_…`, `…_UX_A11Y_…`, `…_HARDWARE_OFFLINE_…`, `…_SECURITY_…`, `…_DATA_…`, `…_OBSERVABILITY_PERF_…`, `…_TESTS_REGRESSIONS_…`, `…_DEPLOY_…`, `…_HIDDEN_RISKS_…` |

### 2.3 Audit POS 110 % + rapport global P (**clone `testttt`**)

> Ces fichiers étaient présents sous `testttt` au 2026-04-19 ; absents du worktree
> `testttt-kiosk-p93` tant qu’ils ne sont pas mergés ou copiés.

| Artefact | Path (`testttt`) |
|----------|------------------|
| Executive POS 110 | `reports/review/AUDIT_POS_110_EXECUTIVE_2026-04-19.md` |
| Tracker findings POS | `reports/review/AUDIT_POS_110_FINDINGS_TRACKER.md` |
| Rapport global P (implémentations) | `reports/review/REPORT_GLOBAL_P_IMPLÉMENTATIONS_2026-04-19.md` |
| Axes POS (multi-fichiers) | préfixe `reports/review/AUDIT_POS_110_*_2026-04-19.md` |

### 2.4 Observabilité K-9 (doc SLO, config)

| Artefact | Path (kiosk-p93) |
|----------|------------------|
| SLO documentés | `docs/observability/SLO_KIOSK_2026-04-18.md` |
| Code clé | `app/Jobs/Observability/SloEvaluatorJob.php`, `app/Services/Observability/SloMetricCollector.php`, `resources/js/observability/*.js` |

### 2.5 Transcript Cursor (historique brut multi-tours)

Les transcripts parent Cursor sont hors du repo ; UUID de session référencée dans les
résumés précédents : `fd9d41fd-6325-46ad-8506-a0093e316d7d` (dossier agent-transcripts Cursor
— ne pas committer ; chemin machine typique sous
`~/.cursor/projects/.../agent-transcripts/`).

---

## 3. Invariants projet (rappel synthétique)

- **SSOT** : prix, taxes, totaux commande, disponibilité autorisée — **backend** (`FrontendOrderService`, garde 409, etc.).
- **`branch_id`** : autorité **KioskMachine** / context sur flux kiosk authentifiés.
- **OrderStateMachine** : transitions contrôlées (dette `apply()` vs assignations — voir audit AX6).
- **EventContract + `DB::afterCommit`** : outbox / broadcast (`DispatchDomainEventsJob`).
- **PII / RGPD** : whitelists `kiosk-event`, scrub Sentry ; gaps `context`/`details` (AX12-01).

---

## 4. État des suites (référence au moment K-10 closeout — kiosk-p93)

- PHPUnit `tests/Feature` : **510** tests, 0 failed (8 skipped).
- Vitest : **718** passed, 1 skipped.

Rejouer après merge : `./vendor/bin/phpunit tests/Feature` et `npx vitest run` depuis la
racine du worktree utilisé.

---

## 5. Fichiers « mémoire » de cette passation (lire dans l’ordre)

1. **`01_CONTEXTE_MAX_RAPPORTS_PATHS_2026-04-19.md`** (ce fichier) — cartographie + paths.  
2. **`02_HISTORIQUE_CONVERSATION_VISION_2026-04-19.md`** — ce qu’on a fait dans le chat + vision.  
3. **`03_DEMARRAGE_PROCHAINES_ETAPES_2026-04-19.md`** — par quoi commencer la **nouvelle**
   conversation + backlog.

---

## 6. Note sur le clone `testttt` (dernière alimentation utilisateur)

Alignement des **codes allergènes** du test `AllergensSeederTest` sur le **seeder UE**
(`gluten`, `crustaces`, `lait`, …) : fichier modifié côté `testttt` :

`tests/Feature/KioskPhase1/AllergensSeederTest.php`

À porter sur `testttt-kiosk-p93` si les deux arbres doivent rester identiques.

---

*Fin fichier 1/3.*
