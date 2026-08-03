<?php

namespace App\Http\Requests\Admin;

use App\Models\PaymentTerminal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentTerminalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $terminalId = $this->route('payment_terminal')?->id;
        $branchId   = $this->resolvedBranchId();

        return [
            'branch_id'      => ['nullable', 'integer', 'exists:branches,id'],
            'name'           => [
                'required',
                'string',
                'max:100',
                Rule::unique('payment_terminals', 'name')
                    ->where(fn ($query) => $query->where('branch_id', $branchId))
                    ->ignore($terminalId),
            ],
            'gateway_type'   => ['required', 'string', Rule::in(PaymentTerminal::GATEWAY_TYPES)],
            // 5,3 decimal → max 99.999% (clipping at 100 prevents non-sensical setup)
            'fee_percent'    => ['nullable', 'numeric', 'min:0', 'max:99.999'],
            // 8,2 decimal → max 999999.99 but realistic ceiling is ~10€/transaction
            'fee_fixed'      => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'serial_number'  => ['nullable', 'string', 'max:100'],
            'status'         => [
                'nullable',
                'integer',
                Rule::in([PaymentTerminal::STATUS_ACTIVE, PaymentTerminal::STATUS_ARCHIVED]),
            ],
        ];
    }

    private function resolvedBranchId(): int
    {
        $userBranchId = (int) ($this->user()?->branch_id ?? 0);

        if ($userBranchId > 0) {
            return $userBranchId;
        }

        return (int) ($this->input('branch_id') ?: $this->route('payment_terminal')?->branch_id ?: 0);
    }
}
