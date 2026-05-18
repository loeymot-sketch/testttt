<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] FormRequest for reconciling a delivery boy cash session.
 *
 * Mirrors `CashDrawerSessionController::reconcile()` body shape :
 *   { variance_reason: string max 255, notes: string max 255 (optional) }
 *
 * Authz handled at route level (`permission:delivery-boys`).
 *
 * NOTE — manager-approval gate (variance > threshold) is enforced by the
 * service layer ; this request only validates payload shape.
 */
class DeliveryBoyCashSessionReconcileRequest extends FormRequest
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
            'variance_reason' => ['nullable', 'string', 'max:255'],
            'notes'           => ['nullable', 'string', 'max:255'],
        ];
    }
}
