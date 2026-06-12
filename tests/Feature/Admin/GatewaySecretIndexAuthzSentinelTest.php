<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\PaymentGatewayController;
use App\Http\Controllers\Admin\SmsGatewayController;
use App\Services\PaymentGatewayService;
use App\Services\SmsGatewayService;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN 2026-06-01 — SET-01 sentinel] Gateway secret leakage.
 *
 * The mgmt-breadth adversarial audit (workflow wf6dhhn09) found that
 * PaymentGatewayController + SmsGatewayController declared
 * `$this->middleware(['permission:settings'])->only('update')`, leaving the
 * `index` method (GET /admin/setting/payment-gateway, /admin/setting/sms-gateway)
 * reachable by ANY auth:sanctum staff holder (Branch Manager / POS Operator /
 * Chef — none holds `settings`). `GatewayOptionsResource` returns each option's
 * `value` unconditionally, so the live response includes secret-typed options
 * (stripe_secret, paypal_client_secret, twilio_auth_token, nexmo_secret, ...).
 *
 * Heal: gate `index` too — `->only('index', 'update')` — mirroring the existing
 * SET-02 fix on MailController + the proven KioskSetup/LoyaltySetup pattern, and
 * the PermissionControllerIndexAuthzTest precedent. Gating index does not break
 * any non-settings surface (the settings component is the sole consumer of these
 * reads). Sentinel asserts the `permission:settings` guard is NOT scoped to a
 * subset of methods that excludes `index`.
 *
 * @group sentinel
 * @group security
 */
class GatewaySecretIndexAuthzSentinelTest extends TestCase
{
    public function test_payment_gateway_controller_gates_index_with_settings(): void
    {
        $this->assertIndexGatedBySettings(
            new PaymentGatewayController($this->app->make(PaymentGatewayService::class)),
            'PaymentGatewayController'
        );
    }

    public function test_sms_gateway_controller_gates_index_with_settings(): void
    {
        $this->assertIndexGatedBySettings(
            new SmsGatewayController($this->app->make(SmsGatewayService::class)),
            'SmsGatewayController'
        );
    }

    /**
     * [HEAL dispute-r1 B-R1-19 2026-06-12] Sentinel invariant RESTATED (not
     * weakened):
     *  1. `index` MUST be gated by a permission middleware whose permission
     *     set INCLUDES `settings` (an OR-combination like
     *     `permission:settings|transactions` is allowed — the secret VALUES
     *     are stripped at the resource level for non-settings callers, see
     *     PaymentGatewayResource + behavioral test
     *     PaymentGatewayIndexBranchManagerAccessTest).
     *  2. `update` MUST be gated by a STRICT `permission:settings` middleware
     *     (no OR-widening of the write gate, ever).
     */
    private function assertIndexGatedBySettings(object $controller, string $name): void
    {
        $middleware = $controller->getMiddleware();
        $indexGated = false;
        $updateStrictlyGated = false;

        foreach ($middleware as $entry) {
            $mw = $entry['middleware'] ?? null;
            $mwString = is_array($mw) ? implode(',', $mw) : (string) $mw;
            if (! str_contains($mwString, 'permission:')) {
                continue;
            }

            $only = (array) ($entry['options']['only'] ?? []);
            $coversIndex = empty($only) || in_array('index', $only, true);
            $coversUpdate = empty($only) || in_array('update', $only, true);

            // Permission set of this entry, e.g. 'permission:settings|transactions'.
            $perms = [];
            if (preg_match('/permission:([a-z0-9_.|-]+)/i', $mwString, $m)) {
                $perms = explode('|', $m[1]);
            }

            if ($coversIndex && in_array('settings', $perms, true)) {
                $indexGated = true;
            }
            if ($coversUpdate && $perms === ['settings']) {
                $updateStrictlyGated = true;
            }
        }

        $this->assertTrue(
            $indexGated,
            "{$name} must gate `index` with a permission middleware including `settings` "
            . '(SET-01 secret leak — secrets reachable by unprivileged staff).'
        );
        $this->assertTrue(
            $updateStrictlyGated,
            "{$name} must gate `update` with a STRICT `permission:settings` middleware "
            . '(write gate must never be OR-widened).'
        );
    }
}
