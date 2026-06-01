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

    private function assertIndexGatedBySettings(object $controller, string $name): void
    {
        $middleware = $controller->getMiddleware();
        $found = false;

        foreach ($middleware as $entry) {
            $mw = $entry['middleware'] ?? null;
            $isPermissionSettings = $mw === 'permission:settings'
                || (is_string($mw) && str_contains($mw, 'permission:settings'))
                || (is_array($mw) && in_array('permission:settings', $mw, true));
            if (! $isPermissionSettings) {
                continue;
            }
            $found = true;

            $only = $entry['options']['only'] ?? null;
            $this->assertTrue(
                empty($only) || in_array('index', (array) $only, true),
                "{$name}.middleware(permission:settings) must gate `index` (SET-01 secret leak). "
                . 'Currently only=[' . implode(',', (array) $only) . '] — secrets reachable by non-settings staff.'
            );
        }

        $this->assertTrue(
            $found,
            "{$name} must declare middleware `permission:settings` gating index + update."
        );
    }
}
