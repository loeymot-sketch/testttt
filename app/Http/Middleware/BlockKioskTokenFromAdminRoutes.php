<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [GOAL-J2-HEAL-02 2026-05-24] Phase J-ADV-6 PATH-1 RED P0 closer.
 *
 * EMPIRICALLY VERIFIED FINDING (see
 * reports/test-e2e/goal-2026-05-23/phase-j/J-ADV-6-trust-escalation-findings.json):
 *
 *   A Sanctum token with ONLY the ['kiosk:order'] ability successfully
 *   reached /api/admin/pos-order (200 with {data:[]} payload) because
 *   Spatie's `permission:*` middleware checks Auth::user()->can() rather
 *   than $token->can() / tokenCan(). When the KioskMachine is bound to
 *   the admin user (default Le Cayenne config — see
 *   database/seeders/KioskMachineTableSeeder.php:47 and
 *   app/Console/Commands/EnsureKioskMachineCommand.php:53), the
 *   kiosk-issued token silently inherits ALL admin Spatie permissions
 *   AND the BranchScope userBranch===0 cross-branch read bypass.
 *
 * Practical attack window:
 *   - Kiosk borne sits in a public space (PCI scope).
 *   - Sanctum token lives in localStorage / device storage.
 *   - Physical access, dev-tools leak, or network sniffing exposes it.
 *   - Attacker now has FULL ADMIN privileges via /api/admin/*.
 *
 * Defense in this middleware (Layer 1):
 *   If the current Sanctum access token carries ONLY the kiosk:order
 *   ability (i.e. NOT a wildcard '*' admin token AND no other abilities
 *   beyond kiosk:order), refuse access to /api/admin/* with 403 and a
 *   structured error code. This is ability-aware: it does not depend on
 *   the underlying user's Spatie permissions or branch_id — both of
 *   which are the very things the bug abuses.
 *
 * Defense Layer 2 (separate refactor, owner countersign pending — see
 * proposals/PROPOSAL_KIOSK_DEDICATED_USER_REFACTOR.md): re-bind the
 * KioskMachine to a dedicated kiosk_kayenne_borne_1 user with NO Spatie
 * roles, so even if Layer 1 were ever removed the kiosk token would
 * carry no admin permissions to inherit.
 *
 * Discipline:
 *   - Session-based admin auth (no Sanctum access token) is UNAFFECTED.
 *   - Wildcard '*' admin tokens are UNAFFECTED.
 *   - Tokens with kiosk:order + any other non-wildcard ability are
 *     also blocked (defense in depth — kiosk-class tokens have no
 *     legitimate business on /api/admin/*).
 *   - This middleware is ADDITIVE; it never modifies existing flow.
 *
 * Sentinel: tests/Feature/Security/KioskTokenAdminBlockSentinelTest.php
 * locks the contract: kiosk-only token MUST 403, wildcard token MUST
 * 200 (or 422 validation, not 401/403 authz).
 */
class BlockKioskTokenFromAdminRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Session-based admin auth or unauthenticated request — out of
        // scope (the underlying route's `auth:sanctum` + `permission:*`
        // stack will decide). We only care about Sanctum-token requests.
        if (! $user || ! method_exists($user, 'currentAccessToken')) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if (! $token) {
            return $next($request);
        }

        // TransientToken (session auth) returns true for everything — no
        // ability scoping to enforce. Pass through.
        if ($token instanceof \Laravel\Sanctum\TransientToken) {
            return $next($request);
        }

        // The real `PersonalAccessToken::can($ability)` is the only
        // contract-stable way to read abilities — direct `->abilities`
        // is null on Mockery-backed test tokens (Sanctum::actingAs uses
        // ->shouldIgnoreMissing(false), so property access is unstable).
        // We use the public can() API instead.
        if (! method_exists($token, 'can')) {
            return $next($request);
        }

        // Probe kiosk:order first — if the token does NOT carry it, it
        // is not in scope of J-ADV-6 PATH-1 and we pass through cleanly.
        // We swallow any Mockery NoMatchingExpectationException as
        // "ability not granted" — in production this codepath uses the
        // real PersonalAccessToken which never throws here.
        try {
            $hasKioskAbility = (bool) $token->can('kiosk:order');
        } catch (\Throwable $e) {
            $hasKioskAbility = false;
        }

        if (! $hasKioskAbility) {
            return $next($request);
        }

        // Token carries kiosk:order. Determine whether it is ALSO a
        // wildcard admin token: real tokens with abilities=['*'] return
        // true for can('*'). Tokens with abilities=['kiosk:order']
        // return false (and Mockery-backed test mocks throw — which we
        // treat as "no wildcard", matching the production semantics).
        try {
            $hasWildcard = (bool) $token->can('*');
        } catch (\Throwable $e) {
            $hasWildcard = false;
        }

        if ($hasWildcard) {
            return $next($request);
        }

        // Kiosk-class token with no wildcard escape hatch. Refuse with a
        // structured error code so frontends can surface a friendly
        // message and so log analysis can grep token_ability_insufficient.
        return response()->json([
            'message' => 'Kiosk-scoped tokens cannot access admin routes.',
            'error'   => 'token_ability_insufficient',
        ], Response::HTTP_FORBIDDEN);
    }
}
