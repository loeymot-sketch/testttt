<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoyaltySetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'loyalty_points_per_euro'            => ['required', 'integer', 'min:0', 'max:1000'],
            'loyalty_points_for_1_euro_discount' => ['required', 'integer', 'min:1', 'max:10000'],
            'loyalty_min_redeem_points'          => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }
}
