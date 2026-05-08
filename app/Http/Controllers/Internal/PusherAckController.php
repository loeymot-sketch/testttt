<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\PusherEventAck;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * [PRE-PROD HARDENING / SYN-2 / P0-7]
 *
 * Receives client→server acks for Pusher envelopes. Each ack is a
 * single row in `pusher_event_acks`, indexed on (correlation_id,
 * branch_id+received_at, received_at). The companion service
 * `PusherDeliveryRatioMonitor` consumes the table to compute a sliding
 * 5-minute "delivery ratio" used by the scheduled monitor command.
 *
 * Trust model:
 *
 *  - The endpoint is mounted under `auth:sanctum`. Anyone with a valid
 *    bearer token can ack — that includes admins, kiosks, KDS, OSS, and
 *    POS operators. We do NOT verify the user is actually subscribed to
 *    the channel: the cost would be a Pusher round-trip per ack, which
 *    defeats the purpose. Spoofed acks at most inflate the ratio (false
 *    health), they cannot DROP it, so the worst case is "miss an
 *    incident", not "fabricate one".
 *
 *  - Validation is deliberately permissive on payload shape (max
 *    lengths only). We never trust the values for read-after-write —
 *    they feed an aggregate count, not a foreign-key lookup.
 *
 *  - All errors are swallowed and turned into 204 No Content. The
 *    client must NEVER block on an ack failure: a busy POS that fails
 *    to ack a single OrderCreated envelope must still process the
 *    order. Errors land in the `debug` log channel so we can investigate
 *    after the fact without firing pages.
 */
class PusherAckController extends Controller
{
    public function store(Request $request): Response
    {
        // Validate strictly enough to keep the index columns sane, but
        // not enough to reject "good faith" envelopes with quirks.
        $validated = $request->validate([
            'correlation_id'  => 'required|string|max:100',
            'branch_id'       => 'nullable|integer',
            'channel'         => 'required|string|max:100',
            'event_name'      => 'required|string|max:100',
            'received_at'     => 'required|date',
            'domain_event_id' => 'nullable|integer',
        ]);

        try {
            PusherEventAck::create([
                'correlation_id'  => $validated['correlation_id'],
                'branch_id'       => $validated['branch_id'] ?? null,
                'channel'         => $validated['channel'],
                'event_name'      => $validated['event_name'],
                'received_at'     => $validated['received_at'],
                'domain_event_id' => $validated['domain_event_id'] ?? null,
                'client_user_id'  => Auth::id(),
                'client_ip'       => $request->ip(),
                'user_agent'      => mb_substr((string) $request->userAgent(), 0, 500),
                // Insert-only table: created_at filled here because the
                // model has $timestamps = false (the migration only
                // declares created_at, no updated_at).
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail — never block the client on ack errors.
            Log::debug('[PusherAck] write failed', [
                'error'          => $e->getMessage(),
                'correlation_id' => $validated['correlation_id'] ?? null,
            ]);
        }

        return response('', 204);
    }
}
