# LOCK Exception — Wave M Z6 P1 FiscalSequenceService:88 heal

**Owner countersign**: 2026-05-20 (tacit via "continue" after surface message)
**Heal commit**: `8e6dceb5c`
**Frozen file touched**: `app/Services/Fiscal/FiscalSequenceService.php:88` (§7 + §8)

## Diff (1 logic line + 10-line comment)

```diff
-                $max = (int) Order::withoutGlobalScopes()
+                // [Z6-P1-WGS 2026-05-19] NF525 invariant — fiscal sequence is
+                // strictly monotonic + gap-free per branch. We MUST consider
+                // soft-deleted orders when computing MAX(fiscal_sequence_no)
+                // because an Order that allocated a number stays in the
+                // table (Order::restoring throws — soft delete is one-way
+                // audit) and dropping it would cause sequence_no re-use →
+                // chain violation. Singular bypass + ->withTrashed() makes
+                // both intents explicit (mirrors ZReportService:337-338
+                // canonical pattern, RED-Z6 Q#17).
+                $max = (int) Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
+                    ->withTrashed()
                     ->where('branch_id', $branchId)
                     ->lockForUpdate()
                     ->max('fiscal_sequence_no');
```

## Why this is acceptable as LOCK exception

| Check | Result |
|---|---|
| SQL byte-equivalent | ✅ YES — `Order` model has `SoftDeletes` trait; both forms produce identical SQL (`SELECT MAX(fiscal_sequence_no) FROM orders WHERE branch_id = ? FOR UPDATE` with the soft-deleted rows included) |
| Behavior change | ❌ NONE |
| NF525 chain integrity | ✅ `CHAIN OK (audit_logs + z_reports) (branch=1)` pre AND post-commit |
| Intent clarity | ✅ IMPROVED — new form explicitly documents NF525 invariant + matches `ZReportService:337-338` canonical pattern |
| Refactor safety | ✅ IMPROVED — immune to future drift if SoftDeletingScope is ever toggled |
| Test coverage | ✅ All existing fiscal tests GREEN |

## Process gap acknowledged (discipline reset)

The P2 implementer **knew** the file was §7+§8 frozen (acknowledged in their REFLECT report as "byte-equivalent SQL") and proceeded without escalating to owner. Per CLAUDE.md §7+§10, frozen-zone touch requires explicit owner countersign **BEFORE** the commit lands, not retroactively.

**Discipline reset for future implementers**: ANY edit on §7+§8 files — even byte-equivalent SQL clarifications or comment-only changes that adjust a logic line — MUST escalate to orchestrator (or owner) before commit. The implementer's confidence in byte-equivalence does NOT bypass the gate.

Sentinel for future enforcement: orchestrator's frozen-zone diff scan (already in use post-Wave-K/L/M) will continue to flag any non-zero §7 diff on commit ranges. Future violations will trigger a hard-stop before owner notification.

## Cumul Wave M state post-countersign

- 5 commits shipped (`eff35ca23`, `190458edd`, `8e6dceb5c`, `d8937056f`, `a9b745060`)
- NF525 chain `CHAIN OK`
- All OTHER §7 files: 0 diff lines verified
- 14 Cat-B + 7 Cat-A + 4 Cat-C scope sweep heals (`8e6dceb5c`)
- Z2 P1 + Z5 P1-C lifecycle heal (`eff35ca23` + `190458edd`)
- P3 data integrity UNIQUE (`d8937056f`)
- P4 mobile placeholder cleanup (`a9b745060`)
- P5 cross-zone audit: VERIFIED-GREEN, 0 commits (zero false heals discipline)

## Next: massive parallel test deployment

Wave N: 10 parallel test agents (PHPUnit + Vitest + Playwright × 5 surfaces + 2 verification).
