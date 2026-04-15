<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $correlationId = $request->header('X-Correlation-ID') ?: (string) Str::uuid();
        $request->headers->set('X-Correlation-ID', $correlationId);

        Log::withContext([
            'correlation_id' => $correlationId,
            'user_id' => auth()->id(),
            'branch_id' => auth()->user()?->branch_id ?? null,
        ]);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
