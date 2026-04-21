# PLAN_P_MEGA_W8_2026-04-20 — Security K-6 enforcement + Throttle hardening + NF525 readiness (P-MEGA-20 + P-MEGA-21 + P-MEGA-22)

**Cycle parent** : `plans/PLAN_MEGA_FUNCTIONAL_CORRECTNESS_2026-04-20.md` Vague 8 (Security / NF525 / observability avancée)
**Date** : 2026-04-20
**Mode** : RUNNER_MODE single-session (orchestrator-led, **AUDIT-FIRST** strict)
**Auto-remediation** : **DÉSACTIVÉE par défaut sur tous les sous-cycles W8** — chaque sous-cycle est gated humain par construction (auth, branch_id, NF525). Re-activable **uniquement** post-approval gate écrit, et seulement pour le diff post-approval (jamais pour la décision de toucher la zone).
**HEAD baseline** : `8070bc357` (Vague 7 closed PASSED — offline queue v2 + hardware fallback + branch theming gate)
**Vitest baseline** : 700/700
**Synthèse W7 référente** : `reports/execution/SYNTHESE_P_MEGA_W7_2026-04-20.md`

**Précédents immédiats / contraintes héritées** :
- W7.A + W7.B closed PASSED (offline queue v2 + hardware fallback) — pas de modification de leurs livrables en W8.
- W7.C BLOCKED HUMAN_GATE (branch theming — schema `branches.theme_*`) — `branches` schema **OFF-LIMITS W8**.
- W5 : 3 GATES OUVERTES (P-MEGA-12 TVA / P-MEGA-13 TPE / P-MEGA-14 NF525 receipt) → composants gated W5 **OFF-LIMITS W8** (cohérent W6/W7) — voir liste exacte ci-dessous.
- Symétrie `OrderService::pay()` POS↔Kiosk déjà cassée W5 → **interdiction d'aggraver**. Aucun toucher `OrderService` / `FrontendOrderService` en W8.
- `dispatch-after-commit` invariant — aucun nouveau dispatch synchrone hors `afterCommit`.
- `branch_id` propagation invariant — modifications uniquement consommatrices, pas de réécriture du contrat.
- Worktree V14 (POS) actif et **non commité** : OFF-LIMITS comme en W7.

---

## TASK_ID

`P_MEGA_W8_SECURITY_OBSERVABILITY_2026-04-20`

---

## STRATÉGIE GÉNÉRALE — 3 sous-cycles AUDIT-FIRST en parallèle, EXECUTE séquentiel post-gate

| Sous-cycle | P-MEGA | Sujet | Critical zone | HUMAN_GATE | Routing EXECUTE (post-gate) |
|---|---|---|---|---|---|
| **W8.A** | P-MEGA-20 | K-6 enforcement `branch_mismatch` (`KioskEventController`) + 2 sentinelles | **OUI** : `branch_id` isolation logic + auth-adjacent (Sanctum tokens) | **HARD GATE pré-déclaré** (plan source) | `foodking-complex-implementer` (GPT-5.4) — `branch_id` filtering = complex per `routing.md` |
| **W8.B** | P-MEGA-21 | K-6.3 + K-6.4 backport dans `RouteServiceProvider` + `KioskThrottleKeysTest` | **OUI** : auth + rate limiting (login-lockout) | **HARD GATE pré-déclaré** (plan source) | `foodking-complex-implementer` (GPT-5.4) — auth changes = complex per `routing.md` |
| **W8.C** | P-MEGA-22 | NF525 readiness end-to-end : 4 piliers (verifyChain pre-close, schedule fiscal:archive, marqueur DUPLICATA, export JET) | **OUI** : NF525 réglementaire (signature fiscale, intégrité de la chaîne) | **HARD GATE pré-déclaré** (plan source) | `foodking-complex-implementer` (GPT-5.4) — NF525 + nouvelle commande artisan + format XML standard contrôle fiscal |

**Principe orchestration** :

1. **AUDIT-FIRST strict** — les 3 audits (W8.A.1, W8.B.1, W8.C.1) sont **readonly, parallélisables**, scopes disjoints (3 `explore` very thorough simultanés).
2. **3 GATE_BRIEFS** rédigés en parallèle après audits (W8.A.2, W8.B.2, W8.C.2) — orchestrateur seul, format `human-gates.mdc`.
3. **HALT obligatoire** après écriture des 3 briefs — humain décide gate par gate, peut approuver tout, partiellement, ou rien.
4. **EXECUTE strictement séquentiel** post-approval — A puis B puis C (jamais en parallèle ; éviter merge conflicts sur `RouteServiceProvider` ↔ `KioskEventController` ne sont pas garantis disjoints sur tests + ordre logique sécurité→audit→fiscal facilite la reprise).
5. **VERIFY 200%** par EXECUTE approuvé (sub-phase A.4 / B.4 / C.4).
6. **Re-audit final orchestrateur** (synthèse W8) avant CLOSED.

**Halt humain** déclenché sur :
1. Touche d'un fichier listé `SUBSYSTEMS_OFF_LIMITS` (gates W5 + W7.C + critical zones routing)
2. Régression Vitest scope-pertinent (chute < 700 verts)
3. Régression PHPUnit scope-pertinent (suite Auth/RateLimit/Fiscal/KioskSecurity)
4. Compteur d'essais ≥ 3 par bug_signature (règle MAX 3 `auto-remediation.mdc`)
5. Audit A.1 / B.1 / C.1 conclut "fix nécessite expansion scope vers `OrderService` / `FrontendOrderService` / `PaymentService` / migration DB" → bascule HARD GATE supplémentaire
6. ESCALATION pré-déclarée déclenchée

---

## DECOUPAGE — 3 sous-cycles, 4 phases chacun (AUDIT → GATE_BRIEF → EXECUTE conditionnel → VERIFY)

```
                ┌─── W8.A.1 audit P-MEGA-20  [readonly, parallèle]
                │           │
                │           ▼
                │      W8.A.2 GATE_BRIEF P-MEGA-20  ──► [HUMAN HALT]
START (3× explore)─┤
                │   ┌─── W8.B.1 audit P-MEGA-21  [readonly, parallèle]
                │   │           │
                │   │           ▼
                │   │      W8.B.2 GATE_BRIEF P-MEGA-21 ──► [HUMAN HALT]
                │   │
                │   └─── W8.C.1 audit P-MEGA-22  [readonly, parallèle]
                │               │
                │               ▼
                │          W8.C.2 GATE_BRIEF P-MEGA-22 ──► [HUMAN HALT]
                │
                └────────►  (HALT GLOBAL — humain décide gate par gate)

POST-APPROVAL (séquentiel A → B → C, par gate approuvé) :
   W8.X.3 EXECUTE (foodking-complex-implementer) ─► W8.X.4 VERIFY 200% (explore) ─► commit
```

### 12 phases au total

| Phase | Nom court | Type | Bloque |
|---|---|---|---|
| **A.1** | Audit P-MEGA-20 K-6 branch_mismatch enforcement | READ-ONLY | rien |
| **A.2** | GATE_BRIEF P-MEGA-20 (branch_id + auth) | Markdown brief | A.1 |
| **A.3** | EXECUTE P-MEGA-20 (post-approval) | WRITE code | A.2 approuvé |
| **A.4** | VERIFY 200% A.3 | READ-ONLY | A.3 |
| **B.1** | Audit P-MEGA-21 K-6.3+K-6.4 RouteServiceProvider | READ-ONLY | rien (parallèle A.1) |
| **B.2** | GATE_BRIEF P-MEGA-21 (auth + rate limiting) | Markdown brief | B.1 |
| **B.3** | EXECUTE P-MEGA-21 (post-approval) | WRITE code | B.2 approuvé + A.4 commit (séquentiel) |
| **B.4** | VERIFY 200% B.3 | READ-ONLY | B.3 |
| **C.1** | Audit P-MEGA-22 NF525 readiness 4 piliers | READ-ONLY | rien (parallèle A.1/B.1) |
| **C.2** | GATE_BRIEF P-MEGA-22 (NF525 réglementaire) | Markdown brief | C.1 |
| **C.3** | EXECUTE P-MEGA-22 (post-approval) | WRITE code | C.2 approuvé + B.4 commit (séquentiel) |
| **C.4** | VERIFY 200% C.3 | READ-ONLY | C.3 |

