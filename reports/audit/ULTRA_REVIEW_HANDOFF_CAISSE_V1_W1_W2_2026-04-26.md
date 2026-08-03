# Handoff ultra-review — Caisse V1 : Vague 1 (masterplay) + Vague 2 (Wave 2 / CV1-LOT, Option B)

**Date** : 2026-04-26  
**Pour** : Claude (terminal) — nouvelle session sans historique de chat.  
**Exécution principale** : `codex-extension` (CLI Codex, GPT) — **aucun** appel Claude pendant les lots Wave 2 décrits ici.

---

## 1) Position produit (ne pas renégocier sans humain)

- **Payment ledger** : **Option B (pilote restreint)**.  
- `**CV1-M04A-PAYMENT-LEDGER-FULL`** : **exclu** / ne pas lancer (full ledger Option A = gate humain + rescope).  
- `**CV1-M04B`** : piste cohérente avec B (déjà traitée côté masterplay si `CLOSED`).

---

## 2) Vague 1 — Masterplay (`CV1-M**`, file officielle)

**Fichier SSOT** : `plans/masterplay/MASTERPLAY_QUEUE.md`  
**Plan parent** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`

D’après la dernière file d’exécution (à re-vérifier en lisant le fichier) :

- **M-19 → M-22 (hors M-04A)** : exécutées et en général **CLOSED** (sentinels, quote, KDS, fiscal, opérations, kiosk, web/Stripe, migrations, préflight, canary, M-21b, M-22, etc. selon le tableau).  
- `**CV1-M04A-PAYMENT-LEDGER-FULL`** : **BLOCKED** (décision Option B). Toutes les autres **CV1-M** listées = **CLOSED** dans l’idée « mission masterplay finie côté code ».

Détails d’audits, REWORK, traces : `reports/audit/GPT_*.md`, `reports/audit/CLAUDE_*.md`, `reports/masterplay/status.json`, `missions/CV1-M*/*/`.

---

## 3) Vague 2 — Wave 2 (lots `CV1-LOT-`*, 36 runs, Option B)

**Ordre** : `missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md` (table 1–36).  
**Préparation** : 36 dossiers `missions/CV1-LOT-*/` avec `input.json`, `execute_brief.md`, `plan_excerpt.md`, `graphiti_context.md`, `README.md`. Générateur : `scripts/prepare-w2-option-b-missions.mjs`.  
**Mémoire d’avancement** (append) : `memory/episodes/caisse_v1_wave2_option_b_2026-04-26.jsonl` + `reports/memory/jsonl_manifest.json` après exécutions.  
**Politique** : chaque `input.json` contient `option_b_policy`, `allowlist`, `mandatory_tests` ; un run = un `TASK_ID`.

### 3.1 Lots terminés (PASS) — d’après l’exécution rapportée (jusqu’à P-09 inclus, avant arrêt K-09)

Cohérent avec l’enchaînement 1–24 de la table W2, les lots **D-01 → P-09** sont considérés **terminés côté Codex** avec livrables type `output_codex.json`, `reports/audit/GPT_SELF_AUDIT_CV1-LOT-*.md`, activity-log libéré, tests ciblés PASS sauf note ci-dessous :


| #     | LOT       | TASK_ID (résumé)                                   | Notes exécution (extrait)                                                                                                                                                                                                                                  |
| ----- | --------- | -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1     | D-01      | `CV1-LOT-D01-…`                                    | Lint client totals, sentinels discount / total, tests PASS.                                                                                                                                                                                                |
| 2     | P-01      | `CV1-LOT-P01-…`                                    | Quote POS explicite (`sealForCommit` / `PosOrderRequest`), `QuoteBindingTest` ; **risque** : `app/Services/Order/OrderQuoteService.php` signalé **non suivi** par `git` dans certains worktrees — **action humaine** : `git add` / commit si intentionnel. |
| 3–9   | K-01…K-03 | (routing, order type, quote pin)                   | Self-audits + tests selon lots.                                                                                                                                                                                                                            |
| 10–24 | D-04…P-09 | (delivery, payment props, KDS, after-commit, etc.) | Inclut gros champs (docs outbox, `OrderService`/`KitchenDisplaySystemOrderService`, KDS `KitchenReleaseRule`, `AfterCommitDispatchTest`, `tests/Feature` / Vitest / Playwright selon lot).                                                                 |


Fichiers produits / touchés (non exhaustif, d’après le dernier diff partagé) :  

- **App** : `app/Domain/Kds/KitchenReleaseRule.php`, `app/Services/OrderService.php`, `KitchenDisplaySystemOrderService`, listeners KDS, contrôleurs delivery, `OrderDiscountLog`, `config/broadcasting.php`, etc.  
- **Docs** : `docs/EVENT_CONTRACT.md`, `ORDER_FLOW.md`, `OUTBOX_PATTERN.md`, `REALTIME_SETUP.md`, `docs/orchestration/ORDER_EVENT_OUTBOX_CHANNEL_MAP_2026-04-26.md`, `BRANCH_FILTER_MATRIX_`*, `OS_FOS_SYMMETRY_MATRIX_2026-04-25.md`, `docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md`…  
- **Front kiosque (extraits)** : `KioskCartComponent.vue`, `KioskCategoriesComponent`, erreurs globales, `KioskPosWizardComponent.vue`, `kioskCart.js`, `posOrder.js`, `kioskAnalytics.js`…  
- **Tests** : `AfterCommitDispatchTest`, `CancelAuditTrailTest`, `DeliveryOrderContractTest`, KDS, kiosk quotes, `tests/js/`*, `tests/Playwright/kiosk-*.spec.js`…

### 3.2 Arrêt réel — K-09 `CV1-LOT-K09-POS-REALTIME-KIOSK-VIS` : `SCOPE_PRESSURE`

- **Problème** : le vrai câblage realtime / payload côté outbox est en partie dans `PersistOrderCreatedToOutbox.php` et `PersistOrderStatusChangedToOutbox.php` — **hors allowlist** du lot K-09 tel qu’exécuté.  
- **Comportement Codex** : refus d’un **faux patch partiel** (correct).  
- **Prochaine action requise** (avant de compter K-09 comme PASS) : **replan** K-09 avec **allowlist étendue** (les deux listeners + tests contrat event backend/frontend alignés) **ou** scinder en lot dédié avec gate explicite — **décision orchestration** à trancher dans l’ultra-review.

### 3.3 Lots **pas encore** exécutés après l’arrêt (ordre W2)

À partir de la table `W2_LOT_…` : #26 **K-10** (cleanup / idempotency) … jusqu’à #36 **P-15** (E2E), **sous réserve** des statuts `READY` / `BLOCKED_` dans chaque `input.json` (P-10 refund ledger, P-13 fiscal, etc. = souvent **Option B** / gates).

### 3.4 Lots initialement « bloqués avant exécution » (vérifier statut actuel)

Réf. runbook W2 : K-05, P-06, P-10, P-13 (frozen / schéma / Option B) — l’exécution a pu les traiter en **SKIP** ou **PASS** selon gates : **lire** `input.json` par `TASK_ID`.

### 3.5 Ressources d’exécution Codex (Wave 2)

- **Aucun** `claude` / sous-agent appelé pendant la boucle.  
- `npm` / `php artisan test` / Vitest / Playwright ciblés par lot.  
- Note : config Playwright peut pointer `testDir: ./tests/e2e` — ne pas mélanger avec `tests/Playwright` (documenté dans l’auto-audit K-08).  
- **+4884 / -135** (dernier agrégat user) sur l’enchaînement : volumétrie signifiante ; revue globale utile.

---

## 4) Fichiers à lire en priorité pour l’ultra-review

1. `plans/masterplay/MASTERPLAY_QUEUE.md`
2. `missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md`
3. `missions/CV1-LOT-K09-POS-REALTIME-KIOSK-VIS/{input.json, execute_brief.md, output_codex.json, GPT_SELF_AUDIT_…}`
4. `app/Listeners/PersistOrderCreatedToOutbox.php` et `PersistOrderStatusChangedToOutbox.php` (hors allowlist K-09 — cœur du gap)
5. `AGENTS.md`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` (invariants)
6. Échantillon self-audits : `reports/audit/GPT_SELF_AUDIT_CV1-LOT-*.md` (liste partielle fournie par l’humain)
7. `memory/episodes/caisse_v1_wave2_option_b_2026-04-26.jsonl`
8. `OrderQuoteService.php` (suivi git à clarifier)

---

## 5) Objectif de l’ultra-review (ce que tu dois produire)

- Cartographie **P0 / P1** (invariants, `branch_id`, dispatch après commit, symétrie OS/FOS, quote SSOT, gates, `OrderService` + chemins touchés, K-09, fichier untracked, tests manquants).  
- **Un plan d’orchestration** priorisé pour **Codex** (tâches critiques d’abord) : K-09 replan, reprise W2, correctifs ciblés — **sans** refonte hors scope.  
- Verdict explicite : prêt / pas prêt / HOLD release.

**Fin du handoff** — l’invite détaillée (prompt) est dans `CLAUDE_ULTRA_REVIEW_PROMPT_2026-04-26.txt` à lancer en terminal.