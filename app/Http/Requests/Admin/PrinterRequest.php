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
            // [GOAL-L2-HEAL-03 2026-05-24] L7.2 L7-2-F-01 P1 — SafeRemoteHost
            // defends SSRF / internal-VPC port-scan via fsockopen() in
            // app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:24.
            // Admin (and POS-Operator with permission:pos via testPrint) can
            // no longer point printer host at 127.0.0.1, 169.254.169.254
            // (cloud metadata), or arbitrary RFC1918 internal IPs unless
            // SAFE_REMOTE_HOST_ALLOWLIST explicitly allowlists the subnet.
            // Hostnames pass through (DNS-rebind residual risk documented
            // in SafeRemoteHost docblock — V1.0.2 follow-up).
            //
            // [OWNER DECISION 2026-08-13 — option (b) "allowlist fermée"] The
            // rule runs in PORT-AWARE mode here: the allowlist entry that
            // unlocks an internal host must also cover the port, so
            // allowlisting the local print bridge (127.0.0.1:9100-9101) does
            // NOT re-open the fsockopen() port-scan oracle on the other 65533
            // ports of the box. defaultPort mirrors TcpPrinterTransport::send()
            // which dials 9100 when `port` is left blank.
            'host' => [
                Rule::requiredIf(fn () => $this->input('type', 'escpos_tcp') === 'escpos_tcp'),
                'nullable',
                'string',
                'max:64',
                new \App\Rules\SafeRemoteHost(portField: 'port', defaultPort: 9100),
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
