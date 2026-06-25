<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\ZReport;
use Illuminate\Console\Command;

/**
 * [P0 #1 detect-only — owner decision 2026-05-29] NF525 Z-membership reconciliation.
 *
 * The from-roots audit found that a numbered receipt absent from every signed Z is
 * a categorical NF525 stop-ship, and that NO compensating control existed. The
 * owner chose "detect-only for now" for the cross-Z-window settlement class (P0 #1):
 * we keep the revert that stopped numbering cross-window flips, and add this
 * read-only detector so any orphan is surfaced before it becomes a fiscal problem.
 *
 * READ-ONLY. No behavior change, no frozen-zone touch, no writes.
 *
 * Heuristic: ZReportService::aggregate windows revenue by created_at, with a
 * post-Z catch only for TERMINAL-status rows. So a numbered, settled, non-terminal
 * order whose created_at falls in an ALREADY-CLOSED Z window, but that was sealed/
 * modified AFTER that Z closed (updated_at > Z.closed_at), was never aggregated by
 * its window's Z and won't be picked up by any later Z (created_at > $from) →
 * cross-window orphan CANDIDATE. The heuristic can include orders that were
 * legitimately counted in their Z but had a later (post-Z) status change — those
 * are benign false positives, hence "candidate, review" framing (read-only is safe).
 */
class VerifyZMembershipCommand extends Command
{
    protected $signature = 'fiscal:verify-z-membership {--branch= : limit to a single branch_id}';

    protected $description = 'NF525 read-only detector: flag fiscally-numbered orders at risk of appearing in NO signed Z (cross-Z-window settlement orphans, P0 #1 detect-only).';

    public function handle(): int
    {
        $terminal = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];

        $branchIds = $this->option('branch')
            ? [(int) $this->option('branch')]
            : Branch::query()->pluck('id')->all();

        $candidates = [];

        foreach ($branchIds as $bid) {
            // Orders that MUST appear in a Z: numbered + settled + non-terminal.
            $orders = Order::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $bid)
                ->whereNotNull('fiscal_sequence_no')
                ->where('payment_status', '!=', PaymentStatus::UNPAID)
                ->whereNotIn('status', $terminal)
                ->whereNull('parent_order_id') // refund mirrors handled separately
                ->get(['id', 'order_serial_no', 'fiscal_sequence_no', 'created_at', 'updated_at', 'total', 'branch_id']);

            foreach ($orders as $o) {
                // Closed Z whose window covers the order's created_at.
                $coveringZ = ZReport::query()
                    ->where('branch_id', $bid)
                    ->where('status', ZReport::STATUS_CLOSED)
                    ->where('opened_at', '<', $o->created_at)
                    ->where('closed_at', '>=', $o->created_at)
                    ->orderByDesc('closed_at')
                    ->first();

                if (! $coveringZ) {
                    // [GAP-ORPHAN 2026-06-25] Aucun Z FERMÉ ne couvre created_at.
                    // Si un Z OUVERT la couvre (opened_at < created_at) → elle sera
                    // agrégée à la clôture → OK. Sinon la vente tombe dans le TROU
                    // entre un Z fermé et le prochain Z ouvert (ou avant tout Z) :
                    // le prochain Z n'agrège que depuis SON opened_at (fenêtre
                    // (opened_at, closed_at]) → elle ne sera dans AUCUN Z signé →
                    // orphelin (« rapport faux » : sous-évalue ce qu'a rapporté le
                    // service). L'ancien `continue` aveugle la ratait (faux-vert).
                    $openZ = ZReport::query()
                        ->where('branch_id', $bid)
                        ->where('status', ZReport::STATUS_OPEN)
                        ->where('opened_at', '<', $o->created_at)
                        ->orderByDesc('opened_at')
                        ->first();

                    if ($openZ) {
                        // Dans la fenêtre du Z ouvert courant → sera agrégée à la clôture.
                        continue;
                    }

                    $candidates[] = [
                        'branch'      => $bid,
                        'order'       => (string) ($o->order_serial_no ?? $o->id),
                        'seq'         => (int) $o->fiscal_sequence_no,
                        'total'       => number_format((float) $o->total, 2),
                        'created_at'  => (string) $o->created_at,
                        'Z_closed_at' => 'TROU — aucun Z ne couvre cette vente',
                    ];
                    continue;
                }

                // Window already closed. If the order was sealed/modified AFTER that
                // Z closed, the aggregation already ran without it and no later Z
                // includes it → orphan candidate.
                if ($o->updated_at && $o->updated_at->gt($coveringZ->closed_at)) {
                    $candidates[] = [
                        'branch'      => $bid,
                        'order'       => (string) ($o->order_serial_no ?? $o->id),
                        'seq'         => (int) $o->fiscal_sequence_no,
                        'total'       => number_format((float) $o->total, 2),
                        'created_at'  => (string) $o->created_at,
                        'Z_closed_at' => (string) $coveringZ->closed_at,
                    ];
                }
            }
        }

        if (empty($candidates)) {
            $this->info('Z-membership OK — no numbered order flagged as a cross-Z-window orphan candidate.');
            return self::SUCCESS;
        }

        $this->warn(count($candidates) . ' numbered order(s) flagged as cross-Z-window orphan CANDIDATE(s) — review (heuristic may include legitimately-counted orders modified post-Z):');
        $this->table(
            ['branch', 'order', 'seq', 'total', 'created_at', 'sealed/updated after Z closed_at'],
            array_map('array_values', $candidates)
        );
        $this->line('A numbered receipt absent from every signed Z is an NF525 gap-free risk. Investigate. See reports/audit/massive-validation-2026-05-29/ESCALATION_NO_GO.md (P0 #1).');

        return self::FAILURE;
    }
}
