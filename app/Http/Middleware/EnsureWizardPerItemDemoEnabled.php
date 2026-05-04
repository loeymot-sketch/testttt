<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWizardPerItemDemoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('catalog_v15.features.wizard_per_item_demo.enabled', false)) {
            abort(404);
        }

        return $next($request);
    }
}