---

## RUNNER_MODE / PRIMARY_MODEL / SUBAGENT — par phase

### Phase A.1 — Audit P-MEGA-20 K-6 enforcement
- **PRIMARY_MODEL** : Claude (orchestration) → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : pure lecture statique. Comparer `KioskEventController.php` testttt vs réf p93 (déjà documenté dans `reports/audit-orchestration/REPORT_TASK15_SECURITY_K6_2026-04-20.md`). Confirmer que testttt **n'a PAS** la branche K-6.2 (server-authoritative branch logging avec détection mismatch + log canal `security`). Inventorier tests existants `tests/Feature/KioskSecurity/*`, `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php`. Vérifier présence canal Monolog `security` (`config/logging.php`).
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_20_K6_BRANCH_MISMATCH_BASELINE_2026-04-20.md`
- **LOC report** : ~200 lignes
- **Output requis** :
  - Diff exacte testttt vs p93 sur `KioskEventController::store()` (lignes manquantes, blocs à porter)
  - Inventaire alias routes `/kiosk-event` + `/kiosk/event` dans `routes/api.php` (présence ability `kiosk:order` middleware)
  - État canal Monolog `security` (existe ? quel driver ? rotation ?)
  - Existence `KioskMachine` model + relation `branch_id`
  - Liste fichiers à toucher en A.3 avec read/write intent + check vs OFF_LIMITS
  - **Verdict critical zone** : confirmation que la modification reste **strict additif** (logging + détection) et **n'altère pas** la sémantique `branch_id` propagation existante côté order persistence (c'est un endpoint observabilité — `KioskEventController` ≠ `OrderController`)
  - Présence/absence test sentinelle `KioskEventBranchSpoofingTest` côté testttt (à porter)

### Phase A.2 — GATE_BRIEF P-MEGA-20
- **PRIMARY_MODEL** : Claude (orchestrateur) — pas de subagent
- **OUTPUT** : `docs/gates/GATE_P_MEGA_20_K6_BRANCH_MISMATCH_2026-04-20.md` selon format `human-gates.mdc`
- **LOC report** : ~120 lignes
- **Contenu obligatoire** : Trigger / Affected Subsystems / Invariants at Risk / Decision Required / 3 Options + Cancel / Approval block vide
- **Decision Required précis** : "Approuver le port additif K-6.2 enforcement (~93 LOC code + 2 tests sentinelles) **sans modification** de la sémantique `branch_id` server-authoritative existante (le `branch_id` log devient explicitement issu de `KioskMachine`, le payload claimed est conservé en forensic uniquement) ?"

### Phase A.3 — EXECUTE P-MEGA-20 (post-approval)
- **PRIMARY_MODEL** : GPT-5.4 (`foodking-complex-implementer`)
- **SUBAGENT** : `foodking-complex-implementer`
- **Justification routing** : `routing.md` impose **GPT-5.4** dès qu'une logique de filtrage `branch_id` ou tout changement adjacent à `auth` (Sanctum abilities, canal log security) est in-scope. Composer interdit ici.
- **CODE_FILES (autorisés — à confirmer A.1)** :
  - `app/Http/Controllers/Frontend/KioskEventController.php` (write — bloc K-6.2 server-authoritative + détection mismatch + log canal security)
  - `config/logging.php` (write **conditionnel** : ajouter canal `security` daily 90j si A.1 confirme absence — sinon no-op)
  - `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php` (NEW — port test p93 + adaptation factories testttt)
  - `tests/Feature/KioskSecurity/KioskMultiBranchPentestTest.php` (NEW — port test p93)
- **TEST_FILES (nouveaux)** :
  - 2 tests Feature ci-dessus, ≥6 cas combinés (mismatch logged | match no log | absence claimed | forensic meta preserved | security channel receives structured log | route alias coverage)
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_20_K6_BRANCH_MISMATCH_EXECUTE_2026-04-20.md`
- **LOC code estimées** : ~93 prod + ~120 tests = ~210 (cohérent C7 backlog)

