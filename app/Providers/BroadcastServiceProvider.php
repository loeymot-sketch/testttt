<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // [GAP-34-1] Use auth:sanctum middleware for the broadcast auth endpoint.
        // Default Broadcast::routes() uses the 'web' middleware (session-based).
        // This SPA uses Sanctum Bearer tokens — the auth endpoint must accept them.
        // Route: POST /api/broadcasting/auth (matches Echo authEndpoint in bootstrap.js)
        // prefix 'api' ensures the URL is /api/broadcasting/auth (consistent with SPA API base).
        Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']]);

        require base_path('routes/channels.php');
    }
}
