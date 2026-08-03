<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KioskMachineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — KioskMachineController middleware enforces
     * `permission:settings` on show/store/update/destroy/logout/changeStatus. KioskMachine
     * pairing creates sanctum tokens with `kiosk:order` ability — never permit without
     * the settings gate. FormRequest doubles down so any future route bypass still
     * authz-checks.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'machine_id' => ['required', 'string', 'max:190', Rule::unique('kiosk_machines', 'machine_id')->ignore($this->route('kioskMachine.id'))],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'username' => ['required', 'string', 'max:190', Rule::unique('kiosk_machines', 'username')->ignore($this->route('kioskMachine.id'))],
            'password' => [
                $this->route('kioskMachine.id') ? 'nullable' : 'required',
                'string',
                'min:6',
            ],
            'status' => ['required', 'numeric', 'max:24'],
        ];
    }

    public function messages(): array
    {
        return [
            'machine_id.required' => 'The machine field is required',
            'branch_id.required' => 'The branch field is required',
            'user_id.required' => 'The user field is required',
        ];
    }
}
