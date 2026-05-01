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
                'phone' => null,
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
