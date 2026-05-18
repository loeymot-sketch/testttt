<?php

namespace App\Http\Requests;

use App\Enums\Role as EnumRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * [V1.0.2 Sub-6.3 BUILD-1 — 2026-05-18] FormRequest for opening a delivery boy cash session.
 *
 * Validation invariants :
 *   - delivery_boy_id MUST exist on users + bear the DELIVERY_BOY role (asserted in
 *     `withValidator` to avoid coupling Rule::exists scopes to BranchScope).
 *   - opening_amount >= 0 (service throws 422 if negative, but we catch upstream).
 *
 * Authz : the route-level `permission:delivery-boys` middleware guards the action.
 * We return `true` here to defer the authz contract to that middleware, which mirrors
 * the existing `DeliveryBoyRequest` pattern (line 17-20).
 */
class DeliveryBoyCashSessionOpenRequest extends FormRequest
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
            'delivery_boy_id' => ['required', 'integer', 'min:1'],
            'opening_amount'  => ['required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $deliveryBoyId = (int) $this->input('delivery_boy_id', 0);
            if ($deliveryBoyId <= 0) {
                return;
            }

            // BranchScope-bypass : the admin may be branch_id=0 (global) while the
            // livreur lives on branch N — fetch without scope and assert role.
            $user = User::query()->withoutGlobalScopes()->find($deliveryBoyId);
            if (! $user) {
                $validator->errors()->add('delivery_boy_id', 'Delivery boy not found.');
                return;
            }

            if (! $user->hasRole(EnumRole::DELIVERY_BOY) && ! $user->hasRole('Delivery Boy')) {
                $validator->errors()->add('delivery_boy_id', 'Target user is not a delivery boy.');
                return;
            }

            if ((int) $user->branch_id <= 0) {
                $validator->errors()->add('delivery_boy_id', 'Delivery boy has no branch assignment.');
            }
        });
    }
}
