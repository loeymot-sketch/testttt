<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\ZReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * [P0 #1 — NF525 Z-membership reconciliation — HONEST per-Z re-aggregation
 *  2026-07-07 / LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT P2]
 *
 * Proves the NF525 gap-free invariant: "every fiscally-numbered receipt appears
 * in exactly one signed Z (or is pending in the current open window)." READ-ONLY.
 * No behaviour change to signing, no frozen-zone touch, no writes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS WAS REWRITTEN AGAIN (P2 — the C33-only detector MASKED historical
 * orphans → false negatives on the very class of bug it exists to catch)
 * ─────────────────────────────────────────────────────────────────────────────
 * The previous rewrite reconstructed EVERY window with C33 continuous-partition
 * semantics ((closed_{n-1}, closed_n]). But Z reports signed BEFORE the C33 fix
 * went live were signed with the LEGACY window (opened_at, closed_at] on
 * created_at. A sale that fell in the "dead window" between a legacy Z's close
 * and the next legacy Z's OPEN was NEVER aggregated into any signed Z — yet the
 * C33-only detector reconstructed a continuous window that "covered" it and
 * declared 0 orphan = a FALSE NEGATIVE (e.g. branch 1 seq=2467, created
 * 2026-06-07 00:04 in the dead window Z5.closed → Z6.opened).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HONEST MODEL — reconstruct what each Z ACTUALLY sealed (legacy vs C33)
 * ─────────────────────────────────────────────────────────────────────────────
 * Z reports are split by the C33 cutover (config fiscal.c33_cutover_at, the
 * deploy instant of the C33 fix). For a branch, list CLOSED Z sorted by
 * (closed_at, id) ascending; walking them, each Z's REAL sealed window is:
 *
 *   - Z closed  >= cutover (POST-C33):  (closed_{prev}, closed_n]   keyed on the
 *       FISCAL date COALESCE(fiscal_dated_at, created_at) — identical to
 *       ZReportService::aggregate() today (continuous partition + deferred
 *       fiscal_dated_at membership). $prev is the previous Z's closed_at in this
 *       ordering (null → −∞ for the first Z ever).
 *   - Z closed  <  cutover (PRE-C33, LEGACY):  (opened_n, closed_n]  keyed on
 *       created_at — exactly what aggregate() signed at the time. This LEAVES the
 *       dead window (closed_{prev}, opened_n) uncovered, as it genuinely was.
 *
 * A numbered order is COVERED iff its date (per each window's own semantics)
 * falls in at least one such real window. It is a REAL ORPHAN iff it is covered
 * by NO signed window AND it is not pending in the current open window (an OPEN
 * Z whose future — post-C33 — close will seal every sale with fiscal date after
 * the last close). Historical dead-window orphans (pre-C33) are reported
 * HONESTLY as orphans — the detector never claims 0 while the mixed history
 * still holds genuinely-unsealed numbered receipts.
 *
 * Population mirrors aggregate()'s positive-revenue set exactly: numbered
 * (fiscal_sequence_no NOT NULL), settled (payment_status != UNPAID), non-terminal
 * (not CANCELED/REJECTED/RETURNED), no refund mirror (parent_order_id NULL), and
 * withTrashed()+withoutGlobalScope(BranchScope) so soft-deleted post-allocation
 * orders are visible exactly as the aggregator sees them.
 */
class VerifyZMembershipCommand extends Command
{
    protected $signature = 'fiscal:verify-z-membership {--branch= : limit to a single branch_id}';

    protected $description = 'NF525 read-only detector (HONEST per-Z re-aggregation, pre/post-C33 aware): flag fiscally-numbered orders sealed in NO signed Z and not pending in the current open window — including historical dead-window orphans (0 false positive, 0 false negative).';

    public function handle(): int
    {
        $terminal = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];

        $cutover = $this->c33Cutover();
        $hasFiscalDatedAt = Schema::hasColumn('orders', 'fiscal_dated_at');

        $branchIds = $this->option('branch')
            ? [(int) $this->option('branch')]
            : Branch::query()->pluck('id')->all();

        $candidates = [];

        foreach ($branchIds as $bid) {
            // CLOSED Z sorted by (closed_at, id) ascending. Walk them to build the
            // REAL sealed window of each Z (legacy vs C33 semantics).
            $closedZ = ZReport::query()
                ->where('branch_id', $bid)
                ->where('status', ZReport::STATUS_CLOSED)
                ->whereNotNull('closed_at')
                ->orderBy('closed_at')
                ->orderBy('id')
                ->get(['id', 'opened_at', 'closed_at']);

            $windows = [];
            $prevClosed = null; // Carbon|null — previous Z's closed_at in ascending order
            $lastClosedAt = null;
            foreach ($closedZ as $z) {
                $closedAt = $z->closed_at instanceof Carbon ? $z->closed_at : Carbon::parse($z->closed_at);

                if ($closedAt->gte($cutover)) {
                    // POST-C33: continuous partition keyed on the fiscal date.
                    $windows[] = [
                        'lower' => $prevClosed,          // null → −∞
                        'upper' => $closedAt,            // inclusive
                        'date'  => 'fiscal',
                    ];
                } else {
                    // PRE-C33 (legacy): (opened_at, closed_at] keyed on created_at.
                    $openedAt = $z->opened_at
                        ? ($z->opened_at instanceof Carbon ? $z->opened_at : Carbon::parse($z->opened_at))
                        : null;
                    $windows[] = [
                        'lower' => $openedAt,            // null → −∞ (defensive)
                        'upper' => $closedAt,            // inclusive
                        'date'  => 'created',
                    ];
                }

                $prevClosed = $closedAt;
                $lastClosedAt = $closedAt;
            }

            // The current open window exists iff an OPEN Z is present. Its close is
            // post-C33 → aggregates from $lastClosedAt keyed on the fiscal date.
            $hasOpenZ = ZReport::query()
                ->where('branch_id', $bid)
                ->where('status', ZReport::STATUS_OPEN)
                ->exists();

            // Population = aggregate()'s positive-revenue set (mirror exact).
            $columns = ['id', 'order_serial_no', 'fiscal_sequence_no', 'created_at', 'total', 'branch_id'];
            if ($hasFiscalDatedAt) {
                $columns[] = 'fiscal_dated_at';
            }

            $orders = Order::withoutGlobalScope(BranchScope::class)
                ->withTrashed()
                ->where('branch_id', $bid)
                ->whereNotNull('fiscal_sequence_no')
                ->where('payment_status', '!=', PaymentStatus::UNPAID)
                ->whereNotIn('status', $terminal)
                ->whereNull('parent_order_id')
                ->get($columns);

            foreach ($orders as $o) {
                $createdAt = $o->created_at instanceof Carbon ? $o->created_at : Carbon::parse($o->created_at);
                $fiscalDate = ($hasFiscalDatedAt && $o->fiscal_dated_at)
                    ? ($o->fiscal_dated_at instanceof Carbon ? $o->fiscal_dated_at : Carbon::parse($o->fiscal_dated_at))
                    : $createdAt;

                if ($this->fallsInAnySignedWindow($windows, $createdAt, $fiscalDate)) {
                    continue; // sealed in a signed Z → OK
                }

                // Not covered by any signed window. Pending iff an OPEN Z will seal
                // it (fiscal date strictly after the last close, or no close yet).
                if ($hasOpenZ && ($lastClosedAt === null || $fiscalDate->gt($lastClosedAt))) {
                    continue; // pending in the current open window
                }

                // Real orphan — report honestly with a precise motif.
                if ($lastClosedAt === null) {
                    $motif = 'aucun Z (ni clos ni ouvert) sur cette branche';
                } elseif ($fiscalDate->lte($lastClosedAt)) {
                    $motif = 'fenetre-morte pre-C33 (entre cloture Z precedent et ouverture Z suivant) — jamais agregee (semantique opened_at d\'alors) = orphelin historique';
                } else {
                    $motif = 'apres le dernier Z clos ('.$lastClosedAt.'), aucun Z ouvert pour la sceller';
                }

                $candidates[] = $this->candidate($bid, $o, $motif);
            }
        }

        if (empty($candidates)) {
            $this->info('Z-membership OK — chaque commande numerotee est dans un Z signe (fenetre reelle pre/post-C33) ou en attente dans la fenetre ouverte courante (aucun orphelin).');

            return self::SUCCESS;
        }

        $this->warn(count($candidates).' commande(s) numerotee(s) REELLEMENT hors de tout Z signe (detecteur honnete pre/post-C33) :');
        $this->table(
            ['branch', 'order', 'seq', 'total', 'created_at', 'motif'],
            array_map('array_values', $candidates)
        );
        $this->line('Un recu numerote absent de tout Z signe est une violation NF525 gap-free. Les orphelins « fenetre-morte pre-C33 » sont des ventes historiques jamais scellees sous l\'ancienne semantique (opened_at) — a rescellage manuel/documentation. Les orphelins « apres le dernier Z clos » se scellent en ouvrant puis cloturant un Z (partition continue C33). Voir reports/audit/massive-validation-2026-05-29/ESCALATION_NO_GO.md (P0 #1).');

        return self::FAILURE;
    }

    /**
     * The C33 cutover instant: Z reports closed on/after it were signed with the
     * C33 continuous-partition + fiscal_dated_at semantics; those closed before
     * it were signed with the legacy (opened_at, closed_at] window on created_at.
     */
    private function c33Cutover(): Carbon
    {
        $raw = Config::get('fiscal.c33_cutover_at', '2026-07-07 00:00:00');

        try {
            return $raw instanceof Carbon ? $raw : Carbon::parse((string) $raw);
        } catch (\Throwable $e) {
            // Malformed override → default cutover (never crash the detector).
            return Carbon::parse('2026-07-07 00:00:00');
        }
    }

    /**
     * True iff the order falls in at least one Z's REAL sealed window. Each
     * window carries its own date semantics:
     *   - 'fiscal'  (post-C33) → compare COALESCE(fiscal_dated_at, created_at);
     *   - 'created' (pre-C33 legacy) → compare created_at.
     * Bounds: lower STRICT (null → −∞), upper INCLUSIVE — identical to the
     * window ZReportService::aggregate() actually signed.
     *
     * @param  array<int, array{lower: ?Carbon, upper: Carbon, date: string}>  $windows
     */
    private function fallsInAnySignedWindow(array $windows, Carbon $createdAt, Carbon $fiscalDate): bool
    {
        foreach ($windows as $w) {
            $cmp = $w['date'] === 'fiscal' ? $fiscalDate : $createdAt;
            $lower = $w['lower'];

            $aboveLower = $lower === null || $cmp->gt($lower);
            if ($aboveLower && $cmp->lte($w['upper'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function candidate(int $branchId, Order $o, string $motif): array
    {
        return [
            'branch' => (string) $branchId,
            'order' => (string) ($o->order_serial_no ?? $o->id),
            'seq' => (string) (int) $o->fiscal_sequence_no,
            'total' => number_format((float) $o->total, 2),
            'created_at' => (string) $o->created_at,
            'motif' => $motif,
        ];
    }
}
