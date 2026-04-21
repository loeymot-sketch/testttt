<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $printerId = $this->route('printer')?->id;
        $branchId = $this->resolvedBranchId();

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('printers', 'name')
                    ->where(fn ($query) => $query->where('branch_id', $branchId))
                    ->ignore($printerId),
            ],
            'type' => ['required', 'string', Rule::in(['escpos_tcp', 'escpos_usb', 'browser_html'])],
            'host' => [
                Rule::requiredIf(fn () => $this->input('type', 'escpos_tcp') === 'escpos_tcp'),
                'nullable',
                'string',
                'max:64',
            ],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'station' => ['nullable', 'string', Rule::in(['receipt', 'kitchen_hot', 'kitchen_cold', 'bar'])],
            'width_chars' => ['nullable', 'integer', Rule::in([32, 48])],
            'status' => ['nullable', 'integer', Rule::in([0, 1])],
            'options' => ['nullable', 'array'],
        ];
    }

    private function resolvedBranchId(): int
    {
        $userBranchId = (int) ($this->user()?->branch_id ?? 0);

        if ($userBranchId > 0) {
            return $userBranchId;
        }

        return (int) ($this->input('branch_id') ?: $this->route('printer')?->branch_id ?: 0);
    }
}
