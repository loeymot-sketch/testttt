<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // [SEC-FIX] Use config() not env() — env() returns null after php artisan config:cache
        $validApiKey = config('app.api_key');

        // [SEC-APIKEY-TIMING heal 2026-06-14 — ultra-review] Constant-time compare.
        // `===` on strings short-circuits at the first differing byte, leaking the
        // shared API key one byte at a time via response timing. hash_equals compares
        // in time independent of the match position. Guard the null/empty-config case
        // (hash_equals requires two strings) so a misconfigured key never grants access.
        if ($request->hasHeader('x-api-key') && is_string($validApiKey) && $validApiKey !== '') {
            if (hash_equals($validApiKey, (string) $request->header('x-api-key'))) {
                return $next($request);
            }
        }
        return response()->json(trans('all.message.invalid_api_key'), 400);
    }
}