### Phase A.4 — VERIFY 200% P-MEGA-20
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough" — branch_id + security log = enjeu fort)
- **Justification routing** : audit indépendant du diff A.3. Vérifier (a) aucune modification de la sémantique `branch_id` côté order, (b) aucun toucher OrderService / FrontendOrderService / PaymentService, (c) le payload `branch_id` claimed est **toujours** ignoré côté scope (server-side authoritative), (d) tests verts y compris suite Auth.
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_20_K6_BRANCH_MISMATCH_200_2026-04-20.md`
- **LOC report** : ~140 lignes

### Phase B.1 — Audit P-MEGA-21 throttle K-6.3 + K-6.4
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : lecture statique `app/Providers/RouteServiceProvider.php` + `tests/Feature/Auth/*` (suite RateLimit / Lockout) + recherche d'éventuels `KioskThrottleKeysTest` côté testttt (audit baseline 2026-04-20 confirme absence). Croiser avec `reports/execution/RUN_C9_C10_AUDIT_CONVERGENCE_2026-04-20.md` (déjà documenté : K-6.3 keying `kiosk:{user_id}|{ip}`, K-6.4 fallback `anon`).
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_21_THROTTLE_K63_K64_BASELINE_2026-04-20.md`
- **LOC report** : ~150 lignes
- **Output requis** :
  - État actuel `RateLimiter::for('kiosk-orders')` (testttt : `by($request->ip())` seul — confirmé baseline)
  - État actuel `RateLimiter::for('login-lockout')` (testttt : `email ?: username` mais **PAS** `?: 'anon'` fallback explicite — confirmé baseline)
  - Inventaire tests `tests/Feature/Auth/*` existants impactant rate limit
  - Vérifier que la configurabilité testttt (`config('kiosk.order_rate_limit')`, `config('auth.login_lockout.*')`) est **préservée** par le merge proposé
  - Liste fichiers à toucher en B.3 avec check OFF_LIMITS
  - **Verdict critical zone** : confirmer que le merge est **strict additif** (10 LOC net, configurabilité conservée) sans changement de la signature publique des limiters

### Phase B.2 — GATE_BRIEF P-MEGA-21
- **PRIMARY_MODEL** : Claude (orchestrateur)
- **OUTPUT** : `docs/gates/GATE_P_MEGA_21_THROTTLE_K63_K64_2026-04-20.md` selon format `human-gates.mdc`
- **LOC report** : ~100 lignes
- **Decision Required précis** : "Approuver le merge convergence : (a) `kiosk-orders` keying `kiosk:{user_id}|{ip}` (anti DoS NAT inter-kiosks), (b) `login-lockout` fallback `anon` explicite (anti bypass bucket vide), tout en préservant 100% de la configurabilité testttt actuelle ?"

### Phase B.3 — EXECUTE P-MEGA-21 (post-approval)
- **PRIMARY_MODEL** : GPT-5.4 (`foodking-complex-implementer`)
- **SUBAGENT** : `foodking-complex-implementer`
- **Justification routing** : `routing.md` impose **GPT-5.4** sur toute modification auth / rate limiting. Composer interdit ici.
- **CODE_FILES (autorisés — à confirmer B.1)** :
  - `app/Providers/RouteServiceProvider.php` (write — modifier closures `kiosk-orders` + `login-lockout` ; ~10 LOC net)
  - `tests/Feature/Auth/KioskThrottleKeysTest.php` (NEW — port p93, 5 tests)
- **TEST_FILES (nouveaux)** :
  - `KioskThrottleKeysTest` (5 cas : keying user+ip distinct buckets | fallback anon explicit | regression configurabilité testttt | login-lockout email+ip | login-lockout username+ip)
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_21_THROTTLE_K63_K64_EXECUTE_2026-04-20.md`
- **LOC code estimées** : ~10 prod + ~120 tests = ~130

### Phase B.4 — VERIFY 200% P-MEGA-21
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough" — auth = enjeu fort)
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_21_THROTTLE_K63_K64_200_2026-04-20.md`
- **LOC report** : ~120 lignes

### Phase C.1 — Audit P-MEGA-22 NF525 readiness 4 piliers
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough")
- **Justification routing** : audit fiscal critique. Lecture `app/Services/Fiscal/ZReportService.php` (`open()`, `close()`, `verifySignature()` — `verifyChain` à investiguer/créer), `app/Console/Kernel.php` (absence schedule `fiscal:archive` confirmée baseline), `app/Console/Commands/FiscalArchiveCommand.php` (existant, `foodking:fiscal:archive` ?), `resources/js/components/admin/pos/ReceiptComponent.vue` + `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` (présence/absence marqueur DUPLICATA), inventaire `JetExport*`, `PIAF*` (absence confirmée baseline).
- **REPORT_FILE** : `reports/execution/AUDIT_P_MEGA_22_NF525_READINESS_BASELINE_2026-04-20.md`
- **LOC report** : ~250 lignes
- **Output requis** (4 piliers analysés séparément) :
  - **Pilier 1 — verifyChain pre-close** : `ZReportService::open()` appelle-t-il `verifyChain` (qui doit valider la chaîne de tous les Z passés de la branche) avant de réserver le sequence_no suivant ? Si non, scope du fix (créer `verifyChain($branchId)` itérant sur tous Z `closed` + `verifySignature` chaîné). Décider seuil : verify all (potentiellement coûteux) vs sliding window N derniers ? **Question business pour gate brief.**
  - **Pilier 2 — schedule fiscal:archive** : `Console/Kernel.php` schedule actuel + commande `FiscalArchiveCommand` signature/handle. Décider fréquence (`dailyAt('02:00')` proposé plan source) + branche cible (toutes ? config ?) + retention.
  - **Pilier 3 — DUPLICATA marker** : `ReceiptComponent.vue` admin POS + `KioskConfirmationComponent.vue` (gated W5 — **OFF-LIMITS**). Identifier le composant non-gated qui rend le ticket réimprimé. Inventer mécanisme détection réimpression (compteur side-table ? flag `reprinted_at` ?). **Note OFF-LIMITS** : `KioskConfirmationComponent.vue` est gated W5 (P-MEGA-14). Le marqueur DUPLICATA côté kiosk = **OFF-LIMITS W8**. Seul le ticket admin POS / impression physique est in-scope W8.
  - **Pilier 4 — export JET / PIAF** : format XML standard contrôle fiscal FR. Inventaire infra existante (`FiscalArchiveCommand` produit déjà un zip JSONL). Décider si JET = **nouvelle commande artisan dédiée** (`foodking:fiscal:export-jet`) ou enrichissement archive. Spec format = recherche normative (DGI XML schema) — **question business pour gate brief**.
  - **Verdict critical zone** : 4 piliers tous classés critical NF525 réglementaire ; choix possible humain de **scinder le gate** (approve 1, 2, 3 sans 4 ; ou tous séparément).

### Phase C.2 — GATE_BRIEF P-MEGA-22
- **PRIMARY_MODEL** : Claude (orchestrateur)
- **OUTPUT** : `docs/gates/GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md` selon format `human-gates.mdc`
- **LOC report** : ~180 lignes (4 piliers détaillés)
- **Decision Required précis** : "Approuver les 4 piliers NF525 individuellement (verifyChain pre-close | schedule fiscal:archive | DUPLICATA marker ticket admin POS | export JET XML) ? Pour chacun : scope, dépendances, format de sortie. **Recommandation orchestrateur** : approuver piliers 1+2+3 (faible risque additif) en cycle séparé du pilier 4 (export JET = spec XML normative qui mérite revue indépendante)."
- **NOTE OFF-LIMITS rappelée** : marqueur DUPLICATA **uniquement ticket admin POS** — pas de `KioskConfirmationComponent.vue` (gated W5).

### Phase C.3 — EXECUTE P-MEGA-22 (post-approval, par pilier approuvé)
- **PRIMARY_MODEL** : GPT-5.4 (`foodking-complex-implementer`)
- **SUBAGENT** : `foodking-complex-implementer`
- **Justification routing** : `routing.md` — NF525 + nouvelle commande artisan + format XML normatif + chaîne HMAC = **complex obligatoire**. Composer interdit ici.
- **CODE_FILES (autorisés — par pilier, à confirmer C.1 + decision matrix C.2)** :
  - **Pilier 1** :
    - `app/Services/Fiscal/ZReportService.php` (write — ajouter `verifyChain($branchId): bool` + appel dans `open()` avant la transaction sequence_no)
    - `tests/Feature/Fiscal/ZOpenChainVerifiedTest.php` (NEW)
  - **Pilier 2** :
    - `app/Console/Kernel.php` (write minimal — ajouter `$schedule->command('foodking:fiscal:archive --branch=...')->dailyAt('02:00')->withoutOverlapping()->onOneServer()`)
    - `tests/Feature/Fiscal/FiscalArchiveScheduledTest.php` (NEW — assert `\Illuminate\Console\Scheduling\Schedule` contient l'entrée)
  - **Pilier 3** :
    - `resources/js/components/admin/pos/ReceiptComponent.vue` (write — marqueur DUPLICATA si réimpression détectée)
    - `app/Http/Resources/OrderDetailsResource.php` (**WARNING : gated W5 P-MEGA-14**) → **OFF-LIMITS — refactoring requis** : exposer `printed_at` / `print_count` via une **nouvelle resource dédiée** ou un **endpoint dédié** (pas modification de `OrderDetailsResource`). Décision exacte = sous-décision dans gate brief C.2.
    - `tests/js/posReceiptDuplicataMarker.spec.js` (NEW Vitest)
    - **Migration table `orders.print_count` ?** → **HUMAN_GATE supplémentaire** schema migration (pré-déclaré C.2).
  - **Pilier 4** :
    - `app/Services/Fiscal/JetExportService.php` (NEW — wrapper formattage XML conforme spec DGI)
    - `app/Console/Commands/FiscalExportJetCommand.php` (NEW)
    - `tests/Feature/Fiscal/JetExportFormatTest.php` (NEW)
- **TEST_FILES (nouveaux)** : voir par pilier ci-dessus
- **REPORT_FILE** : `reports/execution/RUN_P_MEGA_22_NF525_READINESS_EXECUTE_2026-04-20.md` (consolidé 4 piliers OU 1 par pilier si scindé)
- **LOC code estimées** : ~150 (pilier 1) + ~30 (pilier 2) + ~120 (pilier 3) + ~250 (pilier 4) = ~550 production + ~300 tests si tous approuvés ; partial selon décision

### Phase C.4 — VERIFY 200% P-MEGA-22
- **PRIMARY_MODEL** : Claude → délégué `explore` very thorough
- **SUBAGENT** : `explore` (thoroughness "very thorough" — NF525 = enjeu réglementaire)
- **REPORT_FILE** : `reports/execution/VERIFY_P_MEGA_22_NF525_READINESS_200_2026-04-20.md`
- **LOC report** : ~180 lignes

---

## SUBSYSTEMS_TOUCHED — par sous-cycle

### W8.A (P-MEGA-20) — fichiers autorisés en WRITE (post-gate)

| Path | Phase | Intent | Critical zone |
|---|---|---|---|
| `app/Http/Controllers/Frontend/KioskEventController.php` | A.3 | write (bloc K-6.2 enforcement + log security) | branch_id observability (additif) — **gated** |
| `config/logging.php` | A.3 | write **conditionnel** (canal security si absent) | logging infra (additif) — gated |
| `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php` | A.3 | NEW | — |
| `tests/Feature/KioskSecurity/KioskMultiBranchPentestTest.php` | A.3 | NEW | — |

### W8.B (P-MEGA-21) — fichiers autorisés en WRITE (post-gate)

| Path | Phase | Intent | Critical zone |
|---|---|---|---|
| `app/Providers/RouteServiceProvider.php` | B.3 | write (closures `kiosk-orders` + `login-lockout`) | auth + rate limiting — **gated** |
| `tests/Feature/Auth/KioskThrottleKeysTest.php` | B.3 | NEW | — |
| `.env.example` | B.3 | doc additive (`KIOSK_ORDER_RATE_LIMIT=5`) | — — approuvé décideur 2026-04-20 |
| `tests/Unit/Security/RateLimiterConfigTest.php` | B.3 | aligner SSOT lecture `auth.login_lockout.*` | — — approuvé décideur 2026-04-20 |
| `reports/execution/RUN_P_MEGA_W8_B_THROTTLE_EXECUTE_2026-04-20.md` | B.3 | NEW report | — — convention reports/ |

**Note** : extension scope autorisée par décideur orchestrateur 2026-04-20 (cohérent avec GATE_BRIEF_21 D2 doc + correction SSOT silencieux). Cf. ESCALATION résolue ci-dessous.

### W8.C (P-MEGA-22) — fichiers autorisés en WRITE (post-gate, par pilier approuvé)

| Path | Phase | Intent | Critical zone | Pilier |
|---|---|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | C.3 | write (`verifyChain` + appel `open()`+`close()`) | NF525 — **gated** | 1 |
| `config/logging.php` | C.3-P1 | write additif (channel `fiscal` si absent) | infra logging — additif | 1 |
| `config/fiscal.php` | C.3-P1 | NEW ou enrichir (genesis_prev_hash + verify_chain_strict) | NF525 config | 1 |
| `.env.example` | C.3-P1 + C.3-P3 | doc additive (FISCAL_GENESIS_PREV_HASH, etc.) | — | 1 + 3 |
| `app/Console/Kernel.php` | C.3-P2 | write minimal (schedule entry) | scheduling infra (additif) — gated | 2 |
| `resources/js/components/admin/pos/ReceiptComponent.vue` | C.3-P3 | write (intégration sous-composant DUPLICATA) | UI receipt (additif) — gated | 3 |
| `resources/js/components/admin/pos/ReceiptDuplicataMarker.vue` | C.3-P3 | NEW sous-composant DUPLICATA (D9=B mitigation V14 conflit) | UI additif | 3 |
| Nouvelle resource/endpoint pour `printed_at` / `print_count` | C.3-P3 | NEW | nécessite décision C.2 (pas `OrderDetailsResource` qui est gated W5) | 3 |
| `database/migrations/2026_*_add_receipt_print_count_to_orders.php` | C.3-P3 | **NEW migration** | **HARD GATE schema migration approuvée G22-P3-SCHEMA** | 3 (conditionnel) |
| `app/Services/Fiscal/JetExportService.php` | C.3-P4 | NEW | NF525 export normatif — **DEFER (spec TBD)** | 4 |
| `app/Console/Commands/FiscalExportJetCommand.php` | C.3-P4 | NEW | NF525 — **DEFER (spec TBD)** | 4 |
| `tests/Feature/Fiscal/ZOpenChainVerifiedTest.php` | C.3-P1 | NEW | — | 1 |
| `tests/Feature/Fiscal/FiscalArchiveScheduledTest.php` | C.3-P2 | NEW | — | 2 |
| `tests/js/posReceiptDuplicataMarker.spec.js` | C.3-P3 | NEW Vitest | — | 3 |
| `tests/Feature/Fiscal/JetExportFormatTest.php` | C.3-P4 | NEW (DEFER) | — | 4 |
| `reports/execution/RUN_P_MEGA_W8_C_P1_VERIFYCHAIN_EXECUTE_2026-04-20.md` | C.3-P1 | NEW report | — | 1 |
| `reports/execution/RUN_P_MEGA_W8_C_P2_SCHEDULE_EXECUTE_2026-04-20.md` | C.3-P2 | NEW report | — | 2 |
| `reports/execution/RUN_P_MEGA_W8_C_P3_DUPLICATA_EXECUTE_2026-04-20.md` | C.3-P3 | NEW report | — | 3 |

**Note** : extension scope autorisée par décideur orchestrateur 2026-04-20 (cohérent avec GATE_BRIEF_22 P1+P2+P3 ; P4 DEFER explicite). `config/logging.php` + `config/fiscal.php` + `.env.example` sont strictement additifs/config, pas changements logique métier NF525. Reports/execution sont convention.

---

## SUBSYSTEMS_OFF_LIMITS (toutes phases W8 — strict)

### Critical zones `auto-remediation.mdc` (toute touche logique = HALT immédiat)

- `database/migrations/**` — **OFF-LIMITS W8.A + W8.B** intégral. **OFF-LIMITS W8.C** sauf pilier 3 si approuvé via gate schema supplémentaire (`add_print_count_to_orders`).
- `app/Http/Middleware/Auth*`, `routes/auth*` — **TOTAL OFF-LIMITS** (W8.B touche `RouteServiceProvider` config rate limit, **pas** middleware auth).
- `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` — **TOTAL OFF-LIMITS** (symétrie déjà cassée W5, interdit d'aggraver).
- `app/Services/PaymentService.php`, `app/Services/PaymentManagerService.php` — **TOTAL OFF-LIMITS**.
- `app/Services/Pricing/**` — **TOTAL OFF-LIMITS**.
- Tout `dispatch(...)` ajouté hors `afterCommit`.
- Logique de prix côté frontend (interdiction absolue).
- `branch_id` filtering logic **modifiée** (W8.A consomme + log uniquement, ne modifie pas le contrat de scoping).

### Zones gated W5 (3 GATES OUVERTES — interdiction d'éditer même cosmétique)

- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` — gated P-MEGA-13
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — gated P-MEGA-14
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` — gated W5 (impact direct DUPLICATA pilier 3 — **OFF-LIMITS**, marqueur côté kiosk différé)
- `app/Http/Resources/OrderDetailsResource.php` — gated P-MEGA-14 (**OFF-LIMITS** — pour pilier 3 créer une **nouvelle resource/endpoint**, ne pas modifier celle-ci)

### Zones gated W7.C (HUMAN_GATE business + schema branches)

- `app/Models/Branch.php` (modifications schema theme_*) — OFF-LIMITS
- `database/migrations/*branches*theme*.php` — OFF-LIMITS

### Zones V14 worktree (ne pas écraser modifs en cours non commitées)

- `resources/js/components/admin/pos/**` — OFF-LIMITS **sauf** `ReceiptComponent.vue` pilier 3 (et **uniquement** post-coordination explicite avec worktree V14 dans le gate brief C.2 — vérifier `git status` et conflit potentiel sur `ReceiptComponent.vue`)
- `resources/js/store/modules/posCart.js`, `posParked.js` — OFF-LIMITS
- `resources/js/helpers/posBarcode.js`, `posNormalizeIds.js` — OFF-LIMITS
- `app/Http/Controllers/Admin/Pos/**` — OFF-LIMITS
- `app/Models/PosParkedOrder.php`, `app/Services/PosParkedOrderService.php` — OFF-LIMITS

### Zones admin (out of scope)

- `app/Http/Controllers/Admin/**` (sauf audit readonly) — OFF-LIMITS write

### Zones livrables W7 (closed PASSED — ne pas remanier)

- `resources/js/helpers/kioskOfflineQueue*.js`, `resources/js/helpers/kioskOfflineQueueDb.js`, `resources/js/store/modules/kioskCart.js` (parties offline queue v2) — OFF-LIMITS
- `resources/js/helpers/kioskPrinter.js`, `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`, `KioskConfirmationComponent.vue` (fallback hardware) — OFF-LIMITS
- `resources/css/kiosk-fallback.css` — OFF-LIMITS

---

## INVARIANTS_AT_RISK

### W8.A (P-MEGA-20 K-6 enforcement)

1. **`branch_id` server-authoritative** : le port K-6.2 doit **renforcer** le contrat existant (server-side `KioskMachine.branch_id` est source de vérité), **jamais** l'altérer. Mitigation : A.1 confirme structure ; A.4 vérifie diff vide sur tout fichier de scoping order.
2. **Aucun toucher à `OrderController` / `OrderService`** : `KioskEventController` est endpoint observabilité pure. Aucune corrélation avec `Order::create()` côté serveur. Mitigation : A.4 grep `app/Services/Order*` + `app/Http/Controllers/*Order*` pour diff vide.
3. **Sanctum abilities `kiosk:order` préservées** : aucune modification de la déclaration middleware sur les routes `/kiosk-event` + `/kiosk/event`. Mitigation : audit A.1 + verify A.4.
4. **Canal Monolog `security` non déjà câblé sur autre flux** : si A.1 trouve canal existant utilisé ailleurs, ajout strict additif (pas de remap). Sinon création stricte daily 90j.
5. **Pas de PII dans le log security** : payload claimed conservé en forensic uniquement (clé `branch_id_claimed`), pas d'autre champ payload propagé. Mitigation : test sentinelle assert exclusion PII.

### W8.B (P-MEGA-21 throttle K-6.3 + K-6.4)

1. **Configurabilité testttt préservée** : `config('kiosk.order_rate_limit')` + `config('auth.login_lockout.max_attempts')` + `config('auth.login_lockout.decay_minutes')` doivent rester effectifs après merge. Mitigation : test régression dédié.
2. **Symétrie buckets backwards-compatible** : un kiosk pré-merge (clé `ip()` seule) ne doit pas pouvoir contourner la nouvelle clé en se mettant hors-auth. Mitigation : test "anonymous request still rate-limited" + cas "user_id présent → bucket distinct".
3. **Pas de modification `RateLimiter::for('api')` ni `admin-mutation` ni `pos-*`** : le scope est strictement `kiosk-orders` + `login-lockout`. Mitigation : audit B.1 + verify B.4 grep diff par limiter.
4. **Aucun ajout middleware sur routes existantes** : merge porte sur les closures de définition, pas sur les attachements de routes. Mitigation : verify B.4 confirme `routes/api.php` + `routes/web.php` diff vide.

### W8.C (P-MEGA-22 NF525 readiness)

1. **Chaîne HMAC `prev_hash` immutable** : `verifyChain` est strictement lecture/validation ; ne modifie aucun Z report existant. Mitigation : audit C.1 confirme contrat read-only.
2. **`verifyChain` ne doit pas bloquer `open()` sur Z corrompu pré-existant en local/dev** : politique = exception RuntimeException avec message clair en production (refus open) ; en `dev/testing` même comportement (pour détection précoce). Mitigation : décision documentée gate C.2.
3. **Schedule `fiscal:archive` ne doit pas dispatcher hors `withoutOverlapping`** : risque archives concurrentes corrompues. Mitigation : pattern `withoutOverlapping(N)->onOneServer()` (cohérent avec `SloEvaluatorJob`).
4. **DUPLICATA marker n'altère PAS la signature HMAC du Z report** : marqueur est ticket UI uniquement, jamais propagé dans agrégats Z. Mitigation : verify C.4 vérifie absence diff `ZReport` + `OrderItemCompositionSnapshot`.
5. **DUPLICATA marker n'altère PAS le NF525 numéro fiscal** : numéro reste celui de la commande originale ; le marqueur est texte UI uniquement. Mitigation : test sentinelle.
6. **Export JET = read-only sur DB** : aucune mutation. Mitigation : verify C.4 grep absence `update`/`save`/`delete` dans `JetExportService`.
7. **Pas de schema migration sans HUMAN_GATE écrit supplémentaire** : pilier 3 (`orders.print_count`) déclenche un sous-gate explicite. Pilier 4 (export JET) **ne nécessite PAS** de migration (lit existant).
8. **`KioskConfirmationComponent.vue` gated W5 INTOUCHÉ** : marqueur DUPLICATA côté kiosk = différé cycle séparé post-décision W5 P-MEGA-14. Mitigation : OFF-LIMITS strict, verify C.4 confirme diff vide.

---

## GATE_CONDITIONS

### Hard gates pré-déclarés : **3 confirmés + 1 conditionnel + 2 héritées W7.C/W5**

| ID | Sous-cycle | Trigger | Status |
|---|---|---|---|
| **GATE_P_MEGA_20_K6_BRANCH_MISMATCH** | W8.A | Port K-6.2 enforcement (branch_id observability + canal security) | **PRÉ-DÉCLARÉ** — brief produit en A.2, EXECUTE A.3 conditionnel |
| **GATE_P_MEGA_21_THROTTLE_K63_K64** | W8.B | Merge convergence auth + rate limiting (RouteServiceProvider) | **PRÉ-DÉCLARÉ** — brief produit en B.2, EXECUTE B.3 conditionnel |
| **GATE_P_MEGA_22_NF525_READINESS** | W8.C | 4 piliers NF525 (verifyChain + schedule + DUPLICATA + JET) | **PRÉ-DÉCLARÉ** — brief produit en C.2, EXECUTE C.3 conditionnel par pilier |
| **GATE_P_MEGA_22_PILIER3_SCHEMA** | W8.C pilier 3 | Migration `orders.print_count` ou `orders.printed_at` requise pour DUPLICATA | **CONDITIONNEL** — déclenché si C.1 confirme nécessité ; sous-décision dans GATE_P_MEGA_22 |
| GATE_P_MEGA_19 (héritée W7.C) | — | Branch theming schema + business | **OUVERTE** — interdit toucher `branches.theme_*` en W8 |
| GATES W5 (P-MEGA-12/13/14) | — | TVA / TPE / NF525 receipt | **OUVERTES** — interdit toucher composants gated W5 en W8 |

### Soft gates W8 (forcent halt même hors auto-remediation)

| ID | Trigger | Action |
|---|---|---|
| SOFT_W8_VITEST | Régression Vitest scope-pertinent (chute < 700 verts) | HALT, diagnostic + remediation MAX 3 |
| SOFT_W8_PHPUNIT_AUTH | Régression suite Auth/RateLimit (post-B.3) | HALT, diagnostic + remediation MAX 3 |
| SOFT_W8_PHPUNIT_FISCAL | Régression suite Fiscal/Z report (post-C.3) | HALT, diagnostic + remediation MAX 3 |
| SOFT_W8_GATED_W5 | Détection diff sur composant gated W5 (KioskPayment/Order/Confirmation, OrderDetailsResource) | HALT immédiat, revert, gate brief obligatoire |
| SOFT_W8_V14_OVERLAP | Détection touche fichier worktree V14 non commité | HALT, revert, escalade orchestration V14 ↔ W8 |
| SOFT_W8_BACKEND_EXPANSION | Diff EXECUTE contient ≥1 fichier `app/Services/Order*`, `app/Services/Payment*`, `app/Services/Pricing*` | HALT immédiat, revert, gate brief expansion |
| SOFT_W8_W7_REGRESSION | Diff W8 touche helpers/composants livrés W7 (offline queue v2, hardware fallback) | HALT, revert, escalade |

### Gates héritées toujours opposables

- **W5** : P-MEGA-12 / 13 / 14 — composants OFF-LIMITS listés ci-dessus.
- **W7.C** : P-MEGA-19 — `branches.theme_*` schema OFF-LIMITS.
- **C9 / V14** : `dispatch-after-commit` (P11_DISPATCH_AFTER_COMMIT_REMEDIATION pending) — interdiction d'introduire de nouveau dispatch hors afterCommit en W8.

---

## ESCALATIONS pré-déclarées (count = 6)

- **E1 (Phase A.1) — Réf p93 absente du worktree testttt** : si l'audit ne peut accéder physiquement à p93, baser le port sur `reports/audit-orchestration/REPORT_TASK15_SECURITY_K6_2026-04-20.md` (déjà documenté avec citations file:line p93). Ne pas bloquer A.1 sur l'absence.
- **E2 (Phase A.3) — Canal Monolog `security` collision** : si A.1 révèle qu'un autre flux utilise déjà `Log::channel('security')` avec configuration incompatible, **STOP** et sous-décision dans gate A.2.
- **E3 (Phase B.3) — Tests Auth/RateLimit existants régressent** : si la suite `tests/Feature/Auth/*` casse post-merge (changement keying), STOP et déclencher remediation MAX 3 — au-delà gate `bug_irrésolu`.
- **E4 (Phase C.1) — `verifyChain` doit valider tous les Z passés** : si la branche a 5+ ans de Z reports, le coût `open()` devient O(N) à chaque ouverture. Décision business à intégrer gate C.2 : (a) verify all (correct mais coûteux), (b) sliding window N derniers (rapide mais incomplet), (c) async pre-check job (complexe).
- **E5 (Phase C.3 pilier 3) — Migration `orders.print_count` nécessaire** : sous-gate schema migration distinct du gate principal C.2. À documenter explicitement.
- **E6 (Phase C.3 pilier 4) — Format JET XML normatif** : spec DGI évolutive (versions multiples). Si C.1 ne peut pas figer la spec exacte au moment de l'audit (lien public DGI ou doc interne), **DÉFÉRER pilier 4** à cycle séparé — n'exécuter que piliers 1+2+3.
- ~~**ESCALATION 2026-04-21 W8.B.3**~~ **RÉSOLUE 2026-04-20** : Plan SUBSYSTEMS_TOUCHED W8.B étendu pour inclure `.env.example` (doc additive D2), `tests/Unit/Security/RateLimiterConfigTest.php` (correction SSOT `auth.login_lockout.*`), et report convention. Décideur orchestrateur a explicitement validé ces 3 ajouts comme partie intégrante du package W8.B (cohérent GATE_BRIEF_21).
- **ESCALATION 2026-04-21 W8.C-P1.3** : le brief EXECUTE approuvé pour le pilier 1 demande aussi `config/logging.php` (channel `fiscal` si absent), `config/fiscal.php` (nouvelle config NF525), `.env.example` (doc `FISCAL_*`) et `reports/execution/RUN_P_MEGA_W8_C_P1_VERIFYCHAIN_EXECUTE_2026-04-20.md`, mais ces fichiers ne figurent pas dans `SUBSYSTEMS_TOUCHED` W8.C. Conformément à `execute-context.md`, STOP tant que le plan n'est pas étendu explicitement ou que le périmètre n'est pas réduit aux seuls fichiers déjà autorisés.

---

## Test strategy

| Phase | Stratégie | Détail |
|---|---|---|
| A.1 | `no-test` (audit) | Lecture statique. Aucun nouveau test. |
| A.2 | `no-test` (gate brief) | Markdown brief. |
| A.3 | `phpunit:kiosk-security` | 2 nouveaux Feature tests (KioskEventBranchSpoofing + KioskMultiBranchPentest), ≥6 cas. PHPUnit suite KioskSecurity verte intégralement. Vitest baseline préservée 700/700 (front non touché). |
| A.4 | `no-test` + audit | Run global PHPUnit suite Auth + KioskSecurity + Vitest baseline. |
| B.1 | `no-test` (audit) | Lecture statique. |
| B.2 | `no-test` (gate brief) | Markdown brief. |
| B.3 | `phpunit:auth-throttle` | 1 nouveau Feature test (KioskThrottleKeysTest), ≥5 cas. Suite Auth/RateLimit existante verte. Vitest baseline préservée. |
| B.4 | `no-test` + audit | Run global PHPUnit Auth + RateLimit. |
| C.1 | `no-test` (audit) | Lecture statique fiscal. |
| C.2 | `no-test` (gate brief) | Markdown brief 4 piliers. |
| C.3 | `phpunit:fiscal + vitest:receipt-duplicata` | 4 tests (ZOpenChainVerified + FiscalArchiveScheduled + JetExportFormat PHPUnit + posReceiptDuplicataMarker Vitest), ≥10 cas combinés. |
| C.4 | `no-test` + audit | Run global PHPUnit Fiscal + Z reports + Vitest. |

**Pas de Playwright en W8** : scope = back security/fiscal + admin POS UI minor. Tests Vitest + PHPUnit suffisent.

**Nouveau total Vitest attendu** : `700 baseline + ~5 W8.C pilier 3 = ~705` (si pilier 3 approuvé).
**Nouveau total PHPUnit attendu** : `+ ~12 cas` (2 Kiosk security + 5 throttle + ~5 fiscal selon piliers approuvés).

---

## DoD précis par phase

### A.1 — DoD audit P-MEGA-20
- [ ] `AUDIT_P_MEGA_20_K6_BRANCH_MISMATCH_BASELINE_2026-04-20.md` produit
- [ ] Diff testttt vs p93 documenté (lignes manquantes K-6.2) avec citations file:line
- [ ] Inventaire alias routes `/kiosk-event` + `/kiosk/event` confirmé
- [ ] État canal `security` dans `config/logging.php` documenté
- [ ] Liste fichiers à toucher avec read/write intent + check OFF_LIMITS
- [ ] **Verdict critical zone** : SAFE additif OU STOP gate brief expansion
- [ ] 0 fichier modifié (vérifié `git status`)

### A.2 — DoD GATE_BRIEF P-MEGA-20
- [ ] `docs/gates/GATE_P_MEGA_20_K6_BRANCH_MISMATCH_2026-04-20.md` produit selon format `human-gates.mdc`
- [ ] Trigger / Affected Subsystems / Invariants at Risk / Decision Required précis
- [ ] ≥3 options + Cancel
- [ ] Approval block vide (pas de self-approval)

### A.3 — DoD EXECUTE P-MEGA-20 (post-approval)
- [ ] `RUN_P_MEGA_20_K6_BRANCH_MISMATCH_EXECUTE_2026-04-20.md` produit
- [ ] 2 nouveaux Feature tests verts (≥6 cas total)
- [ ] PHPUnit KioskSecurity suite verte
- [ ] 0 fichier `app/Services/Order*`, `app/Services/Payment*`, `app/Services/Pricing*` modifié
- [ ] 0 fichier gated W5 modifié
- [ ] 0 fichier worktree V14 modifié
- [ ] 0 fichier livrable W7 modifié
- [ ] Vitest 700/700 préservés

### A.4 — DoD VERIFY 200% P-MEGA-20
- [ ] `VERIFY_P_MEGA_20_K6_BRANCH_MISMATCH_200_2026-04-20.md` produit (audit indépendant)
- [ ] Confirmation invariants 1-5 W8.A
- [ ] Confirmation 0 régression
- [ ] Recommendation CLOSED ou REMEDIATION_NEEDED avec bug_signature explicite

### B.1 — DoD audit P-MEGA-21
- [ ] `AUDIT_P_MEGA_21_THROTTLE_K63_K64_BASELINE_2026-04-20.md` produit
- [ ] État `RateLimiter::for('kiosk-orders')` + `login-lockout` documenté avec citations
- [ ] Tests existants `tests/Feature/Auth/*` impactant inventoriés
- [ ] Configurabilité testttt préservation confirmée
- [ ] 0 fichier modifié

### B.2 — DoD GATE_BRIEF P-MEGA-21
- [ ] `docs/gates/GATE_P_MEGA_21_THROTTLE_K63_K64_2026-04-20.md` produit selon format
- [ ] Approval block vide

### B.3 — DoD EXECUTE P-MEGA-21 (post-approval)
- [ ] `RUN_P_MEGA_21_THROTTLE_K63_K64_EXECUTE_2026-04-20.md` produit
- [ ] 1 nouveau Feature test vert (≥5 cas)
- [ ] PHPUnit Auth/RateLimit suite verte
- [ ] Configurabilité testttt préservée (test régression dédié)
- [ ] 0 fichier hors `RouteServiceProvider.php` + tests
- [ ] Vitest 700/700 préservés

### B.4 — DoD VERIFY 200% P-MEGA-21
- [ ] `VERIFY_P_MEGA_21_THROTTLE_K63_K64_200_2026-04-20.md` produit
- [ ] Confirmation invariants 1-4 W8.B
- [ ] Confirmation 0 régression Auth

### C.1 — DoD audit P-MEGA-22
- [ ] `AUDIT_P_MEGA_22_NF525_READINESS_BASELINE_2026-04-20.md` produit
- [ ] 4 piliers analysés séparément avec verdict scope/risque/dépendances
- [ ] État `verifyChain` (existant ? à créer ?) documenté
- [ ] État `Console/Kernel.php` + `FiscalArchiveCommand` signature documenté
- [ ] Mécanisme détection réimpression DUPLICATA proposé (table ? flag ? endpoint ?)
- [ ] Spec format JET XML : pointeur normatif documenté OU défer pilier 4 explicite
- [ ] Sous-décision schema migration `orders.print_count` flagged si nécessaire
- [ ] 0 fichier modifié

### C.2 — DoD GATE_BRIEF P-MEGA-22
- [ ] `docs/gates/GATE_P_MEGA_22_NF525_READINESS_2026-04-20.md` produit selon format
- [ ] 4 piliers décomposés en sous-décisions (humain peut approuver partiellement)
- [ ] Sous-gate schema migration explicite (pilier 3 conditionnel)
- [ ] Approval block vide

### C.3 — DoD EXECUTE P-MEGA-22 (post-approval, par pilier)
- [ ] `RUN_P_MEGA_22_NF525_READINESS_EXECUTE_2026-04-20.md` produit (ou 1 par pilier)
- [ ] Tests par pilier verts (≥10 cas combinés si tous approuvés)
- [ ] PHPUnit Fiscal/Z reports suite verte
- [ ] 0 fichier `KioskConfirmationComponent.vue` modifié (gated W5)
- [ ] 0 fichier `OrderDetailsResource.php` modifié (gated W5) — pilier 3 utilise nouvelle resource/endpoint
- [ ] Vitest 700/700 préservés (+ ~5 si pilier 3 Vitest)
- [ ] Migration DB **uniquement** si sous-gate schema explicitement approuvé

### C.4 — DoD VERIFY 200% P-MEGA-22
- [ ] `VERIFY_P_MEGA_22_NF525_READINESS_200_2026-04-20.md` produit
- [ ] Confirmation invariants 1-8 W8.C
- [ ] Confirmation 0 régression Fiscal
- [ ] Recommendation CLOSED par pilier ou REMEDIATION_NEEDED

---

## Estimation effort par sous-cycle

| Sous-cycle | Phase | Type | LOC | Moteur recommandé |
|---|---|---|---|---|
| W8.A | A.1 | Markdown audit | ~200 | `explore` very thorough |
| W8.A | A.2 | Markdown gate brief | ~120 | Claude orchestrateur |
| W8.A | A.3 | Code prod + tests | ~210 | `foodking-complex-implementer` (GPT-5.4) |
| W8.A | A.4 | Markdown verify | ~140 | `explore` very thorough |
| W8.A | **Sous-total** | — | **~670** (dont ~93 LOC code prod) | |
| W8.B | B.1 | Markdown audit | ~150 | `explore` very thorough |
| W8.B | B.2 | Markdown gate brief | ~100 | Claude orchestrateur |
| W8.B | B.3 | Code prod + tests | ~130 | `foodking-complex-implementer` (GPT-5.4) |
| W8.B | B.4 | Markdown verify | ~120 | `explore` very thorough |
| W8.B | **Sous-total** | — | **~500** (dont ~10 LOC code prod) | |
| W8.C | C.1 | Markdown audit | ~250 | `explore` very thorough |
| W8.C | C.2 | Markdown gate brief | ~180 | Claude orchestrateur |
| W8.C | C.3 | Code prod + tests (4 piliers max) | ~850 | `foodking-complex-implementer` (GPT-5.4) |
| W8.C | C.4 | Markdown verify | ~180 | `explore` very thorough |
| W8.C | **Sous-total** | — | **~1460** (dont ~550 LOC code prod si tous piliers) | |
| **TOTAL W8** | | | **~2630** (dont ~650 LOC code prod max) | |

Plan source estime "Wave 8 = ~600 LOC". Notre estimation totale dépasse car incluit audits + gate briefs + verifies markdown ; le **code prod seul ~650 LOC max** (tous piliers C.3 approuvés) est légèrement supérieur car le pilier 4 (export JET XML) n'était pas chiffré individuellement dans le plan source.

---

## METRICS BASELINE à mesurer en A.1, B.1, C.1 AVANT EXECUTE

### A.1 baseline P-MEGA-20

| Metric | Méthode mesure | Cible post-A.3 |
|---|---|---|
| `kiosk_event_branch_mismatch_present` | grep `branch_mismatch` dans `KioskEventController.php` | Présent + log canal security |
| `security_log_channel_present` | grep `'security' =>` dans `config/logging.php` | Présent (daily 90j) |
| `kiosk_security_test_count` | `ls tests/Feature/KioskSecurity/*Test.php | wc -l` | +2 tests |
| `branch_id_payload_in_scope` | grep `$request->input('branch_id')` dans Controller | Conservé en forensic uniquement (pas de scoping) |
| `route_kiosk_event_alias_count` | grep `kiosk-event\|kiosk/event` dans `routes/api.php` | 2 alias confirmés |

### B.1 baseline P-MEGA-21

| Metric | Méthode mesure | Cible post-B.3 |
|---|---|---|
| `kiosk_orders_keying` | Lecture closure `RateLimiter::for('kiosk-orders')` | `kiosk:{user_id}|{ip}` keying |
| `login_lockout_anon_fallback` | Lecture closure `login-lockout` | Fallback `'anon'` explicite |
| `auth_test_count` | `ls tests/Feature/Auth/*Test.php | wc -l` | +1 test |
| `config_kiosk_order_rate_limit` | grep `kiosk.order_rate_limit` | Préservé (configurabilité testttt intacte) |
| `config_login_lockout_max_attempts` | grep `auth.login_lockout.max_attempts` | Préservé |

### C.1 baseline P-MEGA-22

| Metric | Méthode mesure | Cible post-C.3 |
|---|---|---|
| `verify_chain_method_present` | grep `verifyChain` dans `ZReportService.php` | Présent + appelé dans `open()` (pilier 1) |
| `verify_signature_method_present` | grep `verifySignature` dans `ZReportService.php` | Confirmé existe baseline |
| `fiscal_archive_command_present` | `ls app/Console/Commands/FiscalArchiveCommand.php` | Confirmé existe baseline |
| `fiscal_archive_scheduled` | grep `fiscal:archive` dans `Console/Kernel.php` | Présent (pilier 2) |
| `duplicata_marker_in_receipt` | grep `DUPLICATA\|duplicata` dans `ReceiptComponent.vue` admin POS | Présent (pilier 3) |
| `jet_export_service_present` | `ls app/Services/Fiscal/Jet*` | Présent (pilier 4) |
| `print_count_orders_column` | grep `print_count` dans migrations + Order model | Présent **UNIQUEMENT** si sous-gate schema approuvé (pilier 3) |

---

## Pattern auto-remediation par sous-cycle

| Sous-cycle | Auto-remediation | Justification |
|---|---|---|
| **W8.A** | **DÉSACTIVÉE par défaut** | Critical zone branch_id + auth-adjacent. Re-activable post-A.2 approuvé **uniquement** sur le diff post-approval (correctifs cosmétiques, lints) ; toute correction qui ré-altère la sémantique branch_id/auth = HUMAN_GATE renouvelé. |
| **W8.B** | **DÉSACTIVÉE par défaut** | Critical zone auth + rate limiting. Idem A : ré-activable post-B.2 approuvé uniquement pour corrections strictement additive/cosmétique. |
| **W8.C** | **DÉSACTIVÉE par défaut** | Critical zone NF525 réglementaire. Compte tenu du caractère légal, **aucune** auto-remediation post-approval — toute correction = ré-audit C.4 + nouveau diagnostic Claude. |

**Note** : cette désactivation est cohérente avec W5 (DÉSACTIVÉE pour 3 hard gates) et plus stricte que W7 (où A/B étaient activables). Justification : W8 = 100% critical zones par construction.

---

## Risques principaux (3 lignes max)

1. **R1 Worktree V14 conflit sur `ReceiptComponent.vue` (pilier 3)** : V14 worktree non-commité touche déjà `resources/js/components/admin/pos/PaymentComponent.vue` + autres POS. Si V14 a des modifs en cours sur `ReceiptComponent.vue`, pilier 3 EXECUTE entrera en conflit. Mitigation : `git status` strict avant C.3 + sous-coordination dans gate brief C.2.
2. **R2 `verifyChain` coût O(N) sur branche ancienne** (E4) : si une branche a 1825 jours × 1 Z/jour = 1825 Z reports, chaque `open()` deviendrait coûteux. Décision business gate C.2 : verify all vs sliding window. Risque : régression performance ouverture caisse.
3. **R3 Format JET XML non figé** (E6) : spec DGI normative évolutive (versions). Si C.1 ne peut pas figer la spec, défer pilier 4 (n'exécuter que 1+2+3). Risque acceptable : backlog différé, pas de blocage W8.

---

## Ordre d'exécution

1. **Confirmer HEAD baseline** (`git log -1 = 8070bc357`) + Vitest baseline (`npm test 2>&1 | tail -3 = 700/700`) → noter dans `ACTIVE_CYCLE.md`.
2. **Démarrage parallèle A.1 ‖ B.1 ‖ C.1** : invoquer 3× `explore` very thorough en parallèle (scopes disjoints : `KioskEventController` + `RouteServiceProvider` + `Fiscal/*`). 3 reports baseline distincts.
3. **Lecture résumés** par orchestrateur. Décisions :
   - A.1 → SAFE additif OU STOP expansion gate (E2)
   - B.1 → SAFE additif (configurabilité préservée)
   - C.1 → 4 piliers analysés ; possible défer pilier 4 si E6 (spec JET non figée)
4. **A.2 + B.2 + C.2** — orchestrateur écrit les 3 GATE_BRIEFS séquentiellement (Claude seul, ~120+100+180 = 400 LOC markdown). Update `ACTIVE_CYCLE.md` : W8.A/B/C tous = `BLOCKED_HUMAN_GATE`.
5. **HALT GLOBAL** — humain décide gate par gate. Peut approuver tout / partiellement / rien. Aucune EXECUTE possible avant approval écrit.
6. **POST-APPROVAL séquentiel A → B → C** :
   - A.3 EXECUTE (GPT-5.4) → A.4 VERIFY (`explore`) → commit atomique W8.A
   - B.3 EXECUTE (GPT-5.4) → B.4 VERIFY → commit atomique W8.B
   - C.3 EXECUTE par pilier approuvé (GPT-5.4) → C.4 VERIFY → commit atomique W8.C (1 commit consolidé ou 1 par pilier selon volume)
7. **Synthèse W8** — orchestrateur écrit `reports/execution/SYNTHESE_P_MEGA_W8_2026-04-20.md` agrégeant les 12 phases + final report per `auto-remediation.mdc` template.
8. **Update `.cursor/ACTIVE_CYCLE.md`** : PHASE W8.A/B/C selon outcome (`CLOSED PASSED` / `BLOCKED_HUMAN_GATE` / `PARTIAL` selon piliers C).

---

## ACTIVE_CYCLE update prévu

À l'ouverture du cycle :
- TASK_ID = `P_MEGA_W8_SECURITY_OBSERVABILITY_2026-04-20`
- PHASE = `AUDIT — W8.A.1 ‖ W8.B.1 ‖ W8.C.1` (parallèles readonly)
- PRIMARY_MODEL = `Claude (orchestration) + explore × 3 (audits A.1/B.1/C.1) + Claude orchestrateur (gate briefs A.2/B.2/C.2) + foodking-complex-implementer × 3 (EXECUTEs A.3/B.3/C.3 post-approval) + explore × 3 (VERIFY A.4/B.4/C.4)`
- PLAN_FILE = `plans/PLAN_P_MEGA_W8_2026-04-20.md`
- REPORT_FILES = 12 (3 audits + 3 gate briefs + 3 EXECUTE reports + 3 VERIFY reports) + 1 synthèse
- GATE_FILES = 3 pré-déclarés (`GATE_P_MEGA_20`, `GATE_P_MEGA_21`, `GATE_P_MEGA_22`) + 1 conditionnel (`GATE_P_MEGA_22_PILIER3_SCHEMA`)
- RUNNER_MODE = single-session
- AUTO_REMEDIATION = DÉSACTIVÉE sur W8.A/B/C (post-approval re-activation conditionnelle uniquement pour W8.A/B sur diff cosmétique ; **jamais** pour W8.C NF525 réglementaire)

À la fin de C.4 :
- W8.A PHASE = `CLOSED PASSED` (cas nominal post-approval) OU `BLOCKED_HUMAN_GATE` (gate non levé) OU `BLOCKED_EXPANSION_GATE` (E2 déclenché)
- W8.B PHASE = `CLOSED PASSED` OU `BLOCKED_HUMAN_GATE`
- W8.C PHASE = `CLOSED PASSED ALL_PILLARS` OU `CLOSED PASSED PARTIAL (piliers X+Y)` OU `BLOCKED_HUMAN_GATE`
- NEXT = "humain commit W8 atomiques + décide reprise W7.C theming OU W5 décisions gates en attente OU Wave 9 (P-MEGA-23 admin↔kiosk drift)"

---

## Manifeste

> Vague 8 = **3 sous-cycles 100% gated humain par construction** (security K-6 enforcement + auth/throttle hardening + NF525 readiness 4 piliers). Architecture **AUDIT-FIRST strict** : 3 audits parallèles readonly → 3 gate briefs → HALT global → EXECUTE séquentiel A→B→C uniquement post-approval écrit. Auto-remediation **DÉSACTIVÉE** par défaut (cohérent W5 ; plus strict que W7) car 100% critical zones (`branch_id` + auth + NF525). 3 GATES pré-déclarées + 1 conditionnel (schema migration pilier 3) + 6 ESCALATIONs. Le scope est délibérément **back-only** (`KioskEventController` + `RouteServiceProvider` + `Fiscal/*` + `Console/Kernel.php`) avec une exception ciblée admin POS UI (`ReceiptComponent.vue` pilier 3). **OFF-LIMITS strict** : 3 GATES OUVERTES W5 (TVA / TPE / NF525 receipt) + W7.C theming schema + worktree V14 + livrables W7 (offline queue + hardware fallback) + `OrderService` / `FrontendOrderService` / `PaymentService`. La symétrie déjà cassée W5 sur `OrderService::pay` POS↔Kiosk **n'est pas aggravée** : W8 ne touche aucun fichier order/payment. Le but : (a) durcir K-6 enforcement (port additif p93 → testttt avec test sentinelle spoofing), (b) merger K-6.3+K-6.4 RouteServiceProvider sans casser configurabilité testttt, (c) compléter NF525 readiness (chain verify + schedule archive + DUPLICATA marker + export JET) avec sous-décision business par pilier. Le pilier 4 (JET XML normatif) est **déférable** si la spec DGI ne peut pas être figée à l'audit. Pas d'auto-remédiation NF525 : toute correction post-EXECUTE = ré-audit explicit Claude + diagnostic + replan documenté.
