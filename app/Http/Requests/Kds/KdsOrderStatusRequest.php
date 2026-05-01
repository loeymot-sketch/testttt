<?php

namespace App\Http\Requests\Kds;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KdsOrderStatusRequest extends FormRequest
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
        return [
            'status' => ['required', 'integer', Rule::in(self::kdsStatuses())],
            'expected_status' => ['required', 'integer', Rule::in(self::kdsStatuses())],
        ];
    }

    /**
     * @return int[]
     */
    public static function kdsStatuses(): array
    {
        return [
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
            OrderStatus::PREPARED,
        ];
    }
}
