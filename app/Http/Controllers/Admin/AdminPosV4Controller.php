<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Analytic;
use App\Models\ThemeSetting;

/**
 * [POS-V4 W2 #1 2026-04-26] Dedicated controller for the parallel POS V4 entry.
 *
 * Mirrors RootController@index but returns the slim `admin-pos-v4` Blade view
 * (which loads `pos-app.js` instead of `app.js`).
 *
 * Auth is enforced client-side by `pos-app.js` (router guard reads token from
 * localStorage and redirects to /login on miss). This controller has NO auth
 * middleware — the Blade is publicly servable, but `pos-app.js` will bounce
 * the user immediately if no valid token is present. Same pattern as
 * RootController for parity with the legacy SPA.
 *
 * Rollback: delete this file + revert routes/web.php.
 */
class AdminPosV4Controller extends Controller
{
    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $analytics = Analytic::with('analyticSections')->where(['status' => Status::ACTIVE])->get();
        $themeFavicon = ThemeSetting::where(['key' => 'theme_favicon_logo'])->first();
        $favIcon = $themeFavicon?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png');

        return view('admin-pos-v4', ['analytics' => $analytics, 'favicon' => $favIcon]);
    }
}
