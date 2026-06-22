# MASTER PLAN — Finition V1 FoodKing — CV1-V1-FINISH-*

| Champ | Valeur |
|---|---|
| MASTER_TASK_ID | `CV1-V1-FINISH-MASTER` |
| Date | 2026-05-04 |
| Source | Ultra-review Claude terminal indépendante 2026-05-04 (verdict `PASS_WITH_HEALING`, captures `terminals/21.txt:102-1041`) |
| PARENT_CYCLE | `CV1-V1-PIVOT-MASTER` CLOSED PASS (8 cycles, 2026-05-04 13:05) |
| Owner | Cursor Claude (orchestrator + executor via sub-agents) |
| Approbation humaine | User message 2026-05-04 14:54 UTC+2 : « go non stop use sub agent routine implémentor for all an audi après ! tourne en boucle » — délégation totale exécution avec retour audit final |

---

## 1. Contexte

L'ultra-review Claude terminal a rendu `PASS_WITH_HEALING` sur le Pivot V1. Staging fonctionnel, mais **prod cutover bloqué** sur 5 actions :

1. 🔴 Sécurité Demo V2 — middleware `wizard.per_item_demo` ne couvre que les routes `/admin/composer/items/...`. Routes step/profile partagées contournables par un user `catalog.compose` connaissant un `profile_id` legacy.
2. 🔴 i18n parity — 5 clés Cycle 5 orphelines + sentinelle parity ne scanne que 2/5 dossiers Vue.
3. 🟠 Gate `CV1-V1-PIVOT-PRODUCTION-CUTOVER` — décisions humaines pending (data legacy + DB version + maintenance window).
4. 🟠 Smoketest staging — script + procédure à préparer.
5. 🟠 Vérification env prod — opération humaine au cutover.

Plus 1 fortement recommandé V1 (non bloquant strict mais qualité prod) :

6. ⚠️ A11y healing — toggle clavier, table scope, tabs ARIA non conformes WCAG 2.1 AA.

---

## 2. 6 cycles séquencés

### Cycle H1 — Sécurité Demo V2 étendue

- **TASK_ID** : `CV1-V1-FINISH-SECURITY-DEMO-V2-001`
- **EXECUTION_TIER** : `complex`
- **EXECUTE_DELEGATION** : `foodking-complex-implementer`
- **SUBSYSTEMS_TOUCHED** : `app/Http/Middleware/` (write — nouveau middleware), `app/Http/Kernel.php` (write — alias), `routes/api.php` (write — section composer profile/step), `tests/Feature/` (write — nouveau test)
- **SUBSYSTEMS_OFF_LIMITS** : tous les services Composer/Ingredients (lecture uniquement), tous frontend
- **GATE_CONDITIONS** : None anticipated (middleware additif, retour back-compat préservé)
- **INVARIANTS_AT_RISK** : I3 branch_id (read-only confirm), invariant sécurité Demo V2 (renforcé)

### Cycle H2 — i18n parity 5 clés + sentinelle 5 dossiers ✅ DONE

- **TASK_ID** : `CV1-V1-FINISH-I18N-PARITY-001`
- **STATUT** : PASS (livré 2026-05-04 ~14:55, RUN report `reports/execution/RUN_CV1-V1-FINISH-I18N-PARITY-001_2026-05-04.md`)
- **Évidence** : 5 clés ajoutées × 5 langues, sentinelle scan élargi 5 dossiers, Vitest 1149 préservé, build OK.

### Cycle H3 — A11y healing ingrédients

- **TASK_ID** : `CV1-V1-FINISH-A11Y-INGREDIENTS-001`
- **EXECUTION_TIER** : `complex`
- **EXECUTE_DELEGATION** : `foodking-complex-implementer`
- **SUBSYSTEMS_TOUCHED** : `resources/js/components/admin/ingredients/` (write), `resources/js/components/admin/demo/` (write), `tests/js/` (write — nouveaux specs A11y), `tests/playwright/critical-flow/v1-ingredients-a11y.spec.js` (write — étendu), `resources/js/languages/*.json` (write — 2 nouvelles clés × 5 langues)
- **SUBSYSTEMS_OFF_LIMITS** : tout backend, tous composants Studio/Composer hors ingrédients/demo
- **GATE_CONDITIONS** : None anticipated
- **INVARIANTS_AT_RISK** : i18n parity (renforcé par H2 sentinelle élargie)

