# REPORT — Wave 5 HEAL — 3 BLOCKERS HIGH closed

**Date :** 2026-05-08
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**HEAD final :** `9dc009ec9` (entrant `4a9d5a115` FINAL_REPORT v3)
**Agent :** general-purpose (HEAL Wave 5)

---

## Executive Summary

| # | Finding | Status | Sentinels | Régression |
|---|---|---|---|---|
| 1 | **WAVE5-SEC-001** Privilege escalation 4 user services | ✅ closed | 14/14 PASS, 27 assertions | 0 break |
| 2 | **WAVE5-POS-001** Refund counter-entry sans seal-check parent | ✅ closed | 4/4 PASS, 10 assertions | 0 break |
| 3 | **WAVE5-KIOSK-001** Kiosk dine-in V1 backend enforcement | ✅ closed | 4/4 PASS, 11 assertions | 0 break |

**Total Wave 5 HEAL : 22 PASS / 0 FAIL / 48 assertions sentinels + ~445 tests régression smoke verts.**

---

## Section 1 — Blocker 1 : WAVE5-SEC-001

**Drift confirmé :** tautology `if (!in_array(EnumRole::CUSTOMER, [EnumRole::ADMIN]))` dans 4 services × 3 méthodes mutantes. Branch Manager pouvait pivoter cross-role (ex. rotater password Admin via `PUT /api/admin/customer/{admin_user_id}`).

**Fix scope-minimal :** nouvelle méthode privée `assertTargetRole(User)` dans chaque service, appelée AVANT le `try {}` (placement critique — le catch wrappe Exception→422 sinon). Throws `\Symfony\Component\HttpKernel\Exception\HttpException(403)`.

**Files patched (4) :**
- `app/Services/CustomerService.php` (+18 LOC)
- `app/Services/WaiterService.php` (+18 LOC)
- `app/Services/ChefService.php` (+18 LOC)
- `app/Services/DeliveryBoyService.php` (+18 LOC)

**Sentinel créé :** `tests/Feature/Sentinels/UserMgmtRoleTargetSentinelTest.php` — 14 cases (1 sanity role-ID + 12 négatifs cross-role + 1 happy-path).

**Note importante :** Service-level testing (DB unchanged via assertException). Seedage explicite des rôles avec IDs alignés EnumRole car Spatie `hasRole(int)` matche par primary key.

## Section 2 — Blocker 2 : WAVE5-POS-001

**Drift confirmé :** `RefundWithCounterEntryService::execute()` n'enforce pas que parent est dans Z fermée → mirror créé pre-Z + parent encore mutable via standard pre-Z RETURNED → double-comptabilisation NF525.

**Décision orchestrateur (advisor) :** ajouter méthode `assertSealed(Order, string)` à `SealedOrderGuard` (complément à `assertMutable`) plutôt qu'inliner la query ZReport — préserve le single-source-of-truth design intent. Honor le même feature flag `fiscal.sealed_z_guard_enabled`.

**Signature `assertSealed` :** same predicate as `assertMutable` (ZReport STATUS_CLOSED + opened<created<=closed) **inverted** : throws `InvalidArgumentException(422)` quand parent N'EST PAS sealed.

**Files patched (2) :**
- `app/Services/Order/SealedOrderGuard.php` (+34 LOC : nouvelle méthode + docstring)
- `app/Services/Order/RefundWithCounterEntryService.php` (+11 LOC : guard call avant DB::transaction)

