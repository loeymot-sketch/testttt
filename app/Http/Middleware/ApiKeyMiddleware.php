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

        if ($request->hasHeader('x-api-key')) {
            if ($request->header('x-api-key') === $validApiKey) {
                return $next($request);
            }
        }
        return response()->json(trans('all.message.invalid_api_key'), 400);
    }
}
