<?php

namespace Tests\Feature\Fiscal;

use Tests\TestCase;

/**
 * [Wave M / Heal Z5 P1-C] fiscal_alloc_error_at flag-write placement sentinel.
 *
 * Locks the invariant: the `fiscal_alloc_error_at` flag MUST be persisted
 * OUTSIDE the parent `DB::transaction` of {@see \App\Services\FrontendOrderService::finalizePaidKioskOrder}
 * — otherwise a failure of the flag-save itself rolls the flag back along
 * with the rest of the transaction, recreating the pre-iter14 orphan
 * (PAID + PENDING + seq=NULL + flag=NULL → invisible to KDS, excluded
 * from Z aggregation, unrecoverable by retry cron).
 *
 * Audit chain:
 *  - iter13 ORDER-PATH P1: original orphan, alloc throw → tx rollback,
 *    no marker → unrecoverable.
 *  - iter14 commit `3150992a7`: catch sets flag inline + log + return,
 *    no rethrow. Fixed the dominant case (alloc throws).
 *  - RED-Z5 §B F-Z5-P1-C (this session): identified narrow nested-failure
 *    edge case — if `$locked->save()` for the flag itself throws (trigger,
 *    FK, DB hiccup), the throw bubbles, the parent tx rolls back, flag
 *    lost. Recommendation: write via separate `DB::table()->update()`
 *    OUTSIDE the parent transaction.
 *  - Wave M (this heal): apply recommendation + lock with sentinel.
 *
 * The sentinel verifies (source inspection):
 *  1. The error-flag write occurs via a raw `DB::table('orders')` update
 *     (bypasses Eloquent save() inside the tx).
 *  2. That call appears OUTSIDE the `DB::transaction(function () use ...);`
 *     closure of `finalizePaidKioskOrder`.
 *  3. The legacy `$locked->fiscal_alloc_error_at = now();` + `$locked->save();`
 *     in-closure pattern is absent from `finalizePaidKioskOrder` (no
 *     reintroduction via refactor).
 *
 * Companion: existing `FiscalAllocOrphanRetryTest` covers the happy path
 * (flag persists after alloc throws). This sentinel covers the structural
 * lock so a refactor cannot silently move the write back inside the tx.
 */
class FiscalAllocErrorFlagOutsideTxSentinelTest extends TestCase
{
    private string $servicePath;
    private string $methodBody;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servicePath = app_path('Services/FrontendOrderService.php');
        $source = file_get_contents($this->servicePath);
        $this->assertNotFalse($source);

