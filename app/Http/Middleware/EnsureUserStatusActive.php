<?php

namespace App\Http\Middleware;

use App\Enums\Status;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [Sprint H1 Z6-06 2026-05-17] Per-request user status revalidation.
 *
 * Sanctum personal access tokens are independent of `users.status`. Pre-
 * patch, an admin disabling a user (setting status != ACTIVE) didn't
 * revoke any issued tokens — they remained valid for the full TTL
 * (480min by sanctum.expiration), giving up to 8h propagation delay for
 * a security-critical revocation.
 *
 * Owner Gate G4 chose Option A (every-request middleware) over periodic-
 * cache (5-min TTL) or TTL-reduction (15min Sanctum window) for the
 * cleanest semantics: an admin disabling a user takes effect on that
 * user's next request, full stop.
 *
 * Discipline:
 *   - Skip if no $request->user() (anonymous routes — natural passthrough).
 *   - Defensive: skip if resolved auth model is not App\Models\User
 *     (future-proofs against alternative auth guards even though kiosk
 *     auth currently mints a User row).
 *   - On status != ACTIVE: delete currentAccessToken AND return 401.
 *   - Webhook / machine-to-machine routes are exempt by route placement
 *     (this middleware lives in the `api` group only; routes outside
 *     that group — payment callbacks in routes/web.php, e.g. — are
 *     naturally untouched).
 *
 * Ordering: registered in the `api` middleware group AFTER auth runs.
 * The accompanying Kernel.php local $middlewarePriority array ensures
 * AuthenticatesRequests resolves before us — Laravel's default would
 * otherwise array_unshift an unknown middleware to position 0.
 *
 * CLAUDE.md §9. Pairs with H1.1 (Z6-02 ability-scope tightening) and
 * H1.2 (Z6-05 mass-assignment strip) in the V1.0.1 hardening sprint.
 */
class EnsureUserStatusActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Anonymous request — no token to revalidate, no status to check.
        if (! $user) {
            return $next($request);
        }

        // Defensive: only re-check status for App\Models\User. Other auth
        // guards (e.g. session-based admin auth that returns a different
        // model, or future API-key auth) carry their own validation.
        if (! ($user instanceof User)) {
            return $next($request);
        }

        // Read status directly from DB — Sanctum caches the resolved
        // `tokenable` User via Eloquent's relation/identity map within
        // the request lifecycle, so $user->status may carry a stale
        // value if the DB row was updated between requests (the very
        // scenario this middleware exists to detect). A single
        // single-column SELECT is cheaper than `$user->refresh()`
        // (which reloads every column) and immune to any future model
        // accessors/mutators that mask the raw column.
        $statusFresh = \DB::table('users')->where('id', $user->id)->value('status');

        if ((int) $statusFresh !== (int) Status::ACTIVE) {
            // Revoke the presented token so it cannot replay if the user
            // is briefly re-enabled. Defensive try/catch: a session-based
            // auth (TransientToken) has no `delete()` semantics.
            try {
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }
            } catch (\Throwable $e) {
                // Swallow — non-Sanctum-token contexts shouldn't crash
                // the security gate. The 401 below is the authoritative
                // user-facing signal.
            }

            return response()->json([
                'message' => 'User account inactive',
            ], 401);
        }

        return $next($request);
    }
}
