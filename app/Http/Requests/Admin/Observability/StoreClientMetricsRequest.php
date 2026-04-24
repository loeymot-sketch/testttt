<?php

namespace App\Http\Requests\Admin\Observability;

use App\Services\Observability\SyncMetricsRecorder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * [NEW-04] Validates batched client telemetry uploads.
 *
 * - max:200 caps the per-request cardinality (matches MetricsBatcher
 *   maxBatch default in resources/js/services/MetricsBatcher.js).
 * - The metric type whitelist is sourced from SyncMetricsRecorder so a
 *   single SSOT controls what the public surface accepts.
 * - value:max:600000 caps any duration metric to 10 minutes — prevents
 *   accidental Number.MAX_SAFE_INTEGER payloads from a buggy client.
 */
class StoreClientMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'metrics' => ['required', 'array', 'max:200'],
            'metrics.*.type' => ['required', 'string', Rule::in(SyncMetricsRecorder::ALLOWED_CLIENT_METRICS)],
            'metrics.*.value' => ['required', 'integer', 'min:0', 'max:600000'],
            'metrics.*.labels' => ['sometimes', 'array'],
            'metrics.*.occurred_at' => ['required', 'date'],
        ];
    }
}
