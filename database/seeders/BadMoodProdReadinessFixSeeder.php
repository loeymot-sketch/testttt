<?php

namespace Database\Seeders;

use App\Enums\Activity;
use App\Enums\GatewayMode;
use App\Enums\InputType;
use App\Models\GatewayOption;
use App\Models\PaymentGateway;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Bad-Mood AUDIT-5 production readiness fix (2026-05-25).
 *
 * AUDIT-5 (reports/audits/BAD-MOOD-AUDIT-5-PROD-READY.json) caught two
 * deployment-state P0 blockers on the working DB:
 *
 *  - SPOF-05 : Spatie roles missing (only Admin in the DB). Cashier
 *    users (POS Operator) and Branch Manager could not be provisioned;
 *    stress command + cashier login both failed.
 *
 *  - SPOF-04 : `payment_gateways` table empty. Stripe gateway
 *    constructor (`app/Http/PaymentGateways/Gateways/Stripe.php:36`)
 *    pulled `null` for the row, then dereferenced
 *    `paymentGatewayOption['stripe_secret']`, throwing TypeError on
 *    first card payment.
 *
 * This seeder is FULLY IDEMPOTENT: it uses `firstOrCreate` on the role
 * keys + `where(slug=...)->exists()` guards on the gateway rows. Safe
 * to call repeatedly across migrations / soft-resets.
 *
 * NOT in the Kiosk role: kiosk machines authenticate via Sanctum
 * tokens with the `kiosk:order` ability (see config/sanctum.php +
 * `App\Http\Controllers\KioskMachineController::login`). There is no
 * Spatie role named "Kiosk" anywhere in the codebase (verified by
 * grep against `app/`, `database/seeders/`, `config/`). The AUDIT-5
 * mention of a Kiosk role is a spec artifact — token-based auth is
 * the canonical kiosk authorization path.
 */
class BadMoodProdReadinessFixSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMissingSpatieRoles();
        $this->seedPaymentGatewaysIfEmpty();
    }

    private function seedMissingSpatieRoles(): void
    {
        // The canonical RoleTableSeeder uses bare `Role::insert(...)` which
        // duplicates rows on re-run. firstOrCreate keys on (name, guard_name)
        // so we converge to one row per role without touching existing data.
        $roles = [
            'Admin',           // bootstrap admin — usually already seeded
            'Customer',
            'Delivery Boy',
            'Waiter',
            'Chef',
            'Branch Manager', // P0 — needed for fiscal close, variance override
            'POS Operator',   // P0 — needed for cashier login + stress fixture
            'Stuff',
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'sanctum',
            ]);
        }
    }

    private function seedPaymentGatewaysIfEmpty(): void
    {
        // Idempotency: if any gateway already exists, the deployment has
        // already been seeded via PaymentGatewayTableSeederVersionOne and
        // we should NOT touch the table (it may carry live secrets in prod).
        if (PaymentGateway::count() > 0) {
            return;
        }

        DB::transaction(function () {
            // Cash On Delivery — V1 LOCAL Le Cayenne primary payment method.
            PaymentGateway::create([
                'name' => 'Cash On Delivery',
                'slug' => 'cash-on-delivery',
                'misc' => null,
                'status' => Activity::ENABLE,
            ]);

            // Credit — secondary internal payment.
            PaymentGateway::create([
                'name' => 'Credit',
                'slug' => 'credit',
                'misc' => null,
                'status' => Activity::ENABLE,
            ]);

            // Stripe — DISABLED by default. V1 LOCAL is cash-only; this row
            // exists ONLY so the gateway class constructor does not crash
            // when admin UI inspects gateway configuration. Owner must
            // explicitly flip status=ENABLE and supply live keys before
            // accepting card payments.
            $stripeMisc = json_encode([
                'input' => ['stripe.stripeInput.blade.php'],
                'js' => ['stripe.stripeJs.blade.php'],
                'submit' => true,
            ]);
            $stripe = PaymentGateway::create([
                'name' => 'Stripe',
                'slug' => 'stripe',
                'misc' => $stripeMisc,
                'status' => Activity::DISABLE,
            ]);

            $stripeOptions = [
                [
                    'option' => 'stripe_key',
                    'value' => env('STRIPE_KEY', 'pk_test_PLACEHOLDER_V1_LOCAL_NOT_LIVE'),
                    'type' => InputType::TEXT,
                    'activities' => json_encode(''),
                ],
                [
                    'option' => 'stripe_secret',
                    'value' => env('STRIPE_SECRET', 'sk_test_PLACEHOLDER_V1_LOCAL_NOT_LIVE'),
                    'type' => InputType::TEXT,
                    'activities' => json_encode(''),
                ],
                [
                    'option' => 'stripe_mode',
                    'value' => (string) GatewayMode::SANDBOX,
                    'type' => InputType::SELECT,
                    'activities' => json_encode([
                        GatewayMode::SANDBOX => 'sandbox',
                        GatewayMode::LIVE => 'live',
                    ]),
                ],
                [
                    'option' => 'stripe_status',
                    'value' => (string) Activity::DISABLE,
                    'type' => InputType::SELECT,
                    'activities' => json_encode([
                        Activity::ENABLE => 'enable',
                        Activity::DISABLE => 'disable',
                    ]),
                ],
            ];

            foreach ($stripeOptions as $opt) {
                GatewayOption::create([
                    'model_id' => $stripe->id,
                    'model_type' => 'App\\Models\\PaymentGateway',
                    'option' => $opt['option'],
                    'value' => $opt['value'],
                    'type' => $opt['type'],
                    'activities' => $opt['activities'],
                ]);
            }
        });
    }
}
