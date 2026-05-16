<?php

namespace App\Services\Pos;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Throwable;

class WalkInCustomerResolver
{
    public const EMAIL = 'walkingcustomer@example.com';

    public function resolve(): User
    {
        $customer = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Client Comptoir',
                'username' => 'client_comptoir',
                // [Sprint 2B / DEL-4] phone is now NOT NULL on users (see
                // 2026_05_16_140100_make_user_phone_required migration). The
                // walk-in / counter customer is a system-internal user — it
                // never receives a phone callback by design. We persist a
                // stable sentinel (`PENDING_WALKIN`) that:
                //   - satisfies the NOT NULL constraint
                //   - fails App\Rules\ValidPhone (non-digit prefix), so any
                //     accidental DELIVERY flow attempting to reuse this
                //     account is rejected with a phone-validation error
                //   - is never matched by a real phone lookup
                'phone' => 'PENDING_WALKIN',
                'password' => Hash::make('123456'),
                'status' => Status::ACTIVE,
                'country_code' => '+33',
                'branch_id' => 0,
            ]
        );

        $this->ensureCustomerRole($customer);

        return $customer;
    }

    private function ensureCustomerRole(User $customer): void
    {
        if (! method_exists($customer, 'hasRole') || ! method_exists($customer, 'assignRole')) {
            return;
        }

        try {
            if (! $customer->hasRole('Customer')) {
                $customer->assignRole('Customer');
            }
        } catch (Throwable) {
            // Role seeding is absent in a few narrow test/bootstrap contexts.
        }
    }
}
