<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\FiscalSealingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FiscalSealingHmacTest extends TestCase
{
    public function test_hmac_is_deterministic_for_canonical_payload_order(): void
    {
        Config::set('fiscal.z_report_secret', str_repeat('z', 48));
        $service = app(FiscalSealingService::class);
        $closedAt = Carbon::parse('2026-04-25 10:00:00', 'Europe/Paris');

        $first = $service->signZReport(7, str_repeat('a', 64), 3, [
            'total_tva' => 2.0,
            'order_count' => 1,
            'total_by_method' => ['cash' => 22.0, 'card' => 10.0],
            'total_ttc' => 32.0,
            'total_ht' => 30.0,
        ], $closedAt);

        $second = $service->signZReport(7, str_repeat('a', 64), 3, [
            'total_ht' => 30.0,
            'total_ttc' => 32.0,
            'total_by_method' => ['card' => 10.0, 'cash' => 22.0],
            'order_count' => 1,
            'total_tva' => 2.0,
        ], $closedAt->copy()->utc());

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
    }

    public function test_hmac_is_branch_scoped(): void
    {
        Config::set('fiscal.z_report_secret', str_repeat('z', 48));
        $service = app(FiscalSealingService::class);
        $payload = [
            'total_ttc' => 12.0,
            'total_ht' => 10.0,
            'total_tva' => 2.0,
            'order_count' => 1,
        ];
        $closedAt = Carbon::parse('2026-04-25 10:00:00', 'UTC');

        $branchA = $service->signZReport(1, '', 1, $payload, $closedAt);
        $branchB = $service->signZReport(2, '', 1, $payload, $closedAt);

        $this->assertNotSame($branchA, $branchB);
    }

    public function test_z_report_signature_matches_legacy_service_hmac_contract(): void
    {
        $secret = str_repeat('l', 48);
        Config::set('fiscal.z_report_secret', $secret);

        $branchId = 9;
        $prevHash = str_repeat('b', 64);
        $sequenceNo = 4;
        $closedAt = Carbon::parse('2026-04-25 12:30:00', 'Europe/Paris');
        $aggregates = [
            'total_tva' => 2.0,
            'total_ttc' => 12.0,
            'total_ht' => 10.0,
            'total_by_tax_rate' => ['20' => 2.0],
            'total_by_method' => ['card' => 12.0],
            'refund_count' => 0,
            'order_count' => 1,
            'cancel_count' => 0,
        ];

        $legacyAggregates = $aggregates;
        ksort($legacyAggregates);
        $legacyCanonical = json_encode([
            'branch_id' => $branchId,
            'sequence_no' => $sequenceNo,
            'closed_at' => $closedAt->copy()->utc()->toIso8601String(),
            'aggregates' => $legacyAggregates,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $expected = hash_hmac('sha256', $prevHash . '|' . $legacyCanonical, $secret);

        $this->assertSame(
            $expected,
            app(FiscalSealingService::class)->signZReport($branchId, $prevHash, $sequenceNo, $aggregates, $closedAt)
        );
    }
}
