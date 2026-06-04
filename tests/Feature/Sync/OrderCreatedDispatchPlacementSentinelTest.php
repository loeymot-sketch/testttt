<?php

namespace Tests\Feature\Sync;

use Tests\TestCase;

/**
 * [Wave M / Heal Z2 P1] OrderCreated dispatch placement sentinel.
 *
 * Locks the invariant: every `OrderCreated::dispatch(...)` call site must
 * appear INSIDE the surrounding `DB::transaction(function () { ... });`
 * closure — so the {@see \App\Events\Concerns\DispatchableAfterCommit}
 * trait actually engages and defers the dispatch until commit.
 *
 * Background: HEAL-PLAN-C analysis (`reports/audit/v1-sync-deep-audit-2026-05-19/
 * HEAL-PLAN-C-order-lifecycle.md`) and `RED-Z2-order-lifecycle.md` §B P1
 * documented that the 5 call sites in OrderService + FrontendOrderService
 * historically fired OUTSIDE the closure. At `transactionLevel()===0` the
 * trait at `DispatchableAfterCommit.php:33` falls through to immediate
 * dispatch — the "deferred on commit, dropped on rollback" guard
 * advertised in `OrderCreated.php:14-17` was dead code on the hot path.
 *
 * Wave M moves the OrderCreated::dispatch line INSIDE each closure so:
 *   - transactionLevel()>0 → afterCommit() registration → fires after
 *     outermost commit (deterministic post-commit timing).
 *   - rollback → afterCommit callback dropped → broadcast never fires →
 *     KDS/OSS never observe a ghost order.
 *
 * This sentinel uses source inspection (regex over file contents). The
 * mechanism is fragile to whitespace but exactly catches the invariant we
 * care about: ordering of two specific textual markers (dispatch call
 * versus closing of the transaction closure).
 */
class OrderCreatedDispatchPlacementSentinelTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     *
     * tuple: [relative service file path under app/, expected number of
     * OrderCreated::dispatch call sites, expected number of those that
     * fall inside a DB::transaction(function () { ... }) closure]
     *
     * NOTE: relative paths only — the Laravel application is not booted
     * during data provider evaluation, so app_path() is unavailable.
     */
    public static function dispatchSitesProvider(): array
    {
        return [
            'OrderService' => [
                'Services/OrderService.php',
                3,
                3,
            ],
            'FrontendOrderService' => [
                'Services/FrontendOrderService.php',
                // Two explicit OrderCreated::dispatch inside two distinct
                // closures (myOrderStore + finalizePaidKioskOrder); the
                // legacy helper `dispatchNewOrderSignals` no longer
                // dispatches OrderCreated post Wave M.
                2,
                2,
            ],
        ];
    }

    private function resolvePath(string $relative): string
    {
        // base_path() needs the app booted; data providers run pre-boot.
        // The test class file lives at tests/Feature/Sync/<file>.php so
        // we can navigate up three levels deterministically.
        return dirname(__DIR__, 3) . '/app/' . $relative;
    }

    /**
     * @dataProvider dispatchSitesProvider
     */
    public function test_order_created_dispatch_sites_count_matches_expectation(
        string $relativePath,
        int $expectedDispatchCount,
        int $expectedClosureWraps
    ): void {
        $filePath = $this->resolvePath($relativePath);
        $source = file_get_contents($filePath);
        $this->assertNotFalse($source, 'unable to read ' . $filePath);

        // Count `OrderCreated::dispatch(` (optionally fully-qualified).
        preg_match_all('/(?:\\\\App\\\\Events\\\\)?OrderCreated::dispatch\\(/', $source, $matches);
        $count = count($matches[0]);

        $this->assertSame(
            $expectedDispatchCount,
            $count,
            sprintf(
                '%s: expected %d OrderCreated::dispatch sites, found %d. '
                . 'Wave M moved dispatches inside the closure; adjust this '
                . 'sentinel if a new legitimate call site is added.',
                basename($filePath),
                $expectedDispatchCount,
                $count
            )
        );
    }

    /**
     * Asserts that EVERY `OrderCreated::dispatch(` call appears BEFORE the
     * matching closing `});` of an enclosing `DB::transaction(function`
     * closure — i.e. inside the closure body.
     *
     * Heuristic: walk forward from each dispatch offset and find the
     * nearest preceding `DB::transaction(function` opening. Then verify
     * that no `});` appears strictly between that opening and the
     * dispatch offset at the closure's depth. We implement this by
     * tracking brace depth from the `function` opening parenthesis.
     *
     * @dataProvider dispatchSitesProvider
     */
    public function test_order_created_dispatch_lines_are_inside_db_transaction_closure(
        string $relativePath,
        int $expectedDispatchCount,
        int $expectedClosureWraps
    ): void {
        $filePath = $this->resolvePath($relativePath);
        $source = file_get_contents($filePath);
        $this->assertNotFalse($source);

        // Find all dispatch call offsets.
        preg_match_all(
            '/(?:\\\\App\\\\Events\\\\)?OrderCreated::dispatch\\(/',
            $source,
            $dispatchMatches,
            PREG_OFFSET_CAPTURE
        );

        $insideClosureCount = 0;
        foreach ($dispatchMatches[0] as $match) {
            [$matchText, $dispatchOffset] = $match;
            $this->assertTrue(
                $this->isOffsetInsideDbTransactionClosure($source, $dispatchOffset),
                sprintf(
                    '%s: OrderCreated::dispatch at offset %d (line %d) is NOT inside '
                    . 'a DB::transaction closure. Wave M P1 requires dispatch INSIDE '
                    . 'the closure so DispatchableAfterCommit engages and defers via '
                    . 'afterCommit() — see HEAL-PLAN-C §C.2 advisor pivot 2026-05-19.',
                    basename($filePath),
                    $dispatchOffset,
                    substr_count(substr($source, 0, $dispatchOffset), "\n") + 1
                )
            );
            $insideClosureCount++;
        }

        $this->assertSame(
            $expectedClosureWraps,
            $insideClosureCount,
            sprintf(
                '%s: expected %d in-closure dispatches, observed %d.',
                basename($filePath),
                $expectedClosureWraps,
                $insideClosureCount
            )
        );
    }

    /**
     * Brace-depth analysis backed by PHP's native tokenizer
     * (`token_get_all`) so that braces inside strings, comments,
     * heredoc/nowdoc, and PHP variable interpolation are correctly
     * IGNORED. Naive char-by-char counting was fragile against
     * comments containing `{` (e.g. `// allow: ... { defensive ... )`
     * Wave M encountered this concretely on OrderService line 1088).
     *
     * Algorithm:
     *  1. Tokenize the entire source.
     *  2. Locate every `DB::transaction(function` opening + the
     *     matching closing `}` via brace-balanced token walk. Brace
     *     punctuation tokens (`{` / `}`) from the tokenizer never
     *     include those embedded in strings/comments, so this is
     *     accurate without manual escaping.
     *  3. Record each closure's (open byte offset, close byte offset)
     *     pair.
     *  4. Return true if `$targetOffset` falls strictly between
     *     any (open, close) pair.
     */
    private function isOffsetInsideDbTransactionClosure(string $source, int $targetOffset): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        // Pre-compute byte offsets per token (cumulative length walk).
        $offsets = [];
        $cursor = 0;
        foreach ($tokens as $idx => $tk) {
            $offsets[$idx] = $cursor;
            $cursor += strlen(is_array($tk) ? $tk[1] : $tk);
        }

        $closures = [];

        for ($i = 0; $i < $count - 4; $i++) {
            $t0 = $tokens[$i];
            if (!is_array($t0) || $t0[0] !== T_STRING || $t0[1] !== 'DB') continue;
            $t1 = $tokens[$i + 1] ?? null;
            if (!is_array($t1) || $t1[0] !== T_DOUBLE_COLON) continue;
            $t2 = $tokens[$i + 2] ?? null;
            if (!is_array($t2) || $t2[0] !== T_STRING || $t2[1] !== 'transaction') continue;

            // Walk forward to `(` then `function` then `{`.
            $j = $i + 3;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!isset($tokens[$j]) || $tokens[$j] !== '(') continue;
            $j++;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_FUNCTION) continue;

            // Advance past `function (...)` signature (skip `use (...)`
            // and return type) to the FIRST `{` token.
            $parenDepth = 0;
            while ($j < $count) {
                $tk = $tokens[$j];
                if ($parenDepth === 0 && $tk === '{') break;
                if ($tk === '(') $parenDepth++;
                elseif ($tk === ')') $parenDepth--;
                $j++;
            }
            if ($j >= $count) continue;

            $openIndex = $j;

            // Brace-balanced walk to matching `}`. CAREFUL: PHP's
            // tokenizer represents string-interpolation `{$var}` as
            // `T_CURLY_OPEN` for the opening `{` but a plain `}` char
            // for the closing — which would naively decrement our
            // closure depth. We track an interpolation stack to skip
            // those closing braces.
            $depth = 1;
            $interpolationStack = 0;
            $k = $j + 1;
            while ($k < $count) {
                $tk = $tokens[$k];
                if (is_array($tk)) {
                    if ($tk[0] === T_CURLY_OPEN || $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                        $interpolationStack++;
                    }
                } else {
                    if ($tk === '{') {
                        $depth++;
                    } elseif ($tk === '}') {
                        if ($interpolationStack > 0) {
                            $interpolationStack--;
                        } else {
                            $depth--;
                            if ($depth === 0) break;
                        }
                    }
                }
                $k++;
            }
            if ($k >= $count) continue;

            $closures[] = [$offsets[$openIndex], $offsets[$k]];
        }

        foreach ($closures as [$open, $close]) {
            if ($targetOffset > $open && $targetOffset < $close) {
                return true;
            }
        }
        return false;
    }
}
