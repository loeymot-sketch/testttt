<?php

namespace App\Http\Requests\Kds;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
 *
 * Authorises the chef's "Annuler bump" action. Same role gate as
 * KdsOrderStatusRequest because the recall surface lives on the KDS board
 * and is bumped/un-bumped by the same operators.
 *
 * Body is intentionally empty for V1 — the order is resolved from the route
 * binding {order} and the 60s TTL is checked in the service under lock.
 * A future iteration could accept an optional `reason_note` text field but
 * the V1 mandate is "annuler bump rapide" with no manual reason.
 */
class KdsOrderRecallRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        $user = auth()->user();

        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['Admin', 'Branch Manager', 'Chef', 'POS Operator', 'Cashier']);
    }

    public function rules(): array
    {
        return [];
    }
}
