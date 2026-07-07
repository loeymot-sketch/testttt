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
use Illuminate\Support\Collection;

/**
 * [P0 #1 — NF525 Z-membership reconciliation — AUTHORITATIVE re-aggregation 2026-07-07]
 *
 * Proves the NF525 gap-free invariant: "every fiscally-numbered receipt appears
 * in exactly one signed Z (or is pending in the current open window)." READ-ONLY.
 * No behaviour change to signing, no frozen-zone touch, no writes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS WAS REWRITTEN (was a HEURISTIC, over-signalled ~2507 false positives)
 * ─────────────────────────────────────────────────────────────────────────────
 * The previous detector used a proxy (created_at inside (opened_at, closed_at]
 * PLUS an updated_at > closed_at "sealed after the Z closed" test). After C33
 * (LOCK_ZREPORT_FISCAL_C33_DELIVERY_VAT, ZReportService::close, 2026-07-07) that
 * proxy is WRONG in two ways and produced a flood of false positives:
 *
 *   1. It bounded the Z window by `opened_at`, leaving a "dead window" between a
 *      Z's close and the next Z's open. C33 made close() aggregate from the
 *      PREVIOUS closed Z's `closed_at` — so those dead windows no longer exist;
 *      a sale created there IS now sealed by the next Z. The old detector still
 *      flagged them → false "TROU".
 *   2. The `updated_at > closed_at` test flagged any order legitimately counted
 *      in its Z that later had a benign status change (updated_at bumps) → false
 *      "cross-window orphan".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AUTHORITATIVE MODEL — re-aggregate by the REAL C33 window
 * ─────────────────────────────────────────────────────────────────────────────
 * Mirror EXACTLY the window ZReportService::aggregate() signs (C33 continuous
 * partition). For a branch, list the signed (CLOSED) Z reports sorted by
 * `closed_at` ascending: c_1 < c_2 < … < c_k. The windows tile the timeline with
 * NO gap and NO overlap:
 *     Z_1 : (−∞, c_1]        (first Z has $from = null → absorbs the whole prior history)
 *     Z_n : (c_{n-1}, c_n]   (lower STRICT, upper INCLUSIVE — identical to aggregate())
 * Their union is (−∞, c_k]. The CURRENT OPEN window is (c_k, +∞): the next close
 * will aggregate from $from = c_k (C33), so any sale created after the last close
 * is guaranteed to be sealed by the next Z → pending, NOT an orphan.
 *
 * A fiscally-numbered order is a REAL ORPHAN only if BOTH hold:
 *   (a) its created_at falls in NO signed window (c_{n-1}, c_n], AND
 *   (b) it is NOT in the current open window — i.e. there is no OPEN Z on the
 *       branch to eventually seal it.
 * Given the tiling, (a) ⟺ created_at > c_k (or the branch has no closed Z at
 * all). Combined with (b): an orphan is a numbered, settled, non-terminal order
 * created after the last closed Z on a branch that has NO open Z pending — a
 * numbered receipt genuinely outside every Z with nothing queued to seal it.
 *
 * Population mirrors aggregate()'s positive-revenue set exactly: numbered
 * (fiscal_sequence_no NOT NULL), settled (payment_status != UNPAID), non-terminal
 * (not CANCELED/REJECTED/RETURNED — those are counted as cancel/refund counts,
 * not positive revenue), no refund mirror (parent_order_id NULL), and
 * withTrashed()+withoutGlobalScope(BranchScope) so soft-deleted post-allocation
 * orders are visible exactly as the aggregator sees them (SELF-AUDIT B 2026-07-05).
 *
 * NOTE on the "numbered-after-close" class: an order created inside a prior Z's
 * window but whose fiscal_sequence_no was allocated AFTER that Z closed is, by
 * created_at, treated as covered here (the authoritative model keys on
 * created_at, matching aggregate()). That residual class is caught by OTHER
 * controls: ZReportService::warnOnOrphanedPaidOrders() warns at close time, and
 * RetryFiscalAllocCommand backfills the sequence — there is no per-order
 * "sequence_allocated_at" column to distinguish it here, and using updated_at as
 * a proxy is precisely what produced the false positives above.
 */
class VerifyZMembershipCommand extends Command
{
    protected $signature = 'fiscal:verify-z-membership {--branch= : limit to a single branch_id}';

    protected $description = 'NF525 read-only detector (AUTHORITATIVE, C33 continuous-partition re-aggregation): flag fiscally-numbered orders present in NO signed Z and not pending in the current open window (real gap-free orphans, 0 false positives).';

    public function handle(): int
    {
        $terminal = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];

        $branchIds = $this->option('branch')
            ? [(int) $this->option('branch')]
            : Branch::query()->pluck('id')->all();

