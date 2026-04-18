<?php

namespace App\Services\Fiscal;

use App\Models\ZReport;
use Illuminate\Support\Carbon;

/**
 * [POS-9.4.8 / POS-GA-F-02]
 *
 * Read-only intraday fiscal snapshot ("X report"). Never writes.
 *
 * The "period" of an X report is the time between the last CLOSED Z
 * for that branch and "now" (or an explicit override). If no Z has
 * ever been closed, the period starts at the first order date for the
 * branch (best-effort; controllers can pass an explicit `$from`).
 *
 * The aggregation algorithm is delegated to ZReportService so an X
 * snapshot is guaranteed to match the Z that will be produced for the
 * same window — this is the NF525 "cohérence intraday" invariant.
 */
class XReportService
{
    public function __construct(
        private ZReportService $zReports
    ) {}

    /**
     * @return array{
     *   branch_id: int,
     *   generated_at: string,
     *   period: array{from: ?string, to: string},
     *   totals: array<string,mixed>
     * }
     */
    public function snapshot(int $branchId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('XReportService::snapshot requires a positive branch_id.');
        }

        $to   = $to   ?? Carbon::now();
        $from = $from ?? $this->defaultFrom($branchId);

        $totals = $this->zReports->aggregate($branchId, $from, $to);

        return [
            'branch_id'    => $branchId,
            'generated_at' => Carbon::now()->toIso8601String(),
            'period' => [
                'from' => $from ? $from->toIso8601String() : null,
                'to'   => $to->toIso8601String(),
            ],
            'totals' => $totals,
        ];
    }

    private function defaultFrom(int $branchId): ?Carbon
    {
        $lastClose = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_CLOSED)
            ->orderByDesc('closed_at')
            ->value('closed_at');

        if ($lastClose) {
            return $lastClose instanceof Carbon ? $lastClose : Carbon::parse($lastClose);
        }

        // If a Z is currently open for the branch, use its opened_at.
        $currentOpen = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_OPEN)
            ->orderByDesc('opened_at')
            ->value('opened_at');

        return $currentOpen
            ? ($currentOpen instanceof Carbon ? $currentOpen : Carbon::parse($currentOpen))
            : null;
    }
}
