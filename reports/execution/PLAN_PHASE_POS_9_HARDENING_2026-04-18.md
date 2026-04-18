# PLAN — Phase POS-9 Hardening (post-audit transverse) — 2026-04-18

**Source** : `reports/review/AUDIT_POS_9_HARDENING_2026-04-18.md` (4 subagents readonly).

**Objectif** : Refermer les 5 P0 et 18 P1 découverts par l'audit cross-cutting post-POS-9.4, AVANT de démarrer POS-9.2 / POS-9.5. Stabiliser la chaîne fiscale de bout en bout pour que le merge POS-9.4 → main soit une vraie livraison NF525-compatible et pas juste de l'infrastructure.

**Branche cible** : `feat/pos-phase-9-hardening` (forké de `feat/pos-phase-9-4` pour continuer la chaîne ; rebase sur main avant merge final).

**Zones autorisées** :
- `app/Services/OrderService.php` → **autorisé** (POS-9.1 déjà mergée, le "freeze" ne concerne que `feat/pos-phase-9-4` branche vs Kiosk P9.5 qui n'est toujours pas démarré). Vérifier `CROSS_TRACK_STATUS.md` avant commit.
- Tous les fichiers fiscaux / audit_logs / z_reports / permissions / config : zone B-exclusive.

**Règles héritées** : commits atomiques `fix(pos/phase-9-h.X.Y)` ou `feat(pos/phase-9-h.X.Y)`. 1 test dédié par item. Verifier indépendant final. `FINDINGS_POS_TRACKER.md` + `CROSS_TRACK_STATUS.md` mis à jour.

---

## Vague H.1 — P0 zero-risk (sécurité / tracker / CI guard) — ~2 h

**Conditions** : aucune dépendance, tous les items touchent des zones POS-exclusives ou déjà modifiées.

| # | ID | Titre | Fichiers | Effort | Test |
|---|---|---|---|---|---|
| H.1.1 | F-A1 | Propagation `HttpException` dans 4 méthodes `OrderService` (pattern POS-9.1.2) | `app/Services/OrderService.php` (changeStatus auth=false, changePaymentStatus, tokenCreate, deliveryBoyOrderChangeStatus) | 20 min | `HttpExceptionPropagationTest` (4 cas — 1 par méthode) |
| H.1.2 | F-A2 | Seeder `RolePermissionTableSeeder` : flat strings au lieu de `[['name'=>x]]` | `database/seeders/RolePermissionTableSeeder.php:25-86` | 15 min | `RolePermissionSeederTest::test_branch_manager_gets_expected_permissions` |
| H.1.3 | F-A3 | `DashboardService::auditTrail` drop `orWhereNull` + `ActionLog::booted()` `is_null` | `app/Services/DashboardService.php:314-322`, `app/Models/ActionLog.php:27-36` | 15 min | `DashboardAuditTrailIsolationTest::test_null_branch_rows_hidden` |
| H.1.4 | F-A4 | `BranchIsolationTest` → `assertStatus(403)` pur + supprime early-out | `tests/Feature/BranchIsolationTest.php` | 15 min | Test existant durci |
| H.1.5 | F-A5 | `PosOrderRequest` : dine-in gate serveur + int cast | `app/Http/Requests/PosOrderRequest.php:50-53` | 20 min | `PosDineInServerGateTest::test_order_type_15_rejected_when_flag_off` |
| H.1.6 | Tracker | F-04 / F-14 / F-38 `resolved` → `partial (gated by BLOCKER_*)` | `tasks/phase9-pos/FINDINGS_POS_TRACKER.md` | 5 min | — |
| H.1.7 | POS-9.2.9 (pre-ship) | Script CI `scripts/check-invariants.sh` (6 greps POS_INVARIANTS §3) | nouveau fichier + mention `composer.json`/`package.json` ou `.github/workflows` | 30 min | Script sort en `exit 1` si hit |

**Gate H.1** : 
- `php artisan test --filter='HttpException|RolePermissionSeeder|DashboardAuditTrail|BranchIsolation|PosDineIn'` vert.
- `bash scripts/check-invariants.sh` → 0 hit.
- PHPUnit POS-9.1 full suite vert (41 tests).

**Durée** : ~2 h · 7 commits atomiques.

---

## Vague H.2 — Fiscal correctness (secrets, race, boundaries, format) — ~4 h ✅ DONE (10/10)

**Status** : All 10 items completed. 72 fiscal + audit tests green.
Gate H.2 verified: grep invariants clean, no regressions introduced on main suite (pre-existing Menu failures confirmed baseline).

**Conditions** : Vague H.1 mergée (sécurité base solide).

| # | ID | Titre | Fichiers | Effort | Test |
|---|---|---|---|---|---|
| H.2.1 | F-C1 / F-B2 | Dev-secret → refusé en prod + `.env.example` + defaults `''` | `config/fiscal.php`, `.env.example`, `app/Services/Fiscal/AuditLogService.php:152-159`, `ZReportService::sign()` | 30 min | `FiscalSecretProductionGuardTest::test_dev_default_rejected_in_production` |
| H.2.2 | F-C3 / F-B1 | `AuditLogService::write()` : `Cache::lock` + `DB::transaction` + migration `UNIQUE(branch_id, prev_hash)` | `app/Services/Fiscal/AuditLogService.php:43-74`, nouvelle migration `2026_04_19_100000_add_unique_chain_index_to_audit_logs.php` | 45 min | `AuditLogConcurrencyTest::test_parallel_writes_one_succeeds_other_retries_no_fork` |
| H.2.3 | F-C5 | `AuditLogService::write()` throw si `branch_id <= 0` | `app/Services/Fiscal/AuditLogService.php:43-74, 133-143` | 15 min | `AuditLogBranchRequiredTest::test_null_or_zero_branch_rejected` |
| H.2.4 | F-C4 | `ZReportService::aggregate()` + `XReportService::snapshot()` : `whereNotNull('fiscal_sequence_no')` | `app/Services/Fiscal/ZReportService.php:155-201`, `XReportService.php` | 15 min | `ZReportAggregateFilterTest::test_only_sequenced_orders_included` |
| H.2.5 | F-B5 | `ZReportService::aggregate()` : `withoutGlobalScope(BranchScope::class)` explicite (keep soft-delete scope) | `app/Services/Fiscal/ZReportService.php:155` | 10 min | `ZReportExcludesSoftDeletedTest` |
| H.2.6 | F-B3 | Z period : demi-intervalle `(prev.closed_at, now]` cohérent Z + X | `ZReportService.php` + `XReportService.php` | 30 min | `ZReportBoundaryTest::test_no_gap_no_double_count` |
| H.2.7 | F-B4 | Timezone : `->utc()->format(...)` dans `canonical` HMAC | `ZReportService::sign()` L247 | 15 min | `ZReportSignatureTimezoneStabilityTest` |
| H.2.8 | F-B6 | `total_by_tax_rate` populé via `PricingService::taxBreakdown` | `ZReportService::aggregate()` + extension `PricingService` | 45 min | `ZReportTaxBreakdownTest` |
| H.2.9 | F-B7 | `audit_logs::down()` throw si `APP_ENV=production` | migration `2026_04_22_000002_create_audit_logs_table.php` | 10 min | `AuditLogsRollbackGuardTest::test_down_blocked_in_production` |
| H.2.10 | F-B10 | `FiscalSequenceService::next()` : `lockForUpdate()` dans le `transaction` | `FiscalSequenceService.php:77-81` | 10 min | test existant ajustable |

**Gate H.2** :
- Tous tests H.2 verts.
- Régression POS-9.4 Fiscal verte (43 tests).
- Grep `whereNotNull.*fiscal_sequence_no` présent dans Z+X.
- Grep `Cache::lock.*audit_chain_b` présent dans `AuditLogService`.

**Durée** : ~4 h · 10 commits atomiques.

---

## Vague H.3 — Operational hardening (observabilité, robustesse, docs) — ~3 h

**Conditions** : H.2 mergée (base fiscale correcte).

| # | ID | Titre | Fichiers | Effort | Test |
|---|---|---|---|---|---|
| H.3.1 | F-C7 | Rate-limit dédié `throttle:10,1` sur `/admin/fiscal/z-report/{open,close}` | `routes/api.php:784-794` | 10 min | `FiscalRateLimitTest` |
| H.3.2 | F-C7 | Log channel `fiscal` + breadcrumbs Sentry sur write/open/close | `config/logging.php`, `AuditLogService::write()`, `ZReportService::{open,close}` | 30 min | `FiscalObservabilityTest::test_channels_used` |
| H.3.3 | F-C8 | `FiscalArchiveCommand` : `->lazy(...)` + stream ZipArchive | `app/Console/Commands/FiscalArchiveCommand.php` | 45 min | `FiscalArchiveMemoryBoundedTest` (benchmark 10k orders) |
| H.3.4 | F-A6 | POS-9.1.11 : filter source + self-exclusion + `AudioContext.resume()` | `resources/js/components/admin/pos/PosComponent.vue:1033-1134` | 30 min | Vitest `posNewOrderNotify.spec.js` — ajout 3 cas |
| H.3.5 | F-A7 | `OrderService::destroy` : soft-delete children OR disable restore | `app/Services/OrderService.php:1622-1626` | 20 min | `PosOrderRestoreIntegrityTest` |
| H.3.6 | F-A8 | Migration composite index `(branch_id, created_at)` sur `action_logs` | nouvelle migration | 10 min | — |
| H.3.7 | F-C14 / F-A9 | i18n FR/EN sur nouvelles clés (`label.base_ht`, `message.new_pos_order*`, fiscal strings) | `lang/fr.json`, `lang/en.json` | 20 min | Vitest existant re-joué |
| H.3.8 | F-A15 / F-A16 | AudioContext resume + drop `console.warn` `posCart.js:40` | `PosComponent.vue`, `posCart.js` | 10 min | — |
| H.3.9 | Docs | `docs/FISCAL_SECRETS.md` (rotation, env vars, runbook) + `docs/AUTHZ_MATRIX.md` update (3 nouvelles perms) | nouveaux fichiers | 30 min | — |

**Gate H.3** :
- Build prod `npm run production` < 27 s.
- Vitest full 51 files / 400+ tests verts.
- PHPUnit full suite vert (sauf 7 Pusher pré-existants).

**Durée** : ~3 h · 9 commits atomiques.

---

## Vague H.4 — Scope drift tickets (non-bloquant — à planifier) — *à reporter*

Non exécuté ici. Créer 2 tickets pour :
- **D-1 POS-9.4.H.PDF** : implémenter PDF réel (mpdf/dompdf) + signature embarquée.
- **D-2 POS-9.4.H.CRYPT** : chiffrement GPG/openssl de l'archive + HMAC sur le zip.

Ces items peuvent être absorbés dans POS-9.8 (UX ticket) + POS-9.10 (outbox/observabilité).

---

## Gate final Phase H

| # | Critère | Attendu |
|---|---|---|
| 1 | Vagues H.1 + H.2 + H.3 mergées | 26 commits atomiques |
| 2 | Tous tests H verts | nouveaux tests + régression |
| 3 | Greps invariants (CI) | 0 hit |
| 4 | Tracker findings | F-04/F-14/F-38 → partial ; nouvelles lignes H.1..H.3 resolved |
| 5 | `CROSS_TRACK_STATUS.md` | Vague H enregistrée |
| 6 | Verifier indépendant | 100% RESOLVED |
| 7 | Rapport `RUN_POS_9_H_2026-04-18.md` + `VERIFY_POS_9_H_2026-04-18.md` | livré |
| 8 | Merge sur main prêt | branche pushée, PR ouverte |

---

## Séquencement

```
POS-9.4 (feat/pos-phase-9-4)
    │
    ▼
Vague H.1 (2 h, P0 zero-risk)     ← branche feat/pos-phase-9-hardening
    │
    ▼
Vague H.2 (4 h, fiscal correctness)
    │
    ▼
Vague H.3 (3 h, operational)
    │
    ▼
Gate H final ────→ merge main
    │
    ▼
Attente Kiosk P9.5
    │
    ▼
POS-9.2 + unfreeze BLOCKERs 9.4.2b/5/10 (mini-vague POS-9.4.bis)
```

**Durée totale H** : ~9 h · 1,5 jour ouvré.

---

## Principe directeur

**NE PAS démarrer POS-9.2 tant que H.1 + H.2 ne sont pas mergées.**

Raison : POS-9.2 refactore `OrderService::changeStatus` / `changePaymentStatus` / `tokenCreate` qui sont précisément les méthodes avec F-A1 (propagation 403 cassée). Si H.1.1 n'est pas land avant, POS-9.2 va dupliquer la dette dans chaque refacto state-machine et le CI grep (POS-9.2.9) n'est pas encore en place pour l'attraper.

---

*Auteur : assistant session (Track B / orchestration post-audit).*
*Date : 2026-04-18.*
