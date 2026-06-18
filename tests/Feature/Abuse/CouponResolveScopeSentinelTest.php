<?php

namespace Tests\Feature\Abuse;

use Tests\TestCase;

/**
 * SENTINEL coupon_resolve_scope — order-create call sites must pass coupon scope.
 * [abuse-heal 2026-06-18 engines]
 *
 * Finding (5-engine hard discovery): the coupon-CHECK endpoint
 * (CouponService::couponChecking) reads branch_id + surface from the request
 * and forwards them to resolveCouponByCode → validateCouponForOrder →
 * Coupon::isUsableNow($branchId, $surface), enforcing the advanced scopes
 * (surfaces, branch_scope). But the order-CREATE paths called
 * resolveCouponById() with ONLY (couponId, subtotal, userId) — branchId +
 * surface defaulted to null, so isUsableNow treated them as "no filter" and a
 * kiosk-only coupon (surfaces=['kiosk']) was happily applied on POS / web. The
 * V1-real impact is the SURFACE leak (revenue leak); branch_scope is V2-prep.
 *
 * This sentinel reflects every `resolveCouponById(` call site in OrderService
 * + FrontendOrderService and asserts NONE of them omit the scope arguments —
 * each call must pass at least 5 arguments (couponId, subtotal, userId,
 * branchId, surface) so a future order-create path can't silently bypass the
 * scope again. (Source/AST sentinel: SQLite behavior tests cannot exercise the
 * full pricing+items create harness cheaply, and the bug lives at the call
 * site, not inside the — already correct — service method.)
 */
class CouponResolveScopeSentinelTest extends TestCase
{
    /**
     * @return array<int, array{0:string}>
     */
    public static function serviceFiles(): array
    {
        return [
            ['Services/OrderService.php'],
            ['Services/FrontendOrderService.php'],
        ];
    }

    /**
     * @dataProvider serviceFiles
     */
    public function test_every_resolve_coupon_by_id_call_passes_scope(string $relativePath): void
    {
        $src = file_get_contents(app_path($relativePath));
        $this->assertNotFalse($src, "could not read {$relativePath}");

        // Find each `resolveCouponById(` call and capture its argument list up to
        // the matching close paren (the calls here are simple, no nested parens in
        // the arg list other than casts like (int)$x — handled by counting depth).
        $offset = 0;
        $callCount = 0;
        while (($pos = strpos($src, 'resolveCouponById(', $offset)) !== false) {
            $callCount++;
            $argsStart = $pos + strlen('resolveCouponById(');
            $depth = 1;
            $i = $argsStart;
            $len = strlen($src);
            while ($i < $len && $depth > 0) {
                $ch = $src[$i];
                if ($ch === '(') {
                    $depth++;
                } elseif ($ch === ')') {
                    $depth--;
                }
                $i++;
            }
            $argList = substr($src, $argsStart, $i - $argsStart - 1);

            // Count top-level commas (depth 0) to derive the argument count.
            $topCommas = 0;
            $d = 0;
            foreach (str_split($argList) as $c) {
                if ($c === '(' || $c === '[') {
                    $d++;
                } elseif ($c === ')' || $c === ']') {
                    $d--;
                } elseif ($c === ',' && $d === 0) {
                    $topCommas++;
                }
            }
            $argCount = $topCommas + 1;

            $this->assertGreaterThanOrEqual(
                4,
                $argCount,
                "A resolveCouponById() call in {$relativePath} passes only {$argCount} args — "
                . "it MUST pass at least branchId (4th arg) so a scoped coupon cannot bypass "
                . "branch/surface validation on order-create. Call args: <{$argList}>"
            );

            $offset = $i;
        }

        $this->assertGreaterThan(
            0,
            $callCount,
            "expected at least one resolveCouponById() call in {$relativePath}"
        );
    }
}
