# Dead-Files Audit — Confident Verdict
**Date**: 2026-05-18
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**Mode**: READ-ONLY exhaustive cross-check (12 checks per file)

---

## File: `app/Http/Controllers/Frontend/CheckoutController.php`
**Verdict**: SAFE-TO-DELETE
**Confidence**: HIGH

### Evidence
- Check 1 (Read summary): 30-line controller with a single `branch(PaginateRequest)` action that delegates to `BranchService::list` and wraps the result in `BranchResource::collection`. Pure passthrough of branches — duplicate of `Frontend\BranchController::index`.
- Check 2 (direct grep `CheckoutController` in app/resources/tests/public/routes/config/bootstrap/database/docs): **1 match** = its own class declaration. Zero external references.
- Check 3 (`new CheckoutController` + `@CheckoutController` + `::CheckoutController`): **0 matches** anywhere.
- Check 4 (`app(CheckoutController::class)` + namespaced FQCN `App\Http\Controllers\Frontend\CheckoutController`): **0 matches**.
- Check 5 (route binding in `routes/web.php` / `routes/api.php`): **0 matches** (also `grep "checkout"` in routes: 0).
- Check 6 (Kernel): N/A for controllers, but `app/Http/Kernel.php` does not mention it.
- Check 7-8 (DI inject `$checkoutController` etc.): 0 matches.
- Check 9 (bootstrap/cache/services.php): not present in workspace; no risk.
- Check 10 (config/app.php providers): no provider explicitly binds it.
- Check 11 (routes/channels.php): no broadcast binding.
- Check 12 (git log -3): `209bbc515 testt Kossay20 2 months ago` — single legacy commit, no recent maintenance.

### Recommendation
**Delete the file.** Compared to its sibling `Frontend/BranchController` which appears 9+ times in `routes/api.php` (`/branches` GET + `/branches/show/...`), `CheckoutController` is wired nowhere. The single `branch()` action is functionally redundant with `BranchController::index` (both call `BranchService->list` and return `BranchResource::collection`).

User-friendly framing: *Inherited demo scaffolding from the upstream Bangladesh template. We already have BranchController doing the exact same thing. Safe to remove.*

### Risk if deleted
None. No route → endpoint cannot be hit. No DI consumer → no constructor resolution failure. No tests → no PHPUnit breakage. Branch listing keeps working via `Frontend/BranchController`.

---

## File: `app/Services/Receipt/ReceiptDataService.php`
**Verdict**: KEEP-WITH-UNCERTAINTY
**Confidence**: MEDIUM

### Evidence
- Check 1 (Read summary): 30-line final class with `buildForOrder(int $orderId): array`. Pure read — assembles `fiscal_sequence_no`, `pos_register_id`, `siret`, `vat_intra`, `legal_footer`, etc. Documented in `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` line 174 as *"pure read, aucune mutation"*.
- Check 2 (direct grep): **1 PHP self-match** + **5 doc/plan matches** (`GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md`, `POS_AUDIT_MASTER_PLAN_2026-05-06.md`, `POS_AUDIT_FINAL_REPORT_2026-05-06.md`).
- Check 3 (`new ReceiptDataService` / `::class`): **0 PHP matches**.
- Check 4 (`app(ReceiptDataService::class)` + FQCN `App\Services\Receipt\ReceiptDataService`): **0 matches** outside the file itself.
- Check 5 (controllers binding): not a controller.
- Check 6 (Kernel): N/A.
- Check 7 (`buildForOrder` calls): **0 PHP matches** (only the method declaration). JS-side `posReceiptBuilder.js` builds the receipt entirely client-side — does **not** call this service.
- Check 8 (DI inject `$receiptDataService`): 0 matches.
- Check 9 (services.php DI cache): not present, no risk.
- Check 10 (config providers): no explicit binding.
- Check 11 (channels.php): N/A.
- Check 12 (git log -3): `90a66f4a4 up Kossay20 4 weeks ago` — single commit ~1 month ago. **Not an old orphan** — it's recent infrastructure that was scaffolded as part of POS audit closure (POS_AUDIT_MASTER_PLAN row 23: *"Génération ticket caisse → ReceiptDataService::buildForOrder"*).

### Recommendation
**Keep, but flag as un-wired.** This is a deliberately-architected NF525-aware service that maps cleanly to the POS audit plan (row 23) but the JS receipt path bypasses it and builds receipts directly via `posReceiptBuilder.js` from `order` props. Two paths exist for closure:

