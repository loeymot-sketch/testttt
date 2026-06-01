# 🎯 GOAL — V1.0.1 Hardening + Go-Live Gate Choreography (2026-06-01)

**Branche** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `8b45c2bbd` · **Discipline** : CLAUDE.md §5 LOOP étape 2 (PLAN-ONLY, rien exécuté).
**Skill** : `ultra-architect-planify`. **Subject inference** : `/ultra-architect-planify` invoqué APRÈS l'exécution de la consolidation go-live → ce GOAL couvre **le SEUL travail non-encore-détaillé** : le **backlog hardening V1.0.1** + la **chorégraphie de fermeture des 8 owner-gates**. *Si tu visais une carte fraîche full-systèmes, dis-le — mais les 8 systèmes sont 50-cycle-validés (BRAIN §2) ; les re-décomposer serait un artefact à valeur négative (cf. advisor).*

---

## §0 — Cadrage (anti-dérive + anti-duplication)

### Scope = remainder UNIQUEMENT (validated systems OUT-OF-SCOPE)
Per advisor : NE PAS re-décomposer les systèmes 50-cycle-validés. Ils sont **out-of-scope, maintenance-only** :

| Système | Anchor (vérifié 2026-06-01) | Statut |
|---|---|---|
| POS Caisse | `Admin/PosController.php` + `public/js/pos-wizard.js` (frozen) + 17 `tests/Feature/Pos/` | ✅ validated · maintenance-only |
| Kiosk | 10 `resources/js/components/frontend/kiosk/Kiosk*Component.vue` | ✅ validated · maintenance-only |
| KDS | `KdsSyncService.php` + `KitchenDisplaySystemOrderService.php` + 9 `tests/Feature/KDS/` | ✅ validated · maintenance-only |
| OSS | `OrderStatusScreenOrderService.php` | ✅ validated · maintenance-only |
| Admin (dashboard/reports/fiscal) | `DashboardService.php` + `Fiscal/ZReportService.php` (frozen) + `ItemService.php` | ✅ converged 2026-06-01 (2e-degré + gestion) |
| Sync (Outbox/Pusher) | `BroadcastServiceProvider.php` + `Persist*ToOutbox` listeners | ✅ validated (latence 137-161ms mesurée) |
| Stock | `Menu/AvailabilityService.php` + `Stock/StockService.php` | ✅ validated · maintenance-only |
| Livreur | `DeliveryBoyService.php` + `DeliveryBoyCashSession/Movement` | ✅ validated · maintenance-only |
| Standalone Mobile / Web | `mobile/` · `/Users/1millnonstop/Downloads/web` | ✅ standalone GO (owner mandate, no API wireup V1) |

**Aucune tâche ci-dessous ne touche ces systèmes** sauf le ratchet sentinel (tests only) + les gates.

### Convergence criteria (par tâche)
Test réel OU `(à créer)` nommé · frozen-zone diff 0 · NF525 CHAIN OK · full-suite vert (baseline **2792/0** 2026-06-01).

---

## §1 — HARDENING STATUS (anchor-verified — la moitié est DÉJÀ FAITE)

⚠️ **Anchor-first a révélé que la majorité du backlog V1.0.1 hardening est déjà livrée** — ne PAS replanifier du fait :

| Item V1.0.1 (ULTRAPLAN) | Anchor | Statut RÉEL |
|---|---|---|
| Password policy min:12 | `UserChangePasswordRequest.php:34` (`Password::min(12)->letters()->numbers()`), `EmployeeRequest.php:50` | ✅ **DONE** (staff). Customer/Signup `min:6` documenté intentionnel. |
| FormRequest authz ratchet → 66 | `FormRequestAuthzDriftSentinelTest.php:65` `RETURN_TRUE_BASELINE = 66` | ✅ **DONE** (déjà ratché 69→66). |
| Sanctum proactive refresh | `routes/api.php:156` `/refresh-token` (RefreshTokenController) | ✅ **DONE** (P-AUTH heal). |
| Idempotency dual-layer + webhook_events | (BRAIN §1, iter11) | ✅ DONE. |

**Genuinely OPEN** (le vrai remainder hardening) → §2.

---

## §2 — OPEN hardening tasks (tight, anchored)

#### Sub 2.1 — Sanctum sensitive-op TTL
**Anchors** : `config/sanctum.php:51` (`'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 480)`), `routes/api.php:156` (refresh).
**Tasks** :
- T-2.1.1 Décider : garder 480min (8h) + refresh proactif (état actuel, acceptable V1 LOCAL) **OU** TTL court (1h) sur les ops sensibles (refund/Z-close/cash-variance) via une ability/scope dédiée. **owner-intent** — défaut V1 LOCAL = garder l'état actuel (single-box, faible risque vol-token).
**Acceptance** : `(test à créer tests/Feature/Auth/SanctumSensitiveOpTtlTest.php)` SI l'owner choisit le TTL court ; sinon DOC-only (noter que 8h+refresh est le choix V1 LOCAL).

#### Sub 2.2 — API-key versioning
**Anchors** : `app/Http/Middleware/ApiKeyMiddleware.php`, `config('app.api_key')`.
**Tasks** :
- T-2.2.1 Audit : la clé API est-elle rotatable sans downtime ? versioning/grace-window ? (V1 LOCAL single-box : 1 clé statique acceptable ; versioning = cloud-prep).
**Acceptance** : DOC verdict (V1 LOCAL = statique OK) OU `(test à créer tests/Feature/Security/ApiKeyRotationTest.php)` si versioning implémenté. **Lean V1 : DOC-defer cloud-prep.**

