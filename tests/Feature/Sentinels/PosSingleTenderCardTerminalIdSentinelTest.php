<?php

namespace Tests\Feature\Sentinels;

use App\Enums\PosPaymentMethod;
use App\Http\Requests\PosOrderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P1 V1 Cloud-Prep insights 2026-05-18] Sentinel: single-tender CARD POS
 * payment MUST require `terminal_id`. Wave 5F F-SPLIT-PHANTOM-CARD-001
 * closed the split-tender CARD path (payment_breakdown.*.terminal_id with
 * branch + ACTIVE checks). This sentinel closes the legacy single-tender
 * path that was still bucketed as "Sans TPE" in the Z-report TPE breakdown.
 *
 * Tested at the rule-shape level only (cheap, isolated). The deep ACTIVE +
 * branch ownership cross-check belongs in OrderService::posOrderStore — it
 * needs branch context that the FormRequest doesn't have reliably (CARD
 * tile single-tender bypass attempt by a desync'd UI must die at the
 * service layer, not the request layer).
 */
class PosSingleTenderCardTerminalIdSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // PosOrderRequest::rules() reads Settings::group('pos')->get('pos_dine_in_enabled')
        // at call time, which hits the `settings` table. seedMinimalSettings()
        // ships a `pos.pos_dine_in_enabled=false` row.
        $this->seedMinimalSettings();
    }

    public function test_rules_require_terminal_id_when_single_tender_card(): void
    {
        $rules = (new PosOrderRequest())->rules();

        $this->assertArrayHasKey(
            'terminal_id',
            $rules,
            'PosOrderRequest must declare a top-level `terminal_id` rule '
            . 'for single-tender CARD path (Wave 5F closed the split path; '
            . 'this closes the legacy single-tender path).'
        );

        $rule = is_array($rules['terminal_id'])
            ? implode('|', $rules['terminal_id'])
            : $rules['terminal_id'];

        $this->assertStringContainsString(
            'required_if:pos_payment_method,' . PosPaymentMethod::CARD,
            $rule,
            'terminal_id must be required_if pos_payment_method=CARD ('
            . PosPaymentMethod::CARD . ') so single-tender CARD sales '
            . 'cannot bypass Z-report TPE attribution.'
        );

        $this->assertStringContainsString(
            'integer',
            $rule,
            'terminal_id must be typed as integer.'
        );

        $this->assertStringContainsString(
            'min:1',
            $rule,
            'terminal_id must have a min:1 floor to reject 0 / negative spoofs.'
        );
    }
}