1. **Wire it** — add a `Route::get('/orders/{order}/receipt-data', [...])` returning `ReceiptDataService::buildForOrder($orderId)` and refactor `posReceiptBuilder.js` + `PosOrderReceiptComponent.vue` to consume backend-truth fields (`fiscal_sequence_no`, `register_id`, `siret`). This is the **NF525-correct** path per audit plan.
2. **Delete + remove plan rows** — if owner confirms JS-only receipt is acceptable for V1, delete service AND update `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` line 174 + `docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md` rows 51, 125, 174 + `docs/gates/GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md` lines 89, 133.

User-friendly framing: *Lives in limbo — designed but never wired. NF525 audit plan says it should serve `fiscal_sequence_no` to the receipt UI. Today JS builds the ticket from already-loaded order data and may show stale or imprecise fiscal-chain fields. Not urgent for V1 Le Cayenne, but should not be silently deleted without owner-gate on fiscal correctness.*

### Risk if deleted
Low immediate risk (no consumer). Medium audit risk — POS_AUDIT_FINAL_REPORT contractually states it exists. Deletion without doc-sync creates audit-trail drift.

---

## File: `app/Http/Middleware/SetLocale.php`
**Verdict**: SAFE-TO-DELETE
**Confidence**: HIGH

### Evidence
- Check 1 (Read summary): 99-line middleware tagged `[PHASE-37]` that detects locale from `?locale=xx` query → Accept-Language → defaults to `'fr'`. Calls `App::setLocale()`. Supported = `['fr','en','ar']`.
- Check 2 (direct grep): **2 self-matches** (class declaration + log line). Zero external references.
- Check 3 (`new SetLocale` / `SetLocale::class`): 0 matches.
- Check 4 (FQCN `App\Http\Middleware\SetLocale`): 0 matches outside the file.
- Check 5 (route binding): N/A.
- Check 6 (`app/Http/Kernel.php`): **NOT registered** in `$middleware`, `$middlewareGroups['web']`, `$middlewareGroups['api']`, `$middlewarePriority`, or `$routeMiddleware`. The `localization` alias on line 122 points to a **different class**: `\App\Http\Middleware\localization::class` (lowercase) which is the ACTIVE locale middleware also tagged `[PHASE-37]`.
- Check 7 (commands): N/A.
- Check 8 (DI inject): 0 matches.
- Check 9 (services.php): not present.
- Check 10 (config/app.php): no explicit listing.
- Check 11 (channels.php): N/A.
- Check 12 (git log -3): `edc8680e5 up Kossay20 7 weeks ago` — single commit, never touched again.

### Recommendation
**Delete.** This is a **duplicate** of `app/Http/Middleware/localization.php` (same `[PHASE-37]` tag, same goal). `localization.php` is the ACTIVE one — registered in Kernel as `'localization'` alias. `SetLocale.php` was an earlier draft never wired.

User-friendly framing: *Twin file from the PHASE-37 locale work. The lowercase `localization.php` won and is in the Kernel. `SetLocale.php` was orphaned. Safe to delete.*

### Risk if deleted
None. Middleware is not in Kernel → not invoked. The active `localization` middleware continues handling Accept-Language. Frontend `axios-setup.js` PHASE-37 header logic is unaffected.

---

## File: `app/Console/Commands/FixIdentityCommand.php`
**Verdict**: SAFE-TO-DELETE
**Confidence**: HIGH

### Evidence
- Check 1 (Read summary): 92-line one-shot artisan command `php artisan settings:fix-identity` that converts Bangladesh demo data (company name, phone +880, Dhaka addresses) to Le Cayenne / Paris / +33. Has `--dry-run`. Pure data migration, idempotent guard via `where('country_code', '+880')`.
- Check 2 (direct grep `FixIdentityCommand` + `settings:fix-identity` + `fix-identity`): **2 self-matches** + **0 external code references**.
- Check 3 (`new FixIdentityCommand`): 0 matches.
- Check 4 (FQCN `App\Console\Commands\FixIdentityCommand`): 0 matches outside the file.
- Check 5 (route binding): N/A.
- Check 6 (Kernel `$commands` registration): `Console/Kernel.php:256 → $this->load(__DIR__.'/Commands')` auto-discovers it, but `schedule()` does **not** schedule it.
- Check 7 (scripts/deploy/Console/Kernel.php schedule): **0 matches** in `scripts/`, `deploy/`, `Makefile`, `composer.json`, or `Console/Kernel.php`. Never invoked automatically.
- Check 8 (DI inject): N/A.
- Check 9 (services.php): not present.
- Check 10 (config/app.php providers): N/A.
- Check 11 (channels.php): N/A.
- Check 12 (git log -3): `827c3512e up Kossay20 8 weeks ago`, `34dc7e705 mvp Kossay20 8 weeks ago` — both ~2 months ago. Aligns with the `recovery_2026-05-09` memory: *"root cause 'tout est cassé' = DB state"*. This command was the one-shot fix for that DB-identity incident. Job done. Demo-to-prod data flip is now committed in seeders / DB and the company is live as Le Cayenne.
- Bonus (`reports/audit/foundation-2026-05-18/round-1/F-X-DUPS/STATUS.md:26`): F-X hunter already classified it as *"one-shot recovery command from the May 9 DB-identity incident. Not scheduled."*