#### Sub 2.3 — FormRequest authz chip-away (V1.0.2, NON-V1-blocking)
**Anchors** : `FormRequestAuthzDriftSentinelTest.php:65` (baseline 66), `app/Http/Requests/*` (FormRequests à `return true;`).
**Tasks** :
- T-2.3.1 Refactor N FormRequests critiques `return true;` → `$this->user()?->can(...)` PUIS lower `RETURN_TRUE_BASELINE` au nouveau count (ratchet). Vague de commits, pas un seul.
**Acceptance** : `FormRequestAuthzDriftSentinelTest` GREEN au nouveau baseline + full-suite vert. **V1.0.2 backlog (post-go-live).**

#### Sub 2.4 — Composer advisories triage
**Anchors** : `composer.json` (phpspreadsheet PAS présent en direct → transitive ou résolu), `composer audit`.
**Tasks** :
- T-2.4.1 `composer audit` (online requis — indispo offline ici) → trier les advisories, MAJ les non-breaking, documenter les breaking en V1.0.2.
**Acceptance** : `composer audit` 0 CRITICAL non-trié + rapport. **Owner-run (network).**

---

## §G — OWNER GATES + GO-LIVE CHOREOGRAPHY (le vrai travail restant)

Les 8 gates (du `GOAL_V1_LOCAL_GOLIVE_CONSOLIDATION_2026-06-01.md`) en **runbook ordonné** — c'est le chemin réel vers le go-live. WHO/WHAT/WHERE :

| # | Gate | WHO | WHAT (artefact) | WHERE | Ordre |
|---|------|-----|-----------------|-------|-------|
| G1 | ZRPT-SEM-01 countersign | Owner | sign-off §6 + DECISIONS LOG | `plans/LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING_2026-06-01.md` §6 | 1 (débloque la confiance fiscale) |
| G2 | LOCK housekeeping ×5 | Owner | status-line → ACCEPTED | `plans/LOCK_*2026-05-18/23*.md` | 1 (parallèle G1) |
| G3 | (résolu) DASH-01 label | — | — | exécuté 2026-06-01 | ✅ done |
| G4 | clean 10h soak server-alone | Physical owner | log 10h + attestation (RSS flat, gap-free, CHAIN OK) | `reports/test-e2e/.../soak/` | 2 (après code-freeze) |
| G5 | `POS_SIMULATION_HARDWARE=false` + `APP_ENV=production` | Physical owner | `.env` + `php artisan config:cache` + boot OK (guards `AppServiceProvider.php:145-215`) | machine prod | 3 |
| G6 | Ansible CVP0-1 REVOKE DELETE/TRUNCATE | Physical owner | run playbook + GRANT diff sur `audit_logs`+`z_reports` | playbook CVP0-1 | 3 (parallèle G5) |
| G7 | `migrate:fresh --seed` (branche Hénin-Beaumont 50.42/2.95) | Physical owner | seed log + `SELECT branches WHERE id=1` | machine prod | 4 (après G5/G6) |
| G8 | Walk physique : TPE + tiroir + imprimante + 1 vraie commande → Z signé | Physical owner | photos + 1 Z signé réel + `fiscal:verify-chain` | on-site | 5 (final) |

**Choreography order** : G1+G2 (countersign/housekeeping, async) → code-freeze → **G4 soak 10h** (serveur SEUL, jamais en parallèle de charge — leçon crash 4.92h) → G5+G6 (prod env + Ansible) → G7 (migrate-seed) → **G8 walk + 1 Z réel** = GO-LIVE.

**Owner-gate-waiting** : aucune vague Claude ne dépend de G4-G8 (physiques) ; G1/G2 (countersign) sont async. Sub 2.1-2.4 (hardening) peuvent tourner avant/pendant les gates si l'owner les veut, mais sont **non-bloquants** go-live.

---

## §A — Agent army (référence — fan-out seulement si Sub 2.3 lancé)
Sub 2.3 (FormRequest chip-away) = Security read-only audit (identifie les `return true;` à route-non-gatée) → Implementer séquentiel (refactor + ratchet) → RED dispute → full-suite. Reste = DOC/owner. (`~/.claude/skills/superpower-gstack/references/army-dispatch.md`.)

## §X — Waves
- **Wave A (Claude, optionnel pré-go-live)** : Sub 2.1-2.2-2.4 = DOC verdicts (V1-LOCAL : garder 8h+refresh, clé statique OK, composer audit owner-run). Checkpoint : verdicts écrits, 0 code sauf si owner réactive.
- **Wave B (Claude, V1.0.2 post-go-live)** : Sub 2.3 FormRequest chip-away (vague de commits + ratchet). Checkpoint : sentinel GREEN au nouveau baseline, full-suite vert.
- **Wave G (OWNER, le go-live réel)** : G1→G8 choreography ci-dessus. Checkpoint : 1 Z signé réel + CHAIN OK sur la machine prod.

Interrupt-resume : commit WIP + `reports/test-e2e/v1-0-1-hardening/INTERRUPT_<wave>.md` + BRAIN §2.

---

## §F — DONE criteria
- **Go-live LOCAL** = G1+G2 résolus + G4 soak 10h vert + G5-G7 (prod env/Ansible/seed) + **G8 : 1 vraie commande → paiement → Z signé sur la machine prod, CHAIN OK**. C'est le seul chemin réel ; tout le code est prêt + vert (2792/0).
- **V1.0.1 hardening** : Sub 2.1/2.2/2.4 = verdicts DOC (la plupart déjà DONE per §1) ; Sub 2.3 chip-away = V1.0.2 non-bloquant.
- **NE PAS** re-décomposer les systèmes validés. Production-perfect = les gates fermés, pas un 16ᵉ GOAL.
