<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // [SECURITY FIX P0-002] Only authenticated users with proper permissions can change order status
        if (!auth()->check()) {
            return false;
        }
        
        // Check if user has admin, manager, or kitchen role
        $user = auth()->user();
        return $user->hasAnyRole(['Admin', 'Branch Manager', 'Chef', 'POS Operator']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {

        return [
            'status' => ['required', 'numeric'],
        ];
    }
}
