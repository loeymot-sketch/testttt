<?php

namespace App\Models;

/**
 * In-app “customer” accounts live in `users` with the Customer role.
 * This model targets the same table for NFC loyalty lookups without duplicating schema.
 */
class Customer extends User
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'branch_id',
        'country_code',
        'is_guest',
        'status',
        'email_verified_at',
        'nfc_uid',
    ];
}
