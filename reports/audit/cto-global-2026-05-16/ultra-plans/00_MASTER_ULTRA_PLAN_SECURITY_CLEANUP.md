# FOODKING — MASTER ULTRA PLAN — Sécurité + Nettoyage
**Cycle** : Post-CTO-audit consolidation
**Date** : 2026-05-16
**Méthode** : 3 sub-agents parallèles (Security deep + Cleanup deep + Execution script) + orchestrator consolidation
**HEAD au moment du plan** : `adf7036e4` (chore security: untrack .env backup + gitignore harden + E2E selector fix)

---

## §0 SI TU NE LIS QU'UN PARAGRAPHE

Ce master plan consolide **6 P0 + 18 P1 sécurité + nettoyage** ré-vérifiés ligne par ligne sur HEAD actuel. **3 corrections majeures vs audit initial** : (1) "39 withoutGlobalScope" était faux — réalité **11 sites dont 10 légitimes**, **seul `PosOrderController:108` est un vrai IDOR** ; (2) "+6782 lignes frozen-zone drift" était faux — réalité **+2585 net** ; (3) "PaymentController RCE" est downgrade P0→P1 (gate `web_payment_v1.enabled=false` actif par défaut). Le seul vrai **P0 chemin-critique restant après les quick wins** est **P0-1 rotation AWS** (owner-only, gate cascade — rien d'autre ne peut commiter tant que ce n'est pas fait). Une fois rotation OK, **6 quick wins <2h** chacun peuvent landé en une session pour passer le score sécurité de 28→55 et le score gates de 27→50.

**Cible** : sécurité **28→75/100**, gates+process **30→75/100** en **3 semaines** (~58h Claude + ~24h owner, ~1.6h/jour owner).

---

## §1 ÉTAT VÉRIFIÉ vs AUDIT INITIAL

| Item audit | Audit dit | Vérification HEAD `adf7036e4` | Verdict |
|---|---|---|---|
| AWS keys leaked | non rotées | non rotées, working tree clean + gitignore hardened commit `adf7036e4` | ✅ Audit confirmé |
| RCE LanguageService | actif | actif `LanguageController.php:98` + `routes/api.php:486` sous `auth:sanctum` only | ✅ Audit confirmé |
| Sanctum tokens `['*']` | 3 sites | confirmé `LoginController:96`, `GuestSignupController:140`, `ForgotPasswordController:165` | ✅ Audit confirmé |
| IDOR cross-branch | "39 sites withoutGlobalScope" | **11 sites réels**, 10 légitimes (machine-bound, idempotency, jobs, doc comments), **seul `PosOrderController:108` est IDOR** | 🟡 Audit DRAFT, corrigé |
| PaymentController RCE primitive | P0 | gate `web_payment_v1.enabled=false` par défaut → exploit nécessite admin activer flag → **P0→P1** | 🟡 Downgrade |
| Frozen-zones drift | "+6782 lignes" | **+2585 net** (re-vérifié `git diff --stat vs main`) | 🟡 Surestimé |
| `.env.backup-pre-round2` tracké | encore tracké | **untrackée** commit `adf7036e4` | ✅ DÉJÀ FIXÉ |
| P0-8 mobile allergens | fabriqués 60/60 | **DÉJÀ FIXÉ** commit `245e8ab57` | ✅ DÉJÀ FIXÉ |
| P0-9 mobile promo stub | banner trompeur | **DÉJÀ FIXÉ** commit `245e8ab57` (`screens-main.jsx:600`) | ✅ DÉJÀ FIXÉ |
| P0-6 Stripe cents truncation | actif | **DÉJÀ FIXÉ** working tree + 3 tests GREEN (cycle 2026-05-16, à commit après rotation) | ✅ DÉJÀ FIXÉ |
| P1-24 safety-check.sh 2 zones | théâtral | **PARTIAL DONE** — script étendu à 15 zones (working tree), CI workflow TODO | 🟡 Partial |
| P1-26 AGENTS.md vs CLAUDE.md | contradiction | **PARTIAL DONE** — header désambiguïsation ajouté top AGENTS.md, décision archive vs keep dual TODO | 🟡 Partial |
| P0-7 installer URL | P0 | guard existant `installer.php` constructor `Redirect::send()` → **P0→P2** | 🟡 Downgrade |
| 10 runbooks DRAFT | non signés | confirmé `reports/runbooks/*` ligne 3 `DRAFT_SKELETON_NOT_SIGNED` | ✅ Audit confirmé |
| `.claude/settings.local.json` 159 entrées | accreted | confirmé 163 lignes/159 entrées | ✅ Audit confirmé |
| 44 commits "up" historique | obscurcissent | confirmé `git log --oneline | grep '^[a-f0-9]\+ up'` | ✅ Audit confirmé |