        // Extract `finalizePaidKioskOrder` body via PHP's native
        // tokenizer with comments stripped. Initial implementation used
        // a char-by-char walker that did not skip `//`/`/* */` comments;
        // a documentation comment containing the text
        // "DB::table('orders')" was wrongly matched by the regex below
        // and broke the position invariant. Tokenizer = robust.
        $this->methodBody = $this->extractMethodBodyViaTokenizer(
            $source,
            'finalizePaidKioskOrder'
        );
        $this->assertNotEmpty(
            $this->methodBody,
            'finalizePaidKioskOrder method must exist with a parseable body'
        );
    }

    /**
     * Returns the body of the named function/method with comments
     * stripped (replaced by equal-length whitespace so offsets remain
     * meaningful), via PHP's `token_get_all` tokenizer.
     */
    private function extractMethodBodyViaTokenizer(string $source, string $methodName): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        $methodIndex = null;
        for ($i = 0; $i < $count - 2; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            $nt = $tokens[$j] ?? null;
            if (is_array($nt) && $nt[0] === T_STRING && $nt[1] === $methodName) {
                $methodIndex = $i;
                break;
            }
        }
        if ($methodIndex === null) return '';

        // Advance past signature `(...)` to first `{`.
        $j = $methodIndex + 1;
        $foundParen = false;
        $parenDepth = 0;
        while ($j < $count) {
            $tk = $tokens[$j];
            if (!$foundParen) {
                if ($tk === '(') { $foundParen = true; $parenDepth = 1; }
            } else {
                if ($tk === '(') $parenDepth++;
                elseif ($tk === ')') {
                    $parenDepth--;
                    if ($parenDepth === 0) { $j++; break; }
                }
            }
            $j++;
        }
        while ($j < $count && $tokens[$j] !== '{') $j++;
        if ($j >= $count) return '';

        $openIndex = $j;

        // Brace-balanced walk via tokens (string-/comment-immune).
        $depth = 1;
        $interpStack = 0;
        $k = $j + 1;
        while ($k < $count) {
            $tk = $tokens[$k];
            if (is_array($tk)) {
                if ($tk[0] === T_CURLY_OPEN || $tk[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $interpStack++;
                }
            } else {
                if ($tk === '{') $depth++;
                elseif ($tk === '}') {
                    if ($interpStack > 0) $interpStack--;
                    else {
                        $depth--;
                        if ($depth === 0) break;
                    }
                }
            }
            $k++;
        }
        if ($k >= $count) return '';

        $body = '';
        for ($idx = $openIndex; $idx <= $k; $idx++) {
            $tk = $tokens[$idx];
            if (is_array($tk)) {
                $id = $tk[0];
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    // Replace comment text with equal-length whitespace
                    // so byte offsets within `$body` remain meaningful
                    // relative to the source structure.
                    $body .= str_repeat(' ', strlen($tk[1]));
                    continue;
                }
                $body .= $tk[1];
            } else {
                $body .= $tk;
            }
        }
        return $body;
    }

    public function test_flag_write_uses_raw_db_table_update(): void
    {
        $this->assertMatchesRegularExpression(
            "/DB::table\\(\\s*['\"]orders['\"]\\s*\\)/",
            $this->methodBody,
            'finalizePaidKioskOrder must persist fiscal_alloc_error_at via '
            . 'DB::table(\'orders\')->update(...) — raw query bypasses '
            . 'parent transaction rollback if Eloquent save() itself throws.'
        );

        $this->assertMatchesRegularExpression(
            "/['\"]fiscal_alloc_error_at['\"]\\s*=>/",
            $this->methodBody,
            'finalizePaidKioskOrder must reference fiscal_alloc_error_at '
            . 'in the raw update payload.'
        );
    }

    public function test_flag_write_is_outside_parent_db_transaction_closure(): void
    {
        // Find the parent `DB::transaction(function` in the method body.
        $txPos = strpos($this->methodBody, 'DB::transaction(function');
        $this->assertNotFalse($txPos, 'finalizePaidKioskOrder must keep its DB::transaction wrapping the alloc closure');

        $openBrace = strpos($this->methodBody, '{', $txPos);
        $this->assertNotFalse($openBrace);

        // Walk to matching close.
        $depth = 1;
        $i = $openBrace + 1;
        $inString = null;
        while ($i < strlen($this->methodBody) && $depth > 0) {
            $ch = $this->methodBody[$i];
            if ($inString !== null) {
                if ($ch === '\\') {
                    $i += 2;
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
            } else {
                if ($ch === "'" || $ch === '"') {
                    $inString = $ch;
                } elseif ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                }
            }
            $i++;
        }
        $closeBrace = $i; // position just after the matching `}`.

        // Position of the raw DB::table('orders') call.
        preg_match(
            "/DB::table\\(\\s*['\"]orders['\"]\\s*\\)/",
            $this->methodBody,
            $tableMatch,
            PREG_OFFSET_CAPTURE
        );
        $this->assertNotEmpty($tableMatch);
        $tablePos = $tableMatch[0][1];

        $this->assertGreaterThan(
            $closeBrace,
            $tablePos,
            'fiscal_alloc_error_at DB::table()->update(...) MUST appear after '
            . 'the parent DB::transaction closure closes — otherwise a save '
            . 'failure rolls back the flag, recreating the pre-iter14 orphan '
            . '(see RED-Z5 §B F-Z5-P1-C 2026-05-19).'
        );
    }

    public function test_legacy_in_tx_eloquent_flag_save_is_absent(): void
    {
        // Reject re-introduction of the in-tx pattern:
        //   $locked->fiscal_alloc_error_at = now();
        //   $locked->save();
        $combo = preg_match(
            "/\\\$locked->fiscal_alloc_error_at\\s*=\\s*now\\(\\)\\s*;\\s*\\\$locked->save\\(\\)/s",
            $this->methodBody
        );

        $this->assertSame(
            0,
            $combo,
            'finalizePaidKioskOrder must not re-introduce the inline '
            . '`$locked->fiscal_alloc_error_at = now(); $locked->save();` '
            . 'pattern — that lives inside the parent transaction and '
            . 'rolls back on nested save() failure.'
        );
    }
}
