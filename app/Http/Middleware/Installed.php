<?php

namespace App\Http\Middleware;

use Closure;

class Installed
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!file_exists(storage_path('installed'))) {
            // API (SPA / axios) attend du JSON — une redirection HTML casse login & guest.
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Application not installed.',
                    'errors'  => ['installer' => ['Complete setup at /install before using the API.']],
                ], 503);
            }

            return redirect('/install');
        }

        return $next($request);
    }
}
