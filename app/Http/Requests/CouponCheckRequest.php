<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponCheckRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'code'  => ['required', 'string', 'max:20'],
            // [P8] Symmetry with P5–P7 — cart/order totals used for coupon eligibility must not be negative.
            'total' => ['required', 'numeric', 'min:0'],
            // [CV6 P0 fix — wire isUsableNow] surface + branch context to enforce
            // advanced promo scopes (branch_scope, surfaces, days, hours).
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'surface'   => ['nullable', 'string', Rule::in(['pos', 'kiosk', 'web'])],
        ];
    }
}
