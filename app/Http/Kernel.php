<?php

namespace App\Http;

use App\Http\Middleware\ApiKeyMiddleware;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\CorrelationIdMiddleware::class,
            // [RED-R2 §1 P2] CSP via HTTP header (replaces <meta http-equiv>).
            // Mode piloté par env CSP_ENFORCE_MODE — défaut report_only.
            \App\Http\Middleware\ContentSecurityPolicyHeader::class,
        ],

        'api' => [
            // \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            // [AUDIT-P1] Use named limiter: 60/min keyed by user ID (auth) or IP (guest).
            // Prevents a single IP from saturating the API while keeping normal usage comfortable.
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\JsonMiddleware::class,
            \App\Http\Middleware\CorrelationIdMiddleware::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \App\Http\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'apiKey' => ApiKeyMiddleware::class,
        'verify.api' => \App\Http\Middleware\VerifyEmail::class,
        'role' => \Spatie\Permission\Middlewares\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middlewares\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middlewares\RoleOrPermissionMiddleware::class,
        'localization' => \App\Http\Middleware\localization::class,
        'installed' => \App\Http\Middleware\Installed::class,
        // [T08b / K-6.1] Register Sanctum ability middleware aliases so routes
        // can use `abilities:kiosk:order` / `ability:kiosk:order` without the
        // full FQCN. Mirrors Sanctum's documented usage and aligns with the
        // testttt-kiosk-p93 reference worktree.
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
        // [C4 / K-8] Validates `X-Kiosk-Locale` / `?lang=` against
        // `Branch.available_locales` of the authenticated kiosk machine.
        // Returns 400 LOCALE_NOT_ALLOWED_FOR_BRANCH when the requested
        // locale is outside the branch allowlist. Aligned with the
        // testttt-kiosk-p93 reference worktree.
        'kiosk.locale' => \App\Http\Middleware\ValidateKioskLocale::class,
        'wizard.per_item_demo' => \App\Http\Middleware\EnsureWizardPerItemDemoEnabled::class,
        'wizard.per_item_profile_guard' => \App\Http\Middleware\EnsureProfileNotItemOwnedUnlessDemoEnabled::class,
        // [F-VERIFY-09-02 / PLAN_P11] HTTP-level idempotency guard. Opt-in
        // per-route via routes/api.php; flag-gated via IDEMPOTENCY_MIDDLEWARE_ENABLED.
        'idempotency' => \App\Http\Middleware\IdempotencyKeyMiddleware::class,
    ];
}