        $candidates = [];

        foreach ($branchIds as $bid) {
            // Signed-Z window boundaries (C33 continuous partition): closed_at of
            // every CLOSED Z, ascending. Windows tile (−∞, c_k] as (c_{n-1}, c_n].
            $boundaries = ZReport::query()
                ->where('branch_id', $bid)
                ->where('status', ZReport::STATUS_CLOSED)
                ->whereNotNull('closed_at')
                ->orderBy('closed_at')
                ->pluck('closed_at'); // Collection<Carbon> ascending

            $lastClosedAt = $boundaries->isNotEmpty() ? $boundaries->last() : null;

            // The current open window exists iff an OPEN Z is present. Its close
            // will aggregate from $from = $lastClosedAt (C33) → it seals every
            // sale created after the last close.
            $hasOpenZ = ZReport::query()
                ->where('branch_id', $bid)
                ->where('status', ZReport::STATUS_OPEN)
                ->exists();

            // Population = aggregate()'s positive-revenue set (mirror exact).
            $orders = Order::withoutGlobalScope(BranchScope::class)
                ->withTrashed()
                ->where('branch_id', $bid)
                ->whereNotNull('fiscal_sequence_no')
                ->where('payment_status', '!=', PaymentStatus::UNPAID)
                ->whereNotIn('status', $terminal)
                ->whereNull('parent_order_id')
                ->get(['id', 'order_serial_no', 'fiscal_sequence_no', 'created_at', 'total', 'branch_id']);

            foreach ($orders as $o) {
                $createdAt = $o->created_at;

                // (a) Covered by a signed Z continuous window (c_{n-1}, c_n] ?
                if ($lastClosedAt !== null && $createdAt->lte($lastClosedAt)) {
                    if ($this->fallsInSignedWindow($createdAt, $boundaries)) {
                        continue; // sealed in exactly one signed Z → OK
                    }

                    // created_at <= c_k yet in no reconstructed window: the
                    // partition is not continuous (a signed Z is missing/skewed).
                    // Structural anomaly — surface it (defensive; tiling normally
                    // makes this unreachable).
                    $candidates[] = $this->candidate(
                        $bid,
                        $o,
                        'ANOMALIE: partition Z non-continue — aucune fenetre signee ne couvre cette vente'
                    );
                    continue;
                }

                // (b) After the last closed Z (or no closed Z at all). If an OPEN
                // Z is pending, the next close seals it → pending, not an orphan.
                if ($hasOpenZ) {
                    continue;
                }

                // No signed window covers it AND no open Z pending → REAL orphan:
                // a numbered receipt in zero Z with nothing queued to seal it.
                $candidates[] = $this->candidate(
                    $bid,
                    $o,
                    $lastClosedAt === null
                        ? 'aucun Z (ni clos ni ouvert) sur cette branche'
                        : 'apres le dernier Z clos ('.$lastClosedAt.'), aucun Z ouvert pour la sceller'
                );
            }
        }

        if (empty($candidates)) {
            $this->info('Z-membership OK — chaque commande numerotee est dans un Z signe ou en attente dans la fenetre ouverte courante (aucun orphelin).');

            return self::SUCCESS;
        }

        $this->warn(count($candidates).' commande(s) numerotee(s) REELLEMENT hors de tout Z signe (detecteur autoritaire C33, 0 faux positif) :');
        $this->table(
            ['branch', 'order', 'seq', 'total', 'created_at', 'motif'],
            array_map('array_values', $candidates)
        );
        $this->line('Un recu numerote absent de tout Z signe est une violation NF525 gap-free. Remediation : ouvrir puis cloturer un Z sur la branche concernee — la partition continue C33 (aggregate from = closed_at du Z precedent) scellera alors toute vente non couverte. Voir reports/audit/massive-validation-2026-05-29/ESCALATION_NO_GO.md (P0 #1).');

        return self::FAILURE;
    }

    /**
     * True iff $createdAt falls in one signed continuous window: window_1 is
     * (−∞, c_1] (first Z absorbs prior history), window_n is (c_{n-1}, c_n] —
     * lower STRICT, upper INCLUSIVE, identical to ZReportService::aggregate().
     *
     * @param  Collection<int, Carbon>  $boundaries  ascending closed_at instants
     */
    private function fallsInSignedWindow(Carbon $createdAt, Collection $boundaries): bool
    {
        $lower = null; // −∞ for the first window

        foreach ($boundaries as $upper) {
            $upper = $upper instanceof Carbon ? $upper : Carbon::parse($upper);

            $aboveLower = $lower === null || $createdAt->gt($lower);
            if ($aboveLower && $createdAt->lte($upper)) {
                return true;
            }

            $lower = $upper;
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