### Cycle H4 — Gate brief production cutover

- **TASK_ID** : `CV1-V1-FINISH-GATE-PROD-CUTOVER-001`
- **EXECUTION_TIER** : `routine` (doc only)
- **EXECUTE_DELEGATION** : direct orchestration doctrine (pas de sub-agent — orchestrateur écrit le gate brief)
- **SUBSYSTEMS_TOUCHED** : `docs/gates/` (write — nouveau gate brief)
- **GATE_CONDITIONS** : ce cycle CRÉE le gate, l'humain le résoudra ensuite
- **INVARIANTS_AT_RISK** : None

### Cycle H5 — Script smoketest staging

- **TASK_ID** : `CV1-V1-FINISH-SMOKETEST-PREPARE-001`
- **EXECUTION_TIER** : `routine`
- **EXECUTE_DELEGATION** : `foodking-routine-implementer`
- **SUBSYSTEMS_TOUCHED** : `scripts/` (write — nouveau script bash), `docs/orchestration/` (write — nouvelle procédure)
- **GATE_CONDITIONS** : None
- **INVARIANTS_AT_RISK** : None

### Cycle H6 — Audit consolidé Claude terminal + CLOSE

- **TASK_ID** : `CV1-V1-FINISH-AUDIT-CLOSE-001`
- **EXECUTION_TIER** : audit (terminal Claude Opus 4.7 high)
- **AUDIT_CHANNEL** : `terminal-claude` (PRIMARY)
- **GATE_CONDITIONS** : si REWORK → boucle healing automatique max 3 rounds, sinon HUMAN_GATE
- **INVARIANTS_AT_RISK** : audit sur tous

---

## 3. Stratégie d'exécution

- **Parallélisme** : H1 + H3 lancés en // (fichiers disjoints : H1 = backend routes/middleware/tests/Feature, H3 = frontend Vue/tests/js). H2 déjà DONE.
- **Séquentiel après les // :** H4 (orchestrateur direct, ~10min), puis H5 (sub-agent routine, ~30min).
- **Audit final :** H6 = terminal Claude consolidé sur les 5 healings.
- **Boucle healing :** sur tout REWORK, planning automatique de fix + relance audit jusqu'à PASS, max 3 rounds avant HUMAN_GATE.

---

## 4. Definition of Done globale

- ✅ H1-H5 livrés avec RUN reports sous `reports/execution/`.
- ✅ Vitest baseline ≥ 1149 + nouveaux tests A11y (probable 1155+).
- ✅ PHPUnit baseline ≥ 1407 + nouveaux tests middleware (probable 1413+).
- ✅ Build `npm run dev` PASS.
- ✅ Sentinelle parity élargie scanne 5 dossiers, aucune clé orpheline.
- ✅ Gate brief `docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md` créé.
- ✅ Script `scripts/v1-pivot-staging-smoketest.sh` créé.
- ✅ Audit terminal Claude `AUDIT_VERDICT: PASS`.
- ✅ Episode mémoire `12_decisions_log.jsonl` append.
- ✅ Archive cycle `docs/orchestration/cycles/CYCLE_CV1-V1-FINISH-MASTER_2026-05-04.md`.

---

## 5. Statut

| Cycle | Statut | RUN |
|---|---|---|
| H1 Sécurité Demo V2 | EXECUTE in progress | (à venir) |
| H2 i18n parity | ✅ PASS | RUN_CV1-V1-FINISH-I18N-PARITY-001_2026-05-04.md |
| H3 A11y | EXECUTE in progress | (à venir) |
| H4 Gate brief | PENDING | (à venir) |
| H5 Smoketest script | PENDING | (à venir) |
| H6 Audit close | PENDING | (à venir) |
