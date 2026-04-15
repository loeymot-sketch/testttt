<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HasCorrelationId
{
    public string $correlationId = '';

    public function initializeCorrelationId(): void
    {
        if (empty($this->correlationId)) {
            $shared = Log::sharedContext();
            $this->correlationId = is_array($shared) && isset($shared['correlation_id'])
                ? (string) $shared['correlation_id']
                : (request()?->header('X-Correlation-ID') ?? (string) Str::uuid());
        }
    }

    protected function restoreCorrelationContext(): void
    {
        Log::withContext(['correlation_id' => $this->correlationId]);
    }
}