**Implication** : sur 30 items P0+P1 originaux, **4 sont déjà fermés** (P0-6, P0-8, P0-9, P0-S-07), **2 sont downgraded** (P0-S-02, P0-S-07), **2 sont partial done** (P1-24, P1-26). Reste **22 items réels** dont **5 P0 critiques**.

---

## §2 NOUVEAU P0 CRITICAL PATH (5 items)

| # | Item | Gate | Owner/Claude | Effort | Bloque |
|---|---|---|---|---|---|
| **1** | **AWS rotation** `AKIAYJOT77SIZHDXNYOZ` + 4 autres commits | OG-OWNER-EXECUTE | **Owner only** (console AWS) | 60min owner | Tout commit suivant |
| **2** | RCE LanguageService quarantine (`permission:settings` + path whitelist + content sanitize) | OG-RED-TEAM-FIRST | Claude + RED-team | 3h Claude | Aucun (peut être paralléle après #1) |
| **3** | Sanctum `['*']` détonation — 3 sites + role-scoped abilities + force re-login | OG-RED-TEAM-FIRST | Claude + RED-team | 6h Claude | Bloque audit IDOR (#4) |
| **4** | IDOR `PosOrderController:108` — single-site fix + audit log | OG-RED-TEAM-FIRST | Claude + RED-team | 2h Claude | Aucun |
| **5** | Backup S3 GPG + DR drill staging | OG-OWNER-EXECUTE + Claude | Owner (AWS S3 + GPG) + Claude (laravel-backup) | 4h owner + 8h Claude | GO-LIVE Le Cayenne |

**Tous parallélisables après #1** sauf #4 qui doit attendre #3 (les tokens role-scoped fournissent l'isolation contextuelle nécessaire à la garde branche-bound).

---

## §3 QUICK WINS — Sprint S1 (jour 1 après rotation, ~5h total)

Une session Claude unique peut landé ces **6 items <2h chacun** une fois la rotation AWS confirmée :

| # | Quick win | File | Effort | Score lift |
|---|---|---|---|---|
| Q1 | Commit working-tree quick wins (P0-6 Stripe + P1-24 safety-check + P1-26 AGENTS.md) en 3 commits atomiques | 4 fichiers + 1 nouveau test | 30min | Score gates +5 |
| Q2 | `gitleaks` GitHub Action + pre-commit hook | `.github/workflows/gitleaks.yml` + `.pre-commit-config.yaml` | 30min | Sécu +10 |
| Q3 | `commitlint` GitHub Action + config (block `up`/`upp`/`wip` single-word) | `.github/workflows/commitlint.yml` + `commitlint.config.js` | 30min | Process +8 |
| Q4 | `composer audit` + `npm audit` en CI (block CRITICAL) | `.github/workflows/dependency-audit.yml` | 20min | Sécu +8 |
| Q5 | `frozen-zones.yml` workflow — parse `CLAUDE.md §7` markers + fail PR si frozen touché sans LOCK doc co-commit | `.github/workflows/frozen-zones.yml` + markers in CLAUDE.md | 45min | Gates +12 |
| Q6 | Prune `.claude/settings.local.json` 159→~30 entrées via skill `/fewer-permission-prompts` | `.claude/settings.local.json` | 30min | Process +5 |
| Q7 | Cleanup 4 fichiers junk working tree (`,`, `[`, `"L'article…"`, `"Utilisateur…"`) + gitignore patterns | gitignore + rm | 10min | Hygiene +3 |

**Net après Sprint S1** : sécurité **28→45**, gates **27→50**, process **41→55**. Total ~3h Claude work pour +20 points pondérés.

---

## §4 PLAN EXÉCUTION 3 SEMAINES — RÉSUMÉ EXÉCUTIF

> Plan hour-by-hour complet dans `EXECUTION_SCRIPT_3_WEEKS.md` (1090 lignes).

### Semaine 1 — Sécurité hard unblock (Jours 1-5)

- **Jour 1 (lun)** — Owner rotation AWS (matin 2h) + Claude commit Stripe/safety-check/AGENTS (matin 1h) + Q2-Q7 quick wins (am) + P1-22 SQL exécuté par owner (am 30min)
- **Jour 2 (mar)** — Prompt #2 Dispatch : Architect+Security+Implementer parallèles → RCE LanguageService + Sanctum role-scoped + force re-login + RED-team review après
- **Jour 3 (mer)** — IDOR PosOrderController fix + 87/93 FormRequests `return true;` audit (top-5 endpoints sensibles)
- **Jour 4 (jeu)** — Owner crée S3 bucket + GPG key + Sentry + BetterUptime + Slack webhook + Claude installe spatie/laravel-backup
- **Jour 5 (ven)** — DR drill staging : drop orders table → restore → outbox replay → Z-report close (timer + documentation)

### Semaine 2 — Hygiène + ops gates (Jours 6-10)

- **Jour 6** — Frozen-zones CI workflow complet (Q5 étendu) + ratchet baseline + 5 RETRO_LOCK docs pour drift accumulé
- **Jour 7** — Owner joue 4 runbooks critiques en staging (chrono + identification blocages) → Claude itère runbooks → SIGNED
- **Jour 8** — `bin/deploy.sh` + `bin/rollback.sh` atomic symlink + supervisor/systemd units + canary deploy testé
- **Jour 9** — E2E bloquant CI (drop `e2e-required` label + `continue-on-error`) + smoke pack 5 specs minimum + stress MySQL CI matrix
- **Jour 10** — POS direct-cash → CashMovement Feature test (Wave Z gap closed) + 18/23 `assertTrue(true)` replacements batch 1

### Semaine 3 — Architecture P0 critical path (Jours 11-15)

- **Jour 11** — Doctrine consolidation : décider archive AGENTS.md vs keep dual + consolider `docs/ARCHITECTURE*.md` + CLAUDE.md §7 begin/end markers
- **Jour 12** — Prompt #5 : OrderStateMachine sole writer enforce + 5 sites OrderService refactor + CI lint guard pour `->status =` dans `app/Services/`
- **Jour 13** — Prompt #16 : KDS UX 8-P0 heal (Designer+Implementer+QA visual subagents + Playwright Read screenshots)
- **Jour 14** — Owner sign LOCK_ORDER_COLLAPSE doc (Prompt #6 PLAN ONLY) → impl 4 semaines en parallèle
- **Jour 15** — Owner sign LOCK_POS_WIZARD doc (Prompt #17 PLAN ONLY) → impl ~16h Week 4

### Jours 16-21 — Reserve healing/buffer

Tampon pour Round-2 de tout item qui a cassé en Sprint 1-3. Aussi : nettoyage 33 branches stale, plasticized cheatsheet runbooks Le Cayenne, prep Week 4 dispatch.

---

## §5 EFFORT TOTAL & BUDGET

| Catégorie | Owner | Claude | Wall-clock |
|---|---|---|---|
| Sécurité (5 domaines, 22 items net) | ~6h (rotation + S3/GPG + sign-off) | ~52h | 5-7j |
| Cleanup hygiène (6 domaines, 26 items) | ~6h (runbooks + branch hygiene) | ~30h | 3-4j |
| Architecture P0 (Order collapse, state machine, mobile, POS LOCK) | ~6h (LOCK sign-off) | ~16h (PLANS ONLY semaine 3) | impl Week 4-6 |
| Buffer Week 1-3 healing | ~6h | ~10h | continu |
| **TOTAL 3 semaines** | **~24h (1.6h/jour)** | **~108h (7h/jour)** | **21 jours** |

**Score evolution attendue** :
- Sécurité : 28 → **75** (+47)
- Gates+process : 30 → **75** (+45)
- Production-readiness : 38 → **65** (+27)
- Global pondéré : 32 → **52** (+20) — **GO Le Cayenne unlock**

---

## §6 OWNER ACTIONS EXCLUSIVES (5 moments BLOQUANTS)

| # | Quand | Action | Durée | Bloque |
|---|---|---|---|---|
| 1 | Jour 1 matin | Rotation AWS console (`AKIAYJOT77SIZHDXNYOZ`) + 4 autres commits leaked | 2h | TOUT |
| 2 | Jour 1 après-midi | Exécuter `sql-prep/P1-22-branch-status-fix.sql` étapes 1→5 sur prod DB | 30min | Listener cleanup follow-up |
| 3 | Jour 4 matin | Créer S3 bucket object-lock + IAM backup-writer + GPG key + Sentry + BetterUptime + Slack webhook | 1h | Jour 5 DR drill |
| 4 | Jour 5 matin | DR drill en staging (joue restore complet) | 2h | Acceptance P0-4 backup |
| 5 | Jour 14 + 15 | Sign LOCK_ORDER_COLLAPSE + LOCK_POS_WIZARD docs | 1h chacun | Impl Week 4+ |

**Total owner blocking** : ~7h critiques sur 24h budget. Reste 17h pour : code review des fixes Claude, runbook play-throughs, decisions architecture, daily check-ins.

---

## §7 ACCEPTANCE CRITERIA MASTER CHECKLIST

Ces items doivent être **TOUS COCHÉS** avant GO-LIVE Le Cayenne (Day 21).

### Sécurité (must-have 12/12)
- [ ] AWS keys rotées + ancienne désactivée (24h grace done)
- [ ] `gitleaks` workflow CI vert sur main + pre-commit installed local
- [ ] `composer audit` + `npm audit` workflows CI green (zero CRITICAL)
- [ ] Sanctum aucun token wildcard `['*']` (CI lint blocking + audit DB existing tokens)
- [ ] Sanctum role-scoped abilities documentées (`pos:order`, `kiosk:order`, `admin:*`)
- [ ] LanguageService route sous `permission:settings` + path whitelist test green + content `<?` rejection test green
- [ ] `PosOrderController:108` branch-bound assertion test green + audit log écrit
- [ ] `withoutGlobalScope(BranchScope)` 11 sites annotés (10 légitimes documentés)
- [ ] PHPSpreadsheet ≥ 2.0.0 (CVE-2024-45048 fermée)
- [ ] Laravel migration plan signed off (impl Week 4+)
- [ ] FormRequest authz top-5 endpoints critiques (kiosk auth, admin POS, fiscal Z, payment confirm, branch admin)
- [ ] Stripe.php:51 round-before-cast (`StripeCentsCastTest` 3/3 GREEN — DONE)

### Cleanup + Gates (must-have 11/11)
- [ ] `commitlint` workflow CI vert + blocking `up`/`upp`/`wip` single-word
- [ ] `frozen-zones.yml` workflow CI vert + RETRO_LOCK docs pour drift accumulé (5 fichiers)
- [ ] CLAUDE.md §7 frozen list = SSOT (begin/end markers parsés par workflow)
- [ ] `safety-check.sh` 15 zones (DONE) + pre-commit installé local
- [ ] AGENTS.md soit archivée (`AGENTS_LEGACY_*.md`) soit header désambiguïsation final (PARTIAL DONE)
- [ ] `docs/ARCHITECTURE.md` vs `docs/ARCHITECTURE_TECHNIQUE.md` consolidées (1 SSOT)
- [ ] `.claude/settings.local.json` pruned 159→~30 entrées
- [ ] 4 fichiers junk supprimés (`,`, `[`, error-message-named) + gitignore patterns
- [ ] 33 branches stale `feature/*` taggées archive ou supprimées
- [ ] Branch protection sur `main` (PR review + status checks + no force push)
- [ ] PR template `.github/PULL_REQUEST_TEMPLATE.md` + checklist owner-side

### Ops + Runbooks (must-have 9/9)
- [ ] spatie/laravel-backup quotidien S3 GPG + object-lock 6 ans + tested restore
- [ ] DR drill staging documenté avec timing
- [ ] Sentry frontend + backend wired + alerts configurées
- [ ] Slack webhook `Log::error` channel #foodking-prod-alerts
- [ ] BetterUptime ping `/health/live` 60s + alerte 2-cycles
- [ ] 4 runbooks signed (FISCAL_SEQUENCE_BREAK, KIOSK_NETWORK_LOSS, OUTBOX_BLOCKED, ROLLBACK_CANARY) + plasticized cheatsheet Le Cayenne
- [ ] `bin/deploy.sh` atomic symlink + `bin/rollback.sh` tested + supervisor/systemd units
- [ ] Canary deploy testé staging→prod (1 branche test → 100%)
- [ ] PreflightProductionCommand 15-point gate vert avant chaque deploy

**Total** : 32/32 acceptance criteria pour GO-LIVE.

---

## §8 STALE FINDINGS CORRECTION REGISTRY

Pour maintenir la fiabilité du process, voici **toutes les corrections** apportées par cette re-vérification ligne par ligne. **À appliquer comme template anti-drift pour les prochains audits**.

| Finding original | Correction | Sub-agent qui a corrigé | Méthode |
|---|---|---|---|
| "39 sites withoutGlobalScope(BranchScope)" | 11 réels, 10 légitimes, 1 IDOR | Security ultra | `grep -rn withoutGlobalScope(BranchScope app/` |
| "+6782 lignes frozen-zone drift" | +2585 net | Cleanup ultra | `git diff --stat main -- <frozen files>` |
| "AWS .env.backup tracké" | untracké commit `adf7036e4` | Cleanup ultra | `git ls-files \| grep .env` |
| "PaymentController arbitrary FormRequest P0" | P0→P1 (gate `web_payment_v1.enabled=false`) | Security ultra | read `config/payment.php` |
| "installer URL P0" | P0→P2 (`Redirect::send()` constructor guard) | Security ultra | read `installer.php` constructor |
| "ZReportService +714 / AuditLog +312 / Pricing +740" | +604 / +167 / 20-30% lower respectively | Cleanup ultra | `git diff --stat` per file |
| "P0-8 mobile allergens fabriqués" | DÉJÀ FIXÉ commit `245e8ab57` | Orchestrator quick wins | `git log -p -S 'allergens fabric' mobile/` |
| "P0-9 mobile promo stub" | DÉJÀ FIXÉ commit `245e8ab57` | Orchestrator quick wins | `git log -p -S 'discount' mobile/screens-main` |

**Anti-drift pattern à coder dans les prochains prompts d'audit** :
```
For every finding flagged P0/P1, before declaring it active, RE-VERIFY by:
1. Reading the actual file at the cited line
2. Running `git log -p -S '<keyword>' <path>` to check recent fixes
3. If already-fixed, mark ✅ ALREADY FIXED and exclude from action plan
```

---

## §9 INDEX DES DELIVERABLES

### Ultra plans (deep, paste-ready)
| Fichier | Lignes | Contenu |
|---|---|---|
| `ultra-plans/00_MASTER_ULTRA_PLAN_SECURITY_CLEANUP.md` | 350 | Ce fichier (executive consolidation) |
| `ultra-plans/SECURITY_ULTRA_PLAN.md` | 2078 | 5 domaines sécu, 22 items détaillés avec code before/after + tests + rollback |
| `ultra-plans/CLEANUP_HYGIENE_ULTRA_PLAN.md` | 2140 | 6 domaines cleanup, 26 items détaillés avec YAML snippets + tests + rollback |
| `ultra-plans/EXECUTION_SCRIPT_3_WEEKS.md` | 1090 | Hour-by-hour 21 jours + owner cheatsheet + escalation patterns + GO-LIVE checklist |

### Audit racine (référence)
| Fichier | Contenu |
|---|---|
| `00_FINAL_CTO_VERDICT.md` | Verdict 32/100 + 11 axes notés + 5 phases roadmap |
| `agent-{1..8}-*.md` | 8 rapports détaillés sub-agents (architect, security, dba, sre, qa, frontend, competitive, claude-dep) |
| `EXECUTION_ROADMAP_V1.md` | 12-week roadmap initial (à remplacer par EXECUTION_SCRIPT_3_WEEKS) |
| `AGENT_DISPATCH_PACK.md` | 22 prompts ready-to-paste pour chaque P0/P1 |
| `OWNER_GATES_REGISTRY.md` | Classification 5-gate + acceptance + rollback per item |
| `QUICK_WINS_EXECUTED_2026-05-16.md` | Session 2026-05-16 — quick wins delta done |
| `sql-prep/P1-22-branch-status-fix.sql` | SQL ready-for-owner |

---

## §10 PROCHAINS COUPS RECOMMANDÉS

**Pour toi (owner)** :
1. Confirmer rotation AWS (P0-1) → me dire "rotation OK"
2. Lire `EXECUTION_SCRIPT_3_WEEKS.md` §1 Day 1 (10 min)
3. Lancer Day 1 owner actions : SQL P1-22 + AWS rotation + lire les diffs working tree

**Pour Claude (cycle suivant après ton "rotation OK")** :
1. Sprint S1 quick wins (3h) : 3 commits atomiques quick wins déjà prêts + Q2-Q7 (gitleaks + commitlint + dep audit + frozen-zones workflow + settings prune + junk cleanup)
2. Préparer Prompt #2 dispatch (RCE + Sanctum) pour Day 2

**Pour les prochains audits** :
1. **Mandatory pattern** : tous les sub-agents auditeurs doivent inclure dans leur prompt l'instruction "RE-VERIFY current state before flagging — `git log -p -S` + read file at cited line". Évite stale findings (4 false positives détectés cette session).
2. **Anti-cumul drift** : `frozen-zones.yml` workflow (Q5) doit être actif AVANT prochain cycle long pour éviter +2585 lignes de drift supplémentaire.
3. **CI gates first** : avant tout nouveau gros refactor, le pack CI (gitleaks + commitlint + frozen-zones + dep-audit) doit être en place. Sinon, on accumule encore plus de dette.

---

**Signature** : Master plan consolidé via `/superpower-gstack` pipeline — 3 parallel sub-agents (Security deep + Cleanup deep + Execution script) → orchestrator consolidation. 0 fichier code modifié. READ-ONLY synthesis. Cite chaque finding avec sa source.

*Owner peut maintenant exécuter Day 1 dès rotation AWS confirmée.*
