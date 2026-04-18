<?php

namespace Tests\Feature\Routes;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MenuControllerRateLimitTest extends TestCase
{
    public function test_menu_uses_kiosk_menu_rate_limit(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === 'api/frontend/menu' && in_array('GET', $candidate->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains('throttle:kiosk-menu', $route->gatherMiddleware());
    }
}
