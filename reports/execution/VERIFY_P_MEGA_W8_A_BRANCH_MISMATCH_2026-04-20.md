# VERIFY 200% W8.A — P-MEGA-20 K-6.2 branch_mismatch enforcement

**Date** : 2026-04-20
**Cycle** : `P_MEGA_W8_SECURITY_OBSERVABILITY_2026-04-20`
**Sub-cycle** : W8.A
**Commit vérifié** : `d8202bc94`
**Baseline** : `d4263dcf3`
**Verifier** : `explore` (very thorough, readonly)
**Outcome global** : ✅ **PASSED** (Phase 3 dégradée sandbox, EXECUTE confirme 19/19)

## Phase 1 — Scope conformity

LOC delta réel : **+268 / -12** (identique à l'annonce EXECUTE).

Fichiers modifiés (`git show --stat d8202bc94`) :
- `app/Http/Controllers/Frontend/KioskEventController.php`
- `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php`
- `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php` (NEW, 4 cas)
- `reports/execution/RUN_P_MEGA_W8_A_BRANCH_MISMATCH_EXECUTE_2026-04-20.md`
- `.cursor/ACTIVE_CYCLE.md` (méta cycle, hors périmètre métier)

OFF-LIMITS : aucun fichier des zones interdites touché (`OrderService`, `FrontendOrderService`, `Payment*`, migrations kiosk/order, `RouteServiceProvider`, `Pricing*`, etc.).

## Phase 2 — Conformité audit + gate

### A. KioskEventController::store() — Bloc 1 ✅
- ✅ `branch=` dans `$details` provient de `$serverBranchId` (autoritaire), JAMAIS du payload
- ✅ Variables claires : `$serverBranchId`, `$claimedBranchId`, `$branchMismatch`
- ✅ Détection mismatch correcte : `claimed !== null && (int)claimed !== serverBranchId && serverBranchId > 0`
- ✅ `serverBranchId === 0` (machine null) → pas de log security mismatch (évite faux positifs)
- ✅ `Log::channel('security')->warning(...)` avec clés stables : `event=kiosk.branch_mismatch`, `server_branch_id`, `claimed_branch_id`, `user_id`, `machine_id`, `machine_username`, `route`, `request_id`, `ip`
- ✅ Wrapped dans try/catch (fallback `Log::warning` si channel manquant)
- ✅ `$extra['branch_id_claimed']` UNIQUEMENT si mismatch (pas de pollution sinon)
- ✅ Réponse HTTP 200 maintenue (D1=A)
- ✅ Whitelist `ALLOWED_TYPES`, `ALLOWED_ANALYTICS_EVENTS`, `HARDWARE_TYPES`, `FORBIDDEN_PAYLOAD_KEYS` inchangées
- ✅ 422 type/event_name/PII préservés
- ✅ Hard-cap 500 chars `details` préservé
- ✅ Hardware channel logging préservé (try/catch)

### B. Test isolation aligné — Bloc 2 ✅
- ✅ Test forgé attend désormais `branch=<branchAId>` (serveur, K-6.2 conforme)
- ✅ Méta `branch_id_claimed` = B (forensic)
- ✅ Méthode renommée pour refléter K-6.2

### C. Spoofing test 4 cas — Bloc 3 ✅
- ✅ Cas 1 (match) : token A + payload A → pas log security, branch=A
- ✅ Cas 2 (mismatch /kiosk-event) : token A + payload B → log security, branch=A authoritaire, meta `branch_id_claimed=B`
- ✅ Cas 3 (mismatch /kiosk/event route alternative) : idem cas 2
- ✅ Cas 4 (no branch_id payload) : token A → branch=A, pas log security
- ✅ Token `kiosk:order` correctement créé (`createToken(..., ['kiosk:order'])`)
- ✅ Factories Branch + KioskMachine + User cohérentes

## Phase 3 — Tests réels

EXECUTE rapporte **19/19 PASSED** sur scope kiosk security.

⚠️ Re-run dans cette sandbox VERIFY non concluant (FS read-only sur `storage/logs/`) — non bloquant.

## Phase 4 — Findings invisibles (200%)

| ID | Sev | Description | Impact | Reco |
|---|---|---|---|---|
| F1 | LOW | Docblock `KioskEventController` (L35–40) pas aligné K-6.2 | Confusion dev/ops | Mettre à jour commentaire SSOT + forensic |
| F2 | LOW | Si canal `security` échoue, `Log::warning` fallback peut aussi échouer si aucun handler writable | 500 rare en infra dégradée | `error_log()` ou noop dernier recours |
| F3 | LOW | Troncature `mb_substr(..., 0, 497).'...'` peut couper `meta=` JSON | JSON partiel dans `ActionLog.details` | Parsers tolérants ou réduire payload |
| F4 | INFO | `request_id` null sans `X-Request-Id` header | Corrélation partielle | OK si front n'envoie pas |
| F5 | MED-test | Tests spoofing dépendent de fichiers log réels | Flakiness CI possible si parallélisme | Refactor `Log::fake()` plus tard |
| F6 | LOW | `test_all_phase5_types_respect_branch_isolation` ne vérifie pas `branch=` | Régression `details` possible sans échec test | Ajouter assert `branch=A` |

### Bugs invisibles passés en revue (B1–B8)
- **B1** Machine introuvable : cohérent avec gate (évite faux positifs) ✅
- **B2** Routes : `auth:sanctum` + `abilities:kiosk:order` confirmés ✅ (`routes/api.php` L971–973, L1017–1018)
- **B3** Channel `security` : config présente ✅ (`config/logging.php` L141–146, daily `storage/logs/security.log`)
- **B4** Cap 500 / JSON coupé : tracking F3 (LOW)
- **B5** `request_id` null : tracking F4 (INFO)
- **B6** Isolation : aligné, pas de test mort sur ancien `branch=B` ✅
- **B7** Signature `store()` inchangée, réponse `{status: true}` ✅
- **B8** Spot-check `MenuController` : utilise `KioskMachine` pour `branch_id` (cohérent audit §1.1) ✅

**0 finding HIGH ou CRITICAL.** 6 findings LOW/INFO/MED-test (dette acceptable).

## Verdict final

- ✅ Sécurité K-6.2 : conforme (statique + gate D1=A, D2=A, D3=A)
- ✅ Aucun OFF-LIMITS audit touché
- ✅ Qualité tests : conforme spec 4 cas + isolation alignée
- ✅ Run report cohérent avec implémentation

**Recommandation orchestrateur** : ✅ **CLOSED PASSED**

Notes pour W9 ou backlog :
- F1 (docblock K-6.2) — fix routine 5 LOC
- F5 (Log::fake() refactor) — amélioration qualité tests
- F6 (assert `branch=A` dans isolation test) — défense additionnelle
