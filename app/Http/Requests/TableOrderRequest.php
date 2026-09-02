<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Http\Requests\Concerns\NormalizesAdvanceOrder;
use App\Http\Requests\Concerns\ValidatesAddonRoles;
use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use App\Rules\ValidJsonOrder;
use Illuminate\Foundation\Http\FormRequest;
use Smartisan\Settings\Facades\Settings;

class TableOrderRequest extends FormRequest
{
    use NormalizesAdvanceOrder;
    use ValidatesAddonRoles;
    use ValidatesOrderItemVariations;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAdvanceOrder();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'dining_table_id' => ['required', 'numeric'],
            'customer_id' => ['required', 'numeric'],
            'branch_id' => ['required', 'numeric'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            // [P6] Symmetry with OrderRequest/PosOrderRequest — reject bogus negative amounts at QR table.
            'total' => ['required', 'numeric', 'min:0'],
            'order_type' => ['required', 'numeric'],
            'is_advance_order' => ['required', 'numeric'],
            'address_id' => ['nullable'],
            'delivery_time' => ['nullable'],
            'coupon_id' => ['nullable', 'numeric'],
            'source' => ['required', 'numeric'],
            'token' => ['nullable', 'string'],
            'items' => ['required', 'json', new ValidJsonOrder],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (request('order_type') == OrderType::DELIVERY && Settings::group('order_setup')->get('order_setup_delivery') == Activity::DISABLE) {
                $validator->errors()->add('order_type', trans('all.message.type_de_commande_desactive'));
            } elseif (request('order_type') == OrderType::TAKEAWAY && Settings::group('order_setup')->get('order_setup_takeaway') == Activity::DISABLE) {
                $validator->errors()->add('order_type', trans('all.message.type_de_commande_desactive'));
            } elseif (blank(request('order_type'))) {
                $validator->errors()->add('order_type', trans('all.message.type_de_commande_desactive'));
            }

            $this->validateOrderItemVariationsAfter($validator);
            // [SELF-AUDIT R5 P2 2026-07-05 — snapshot NF525 sous-facturé] Le chemin table-order OMETTAIT
            // la validation des rôles d'addon (menu_*) que OrderRequest/PosOrderRequest appliquent → un
            // rôle d'addon forgé passait, sous-facturait et désynchronisait le composition_snapshot fiscal.
            $this->validateAddonRolesAfter($validator);
        });
    }
}
