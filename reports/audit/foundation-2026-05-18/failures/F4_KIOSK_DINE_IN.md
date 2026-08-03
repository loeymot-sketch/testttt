# F4 — KioskDineInDisabledV1SentinelTest — Root-Cause Investigation

**Branch :** heal/cms-pr1-quickwins-2026-05-18
**HEAD :** 2949e92ed
**Test ID :** WAVE5-KIOSK-001
**Investigated :** 2026-05-18
**Mode :** read-only

---

## TL;DR

The failing assertion is a **stale-test bug**, not a backend regression. The
backend behaviour is correct (HTTP 422 + `errors.order_type` populated). The
test was written when the error message was in English; commit `12b1017cf`
(BORNE-001 P2 heal) translated the message to French per ADR-007 FR-locked
kiosk path, but did **not** update the matching `assertStringContainsString`
in the sentinel. Result: 3 of 4 cases pass, the EN-substring case fails.

**Verdict :** simple test-only fix (one-line substring swap).
**Confidence :** very high (direct git-blame proof + test output proof).
**No backend change required.**

---

## 1. Exact assertion that failed

File `tests/Feature/Sentinels/KioskDineInDisabledV1SentinelTest.php:142`

```php
$resp->assertStatus(422);                                                  // PASS
$resp->assertJsonValidationErrors(['order_type']);                          // PASS
$this->assertStringContainsString(
    'disabled in V1',
    $resp->json('errors.order_type.0') ?? ''
);                                                                          // FAIL
```

PHPUnit output (verbatim) :

> Failed asserting that 'Le service sur place est désactivé en V1 — les
> commandes borne doivent être à emporter.' contains "disabled in V1".

Actual JSON response body :

```json
{
  "message": "Le service sur place est désactivé en V1 — les commandes borne doivent être à emporter.",
  "errors": {
    "order_type": [
      "Le service sur place est désactivé en V1 — les commandes borne doivent être à emporter."
    ]
  }
}
```

Expected (per test) : substring `"disabled in V1"` (English).
Actual : `"désactivé en V1"` (French equivalent).

The first two assertions (status 422 + presence of `order_type` error key)
**both pass** — meaning the dine-in guard is firing correctly and the backend
contract `WAVE5-KIOSK-001` is intact. The third assertion is testing the
**human-readable copy** of the error, which is the only thing that drifted.

Final PHPUnit summary :
```
Tests:  1 failed, 3 passed
```

---

## 2. Code path traced

Frontend request flow when a kiosk token POSTs `order_type=KIOSK(25)` while
`pos_dine_in_enabled=false` :

1. Route `POST /api/frontend/order` → `Frontend\OrderController` (mount point).
2. FormRequest binding triggers `app/Http/Requests/OrderRequest.php`.
3. `authorize()` (line 35) returns `true` because the test seeds Sanctum with
   `['kiosk:order']` ability — `tokenCan('kiosk:order')` is true.
4. `rules()` (line 135) passes — payload is well-formed.
5. `withValidator(after closure)` (line 186) fires :
   - line 195 : `$isKioskToken = $this->isKioskOrderToken()` → `true`.
   - lines 196-206 : kiosk machine lookup succeeds (seeded in `setUp`).
   - **lines 218-229** (the WAVE5-KIOSK-001 guard) :
     ```php
     if ($isKioskToken
         && ! (bool) Settings::group('pos')->get('pos_dine_in_enabled', false)
         && in_array($orderTypeInt, [OrderType::KIOSK, OrderType::DINING_TABLE], true)) {
         $validator->errors()->add(
             'order_type',
             'Le service sur place est désactivé en V1 — les commandes borne doivent être à emporter.'
         );
         return;
     }
     ```
   - Setting `pos_dine_in_enabled=0` was applied in `setDineInEnabled(false)` via
     `Settings::group('pos')->set('pos_dine_in_enabled', 0)` with auto cache
     invalidation. Hit ⇒ error added ⇒ early return.
6. Laravel converts validator errors → HTTP 422 + JSON body shown above.

The backend gate functions exactly as documented in
`reports/execution/audit_2026-05-07/FINAL_REPORT_v3_WAVE5_CONSOLIDATED_2026-05-08.md §1.3`
and CLAUDE.md §9 multi-tenant invariants. **No backend regression.**

---

## 3. Breaking commit identification

Two commits frame the bug :

**Commit `9dc009ec9` — 2026-05-08** — `audit(WAVE5-KIOSK-001): kiosk dine-in V1
backend enforcement + sentinel`. Introduced both the backend guard (English
copy) and the sentinel test (assertion expecting the English substring
`"disabled in V1"`). At this point assertion and code agreed.

