<?php

namespace App\Http\Middleware;

use App\Support\WizardPerItemDemo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWizardPerItemDemoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! WizardPerItemDemo::enabled($request)) {
            abort(404);
        }

        return $next($request);
    }
}
