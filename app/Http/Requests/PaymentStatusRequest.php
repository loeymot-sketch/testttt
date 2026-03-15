<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }
        return auth()->user()->hasAnyRole(['Admin', 'Branch Manager', 'POS Operator']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        return [
            'payment_status' => ['required', 'numeric'],
        ];
    }
}