### Recommendation
**Delete.** One-shot recovery artefact. Idempotent guards mean re-running on a healed DB is harmless but pointless. Keeping it pollutes the artisan command surface (`php artisan list`) and tempts future operators to re-run a Bangladesh-specific migration. The required reset behavior, if ever needed again, lives in seeders and a fresh `php artisan db:seed`.

User-friendly framing: *The "Bangladesh → Paris" data migration we ran once on May 9. It already worked; the DB is in the right state; the command is now noise. Safe to remove.*

### Risk if deleted
None. Not scheduled, not invoked from any deploy script, not chained from another command. Existing DB rows (company = Le Cayenne, country_code = +33, addresses = Paris) are persisted and unaffected.

---

## Summary Table

| # | File | Verdict | Confidence |
|---|------|---------|------------|
| 1 | `app/Http/Controllers/Frontend/CheckoutController.php` | SAFE-TO-DELETE | HIGH |
| 2 | `app/Services/Receipt/ReceiptDataService.php` | KEEP-WITH-UNCERTAINTY | MEDIUM |
| 3 | `app/Http/Middleware/SetLocale.php` | SAFE-TO-DELETE | HIGH |
| 4 | `app/Console/Commands/FixIdentityCommand.php` | SAFE-TO-DELETE | HIGH |

---

## Owner-Friendly Verdict

**I confidently recommend deleting 3 files: `CheckoutController.php`, `SetLocale.php`, `FixIdentityCommand.php`.** Each is unreferenced anywhere, not in any Kernel/Router/Schedule/Provider, and corresponds to inherited demo scaffolding (CheckoutController), a duplicate draft (SetLocale), or a completed one-shot recovery (FixIdentity). Combined deletion: 221 lines of dead PHP.

**I recommend KEEPING `ReceiptDataService.php` pending an owner decision.** Reason: the file is technically un-wired but is **explicitly contracted in the POS audit plan** (`POS_AUDIT_MASTER_PLAN_2026-05-06.md` row 23 + `POS_AUDIT_FINAL_REPORT_2026-05-06.md` line 174 + `GATE_P_MEGA_19_BRANCH_THEMING_2026-04-20.md`). NF525 fiscal-correctness wants `fiscal_sequence_no` + `siret` to flow from backend SSOT into the printed ticket. Today, JS builds the receipt from order-prop data which is fine for V1 Le Cayenne but leaves a fiscal-audit gap. Delete only after explicit owner gate on *"V1 receipt does not need backend-asserted fiscal fields"* + sync of the 3 audit docs.

---

## Proposed Action (3 SAFE-TO-DELETE = batch deletion)

Since 3 of 4 are SAFE-TO-DELETE with HIGH confidence, **propose batch deletion in a single commit** on `heal/cms-pr1-quickwins-2026-05-18`:

```
chore(cleanup): remove 3 dead files validated by F-X hunter + ULTRA-DEEP audit

- app/Http/Controllers/Frontend/CheckoutController.php
  (zero routes, zero refs; duplicate of Frontend/BranchController)
- app/Http/Middleware/SetLocale.php
  (not in Kernel; duplicate of localization.php which is ACTIVE)
- app/Console/Commands/FixIdentityCommand.php
  (one-shot 2026-05-09 DB-identity recovery; job done)

Total: 221 lines removed. ReceiptDataService kept pending owner-gate
on NF525 receipt-backend-truth path (audit plan row 23).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

Recommended verification post-delete:
- `php artisan list` — confirm `settings:fix-identity` disappears, no other command breaks
- `php artisan route:list` — confirm no orphan route
- `php artisan config:cache && php artisan route:cache` — both succeed
- `vendor/bin/phpunit --filter='Locale|Receipt|Branch'` — no regression
- Smoke: `/api/v1/branches` returns 200 with branch list (BranchController still works)
- Visual: `/admin/pos` order receipt prints intact (posReceiptBuilder.js unchanged)