**Commit `12b1017cf` — 2026-05-18 10:32 — THE DRIFT POINT**
`fix(borne-e2e-pageby-v1-0-2): heal BORNE-001 P2 — translate dine-in error to FR`.

```
app/Http/Requests/OrderRequest.php | 5 ++++-
1 file changed, 4 insertions(+), 1 deletion(-)
```

Single-file diff swapping the EN string to FR :

```diff
-'Dine-in is disabled in V1 — kiosk orders must use TAKEAWAY (à emporter).'
+'Le service sur place est désactivé en V1 — les commandes borne doivent être à emporter.'
```

The commit message claims `Scope-minimal : single string change. Backend
validation logic unchanged.` That is accurate — but the heal omitted the
companion assertion update in the sentinel. The sentinel substring
`'disabled in V1'` was correctly pinned to the EN copy and was silently
invalidated.

The drift is also the cause of three E2E spec **comments** that still quote
the old EN message (non-executing, but stale documentation) :

- `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-E.spec.js:126`
- `tests/e2e/test-e2e-pos-kds-sync-2026-05-10-wave-F.spec.js:81`
- `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-B.spec.js:66`

None of those execute the assertion (they're explanatory PHP comments inside
`.js`-style line comments) — but they confirm the prior EN copy was the
documented contract.

No other commit between `9dc009ec9` and HEAD touches the sentinel test or the
guard logic. The chain is unambiguous.

---

## 4. Recommended fix

**Class :** simple-fix (test-only, scope-minimal, no backend touch, no LOCK).

### Patch (1 line) — `tests/Feature/Sentinels/KioskDineInDisabledV1SentinelTest.php:142`

```diff
-        $this->assertStringContainsString('disabled in V1', $resp->json('errors.order_type.0') ?? '');
+        $this->assertStringContainsString('désactivé en V1', $resp->json('errors.order_type.0') ?? '');
```

Rationale :
- Mirrors the FR translation merged in `12b1017cf` (the canonical kiosk-path
  copy per ADR-007 FR-locked surface).
- Keeps the assertion **strong** — a future regression that erases or replaces
  the V1-specific copy would still fail the test.
- Zero behavioral change. The 422 + `order_type` key assertions (the real
  contract: dine-in is refused on kiosk path when the flag is off) remain
  unchanged.

### Alternative considered — extract to lang key

A more robust path would be `__('kiosk.dine_in_disabled_v1')` referenced from
both the backend and the test. Out of scope here (touches translation files
and FormRequest copy semantics — would push past the read-only/scope-mini
boundary). Track as deferred polish under V1.0.2 i18n consolidation if the
owner agrees.

### Defense-in-depth note

The companion test cases (`rejects_order_type_dining_table`,
`allows_takeaway`, `dine_in_enabled_kiosk_allows`) all pass on HEAD — they
verify the backend gate's **behavior** (status code + error key + DB state)
rather than the message string. The 3-passing/1-failing pattern is itself
evidence that the fix scope is the assertion-3 substring only.

### Optional follow-up (separate scope-mini PR)

Update the 3 stale EN comments in the wave-E / wave-F / rush-hour-50x50 specs
to FR for documentation hygiene. Non-functional, can ride with the next
i18n-cleanup batch.

---

## 5. Confidence level

**Very high.** Evidence chain :

1. Live PHPUnit run reproduces the failure with exact message strings — both
   sides of the substring mismatch are visible in the test output.
2. `git log -p` on `OrderRequest.php` cleanly identifies commit `12b1017cf` as
   the drift point ; its `--stat` confirms a 1-file change, and its diff shows
   the exact EN→FR swap of the targeted string.
3. `git log` on the sentinel test shows no commit between `9dc009ec9` (test
   creation) and HEAD — confirming the sentinel was never updated to follow
   the translation.
4. The other 3 cases of the same sentinel pass on HEAD ⇒ the backend gate
   functions correctly ; only the copy assertion drifted.
5. No other repo location asserts the EN substring (only stale Playwright
   spec comments, which don't execute).

No owner decision required. No frozen-zone file involved (`OrderRequest.php`
is not in the frozen list ; the test file is plain feature-test). NF525 chain
unaffected.

**Recommendation :** include the 1-line test fix in the next scope-mini test-
debt PR (or piggyback on the cms-pr1-quickwins-2026-05-18 branch since it's a
hardening branch). Expected wall-clock to land : < 5 min including
`php artisan test --filter KioskDineInDisabled` verification (target output
`4 passed`).
