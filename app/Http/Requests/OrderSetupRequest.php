<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderSetupRequest extends FormRequest
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
            // [P10] All values are durations, activity codes (≥0), or money/distances — none may be negative.
            'order_setup_food_preparation_time'        => ['required', 'numeric', 'min:0'],
            'order_setup_schedule_order_slot_duration' => ['required', 'numeric', 'min:0'],
            'order_setup_takeaway'                     => ['required', 'numeric', 'min:0'],
            'order_setup_delivery'                     => ['required', 'numeric', 'min:0'],
            'order_setup_free_delivery_kilometer'      => ['required', 'numeric', 'min:0'],
            'order_setup_basic_delivery_charge'        => ['required', 'numeric', 'min:0'],
            'order_setup_charge_per_kilo'              => ['required', 'numeric', 'min:0'],
        ];
    }
}