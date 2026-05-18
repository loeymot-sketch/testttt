<?php

namespace Tests\Feature\Pos;

use App\Http\Requests\PosOrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Sprint H5 POS-A6 2026-05-17] Sentinel — assert that PosOrderRequest's
 * client-side `total`, `discount`, `subtotal` payload is OPTIONAL.
 *
 * V1 SSOT rule (CLAUDE.md §8): 100% of pricing is computed server-side via
 * `PricingService::calculateOrder`. The frontend MUST NOT be required to
 * send these 3 fields, and any value it does send is treated as advisory
 * only (UX cross-check for cash). The complementary functional behaviour
 * is already covered by `PosOrderRequestNullableTotalTest` (POS-9.1.8) —
 * this test is a rules-shape guardrail that fires fast in CI without a
 * full HTTP boot.
 *
 * Sister finding: Wave Z POS-A6 (`PosComponent.vue:2722-2734` JS-calc
 * totals stripped from payload in `PaymentComponent.runConfirmOrderAttempt`).
 *
 * If a future change re-introduces `required` on any of the 3 fields, this
 * test trips and forces a deliberate review (probable regression on SSOT).
 */
class PosOrderRequestNoClientTotalsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     * @dataProvider clientTotalsFieldProvider
     */
    public function pos_order_request_does_not_require_client_totals(string $field): void
    {
        $rules = (new PosOrderRequest)->rules();

        $this->assertArrayHasKey(
            $field,
            $rules,
            "PosOrderRequest::rules() MUST define `$field` (even as nullable) so the frontend may stop sending it without a 422 mismatch.",
        );

        $ruleStr = is_array($rules[$field]) ? implode('|', $rules[$field]) : (string) $rules[$field];

        $this->assertStringNotContainsString(
            'required',
            $ruleStr,
            "PosOrderRequest::$field MUST NOT be 'required' — server recomputes via PricingService (SSOT, CLAUDE.md §8). Rule observed: `$ruleStr`",
        );

        $this->assertStringContainsString(
            'nullable',
            $ruleStr,
            "PosOrderRequest::$field SHOULD be explicitly 'nullable' so frontend may omit it. Rule observed: `$ruleStr`",
        );
    }

    public static function clientTotalsFieldProvider(): array
    {
        return [
            'total'    => ['total'],
            'discount' => ['discount'],
            'subtotal' => ['subtotal'],
        ];
    }

    /** @test */
    public function pos_order_request_rules_keep_min_zero_guard_on_client_totals(): void
    {
        // [P7] Even though the fields are nullable, when the client *does*
        // send a value it must be non-negative. This guards the negative-amount
        // bypass (PosOrderRequestNullableTotalTest::test_negative_subtotal_rejected_at_validation).
        $rules = (new PosOrderRequest)->rules();

        foreach (['total', 'discount', 'subtotal'] as $field) {
            $ruleStr = is_array($rules[$field]) ? implode('|', $rules[$field]) : (string) $rules[$field];
            $this->assertStringContainsString(
                'min:0',
                $ruleStr,
                "PosOrderRequest::$field MUST keep `min:0` to reject negative client spoofs (P7 guard). Rule: `$ruleStr`",
            );
        }
    }
}
