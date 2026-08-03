<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier loyalty redeem request.
 *
 * Validation rules :
 *   - points         : required positive integer, max 100_000 (defense)
 *   - loyalty_code   : required, alphanumeric+ E.164 phone tolerant
 *
 * Authz (LOCK §6.1) :
 *   - cashier must have Spatie permission `pos.redeem-loyalty`.
 *   - Permission is seeded by PermissionTableSeeder (see seeder update in
 *     this commit) and auto-bound to POS Operator + Branch Manager + Admin
 *     via RolePermissionTableSeeder.
 */
class PosLoyaltyRedeemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos.redeem-loyalty') ?? false;
    }

    public function rules(): array
    {
        return [
            'points'       => ['required', 'integer', 'min:1', 'max:100000'],
            'loyalty_code' => ['required', 'string', 'min:4', 'max:25', 'regex:/^[A-Z0-9\+\s\-]+$/i'],
        ];
    }
}
