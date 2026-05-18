<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] FormRequest for closing a delivery boy cash session.
 *
 * Mirrors `CashDrawerSessionController::close()` body shape :
 *   { closing_amount: float >= 0 }
 *
 * Authz handled at route level (`permission:delivery-boys`).
 */
class DeliveryBoyCashSessionCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,array<int,mixed>>
     */
    public function rules(): array
    {
        return [
            'closing_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
