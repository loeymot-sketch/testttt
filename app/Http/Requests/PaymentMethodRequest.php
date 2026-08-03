<?php

namespace App\Http\Requests;

use App\Services\PaymentService;
use Illuminate\Foundation\Http\FormRequest;

class PaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! app(PaymentService::class)->isPilotPaymentMethodAllowed((string) $value)) {
                        $fail('This payment method is not available in the restricted payment pilot.');
                    }
                },
            ],
        ];
    }
}