**Sentinel créé :** `tests/Feature/Sentinels/RefundCounterEntryRequiresSealedParentSentinelTest.php` — 4 cases (pre-Z blocks, post-Z allows, already-mirror'd preserved, feature-flag rollback bypass).

## Section 3 — Blocker 3 : WAVE5-KIOSK-001

**Drift confirmé :** `OrderRequest:150-152` accordait aux kiosk tokens un bypass total des restrictions order_type. Lecture `KioskCartComponent.vue:357` (read-only frozen-zone) confirme **`OrderType::KIOSK = 25` = "Sur place" sémantiquement** (pas un flag de provenance). Asymétrie POS↔Kiosk : POS path enforçait via `PosOrderRequest:127` mais kiosk passait à travers.

**Correction au prompt orchestrateur :** prompt original disait `config('pos.dine_in_enabled')` — n'existe pas. SSOT codebase = `Settings::group('pos')->get('pos_dine_in_enabled', false)` (Smartisan repository).

**Fix BACKEND-ONLY :** nouvelle clause dans `OrderRequest::withValidator()` AVANT le bypass kiosk existant, qui rejette `OrderType::KIOSK` ET `OrderType::DINING_TABLE` (defense-in-depth) quand setting `pos_dine_in_enabled` retourne false. `OrderType::TAKEAWAY` reste autorisé.

**Test infrastructure adaptée :** `tests/TestCase.php::seedMinimalSettings()` += 1 ligne `pos_dine_in_enabled=1` pour préserver les ~21 tests existants postant `OrderType::KIOSK`. Production default reste `false` (fallback). Évite mass-modify 21 fichiers.

**Files patched (2) :**
- `app/Http/Requests/OrderRequest.php` (+15 LOC : kiosk dine-in clause)
- `tests/TestCase.php` (+1 LOC : pos_dine_in_enabled seed)

**Sentinel créé :** `tests/Feature/Sentinels/KioskDineInDisabledV1SentinelTest.php` — 4 cases (KIOSK rejected, DINING_TABLE rejected, TAKEAWAY allowed, KIOSK forward-compat enabled).

## Section 4 — TDD trace

**Sentinels neufs :** 22/22 PASS, 48 assertions.

**Régression smoke (~445 tests verts) :**
- `tests/Feature/Sentinels/` (154 tests) — OK 2 SKIP
- `tests/Feature/Fiscal/` (139 tests) — OK
- `tests/Feature/Order/` (22 tests) — OK
- `tests/Feature/Stock/` (55 tests) — OK 4 SKIP
- `tests/Feature/Symmetry/` (5 tests) — OK
- `KioskSecurityTest` (6) + `KioskFrontendComprehensiveTest` (4) + `PaymentConfirmAbilityTest` (6) — OK
- filter `/Kiosk(Quote|Loyalty)/` (17) — OK
- filter `/Customer|Waiter|Chef|DeliveryBoy/` (5) — OK
- filter `/PaymentConfirm|PosCollect/` (18) — OK
- `QuoteCurrencyOriginTest` (2) + `CleanupVsConfirmRaceTest` (1) + `MultiVariationValidationTest` (9) — OK

## Section 5 — Anti-drift checklist 12 cases

- [x] Technique — signatures cohérentes, types préservés
- [x] Business — invariants V1 (dine-in disabled, role-target, post-Z mirror only)
- [x] Architecture — pas de god class, SealedOrderGuard étendu en symétrie
- [x] Test — sentinels indépendants RefreshDatabase, 22 cases
- [x] Sécurité — privilege escalation neutralisée + DB unchanged + Z window enforce
- [x] Performance — 1 SELECT ZReport + 1 hasRole loadMissing, négligeable
- [x] UX — messages explicites, JsonValidationErrors normalisé
- [x] Dépendance — 0 composer require new, vendor inchangé
- [x] Config — seedMinimalSettings += 1 ligne, fiscal.sealed_z_guard_enabled honoré
- [x] Docs — tags `[WAVE5-SEC-001]` / `[WAVE5-POS-001]` / `[WAVE5-KIOSK-001]` grep-friendly
- [x] Commit — 3 commits atomiques `audit(WAVE5-XXX-001): ...` format strict
- [x] Portée — 0 frozen-zone touchée, 0 migration

## Section 6 — Tests run finaux

22 sentinels neufs PASS + ~445 régression smoke verts + 0 FAIL + 6 SKIP pre-existing.

## Section 7 — Frozen-zones touchées

**AUCUNE.** TOUTES intactes :
- POS Vanilla JS (`public/js/pos-app.js`)
- 8 Kiosk Vue wizards (incl. KioskCart — fix backend-only)
- OrderStateMachine (`app/Services/OrderStateMachine.php`)
- FiscalSequenceService (`app/Services/Fiscal/FiscalSequenceService.php`)
- ZReportService cœur
- AuditLogService HMAC chain
- Payment Gateways (`app/Services/Payment/Gateways/*`)

`SealedOrderGuard` (méthode `assertSealed` ajoutée) NON dans liste frozen-zones explicite — extension symétrique autorisée.

## Section 8 — Migration

**0 migration. 0 schema change.**

## Section 9 — Décision orchestrateur recommandée

**Verdict : `continue` → READY FOR MERGE PROD**

Justifications :
- 3/3 BLOCKERS pre-merge HIGH closed avec 22 sentinels couvrants
- 0 frozen-zone, 0 migration, fix scope-minimal (~120 LOC code utile)
- ~445 tests régression verts sur Fiscal/Order/Sentinels/Kiosk/Payment/User mgmt
- Symétrie POS↔Kiosk rétablie sur dine-in V1
- NF525 invariant renforcé (mirror counter-entry post-Z only)
- Privilege escalation Branch Manager → Admin neutralisée

## Section 10 — Hand-off Wave 5 résiduel

### HIGH non-bloquants restants (4 — Heal V1.0.1 hot-fix sous 7j)
| ID | Description | Effort | Priorité |
|---|---|---|---|
| WAVE5-DATA-004 | Frontend ne subscribe pas events F-016a-BIS extras/variations (UX rush) | 1j | **P0 hot-fix** |
| WAVE5-DATA-001 | Fiscal kiosk PENDING→PAID across Z window boundary | 1.5j | P1 |
| WAVE5-DATA-002 | Outbox lost-broadcast worker crash window | 1.5j | P2 |
| WAVE5-ARCH-001 | PaymentStateMachine bypass 5 sites (latent) | 1j | P3 |

### Owner-decision tracking KIOSK-001
Traité comme BLOCKER (V1 strict "À emporter only" per memory `feedback_v1_dine_in_disabled_2026-05-06`). Pour revert tolérance dine-in V1 : suffit `Settings::group('pos')->set('pos_dine_in_enabled', 1)` en prod — sentinel case 4 prouve la voie KIOSK redevient ouverte automatiquement (forward-compat). 0 rollback de code requis.

### MEDIUM (15) + LOW (16) — backlog V1.0.1 / V1.x
Cf. FINAL_REPORT_v3 §2 + §3.

### Heal-light Owner restants (~1h30 effort)
- ✅ `composer dump-autoload --optimize` (DONE)
- ⏳ Soketi/Pusher local pour multi-tab tests (full cycle + KDS sync)
- ⏳ Enrichir seed E2E (`E2E_BACKEND_AVAILABLE=1`) pour stock-rupture-sync S6.1-S6.6
- ⏳ Appliquer 5 migrations GATED OWNER au rollout window

---

## Commits finaux Wave 5 HEAL

```
9dc009ec9 audit(WAVE5-KIOSK-001): kiosk dine-in V1 backend enforcement + sentinel
c88a0ceca audit(WAVE5-POS-001): refund counter-entry SealedOrderGuard parent + sentinel
d6a16c28a audit(WAVE5-SEC-001): privilege escalation 4 user services + sentinel
```

## Annexe — Fichiers modifiés / créés (paths absolus)

**Modifiés (8) :**
- `app/Services/CustomerService.php`
- `app/Services/WaiterService.php`
- `app/Services/ChefService.php`
- `app/Services/DeliveryBoyService.php`
- `app/Services/Order/SealedOrderGuard.php`
- `app/Services/Order/RefundWithCounterEntryService.php`
- `app/Http/Requests/OrderRequest.php`
- `tests/TestCase.php`

**Créés (3 sentinels) :**
- `tests/Feature/Sentinels/UserMgmtRoleTargetSentinelTest.php`
- `tests/Feature/Sentinels/RefundCounterEntryRequiresSealedParentSentinelTest.php`
- `tests/Feature/Sentinels/KioskDineInDisabledV1SentinelTest.php`

---

**Verdict orchestrateur final v3.heal :** Branche `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` est PROD-READY. 3/3 BLOCKERS pre-merge closed. 4 HIGH non-bloquants en heal V1.0.1 (sous 7j). 15 MEDIUM + 16 LOW backlog V1.x. Frozen-zones TOUTES intactes. Aucun bloquant restant.
