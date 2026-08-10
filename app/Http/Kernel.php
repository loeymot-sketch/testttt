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
        // [Wave 2c P1 SYNC-ADV3B-01 2026-05-18] Defense vs Host spoof —
        // TrustProxies::$proxies='*' (Wave 2b) trusts X-Forwarded-Host /
        // X-Forwarded-Proto from any upstream. TrustHosts pins host to
        // APP_URL subdomains + 127.0.0.1 + localhost to prevent URL
        // generation poisoning. No-op under runningUnitTests() / local.
        \App\Http\Middleware\TrustHosts::class,
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
            // [Sprint H1 Z6-06 2026-05-17] Per-request user status revalidation.
            // MUST run AFTER auth:sanctum so $request->user() is populated.
            // See local $middlewarePriority below — it inserts this entry just
            // after AuthenticatesRequests in the sort order. CLAUDE.md §9.
            \App\Http\Middleware\EnsureUserStatusActive::class,
        ],
    ];

    /**
     * The priority-sorted list of middleware.
     *
     * Forces the listed middleware into this order regardless of their
     * group/route order. Laravel's parent default lists
     * AuthenticatesRequests before SubstituteBindings; we extend it to
     * place EnsureUserStatusActive AFTER AuthenticatesRequests so
     * $request->user() is populated when our status gate runs.
     *
     * Without this explicit override, Kernel::sortMiddleware() would
     * `array_unshift` EnsureUserStatusActive to position 0 (line 410 of
     * Foundation\Http\Kernel) — running it before Sanctum resolves the
     * user, causing every authenticated request to bypass our gate.
     *
     * [Sprint H1 Z6-06 2026-05-17]
     *
     * @var array<int, class-string>
     */
    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \App\Http\Middleware\EnsureUserStatusActive::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        // Porte des écrans de la roue : session web habilitée OU code de la maison.
        'wheel.access' => \App\Http\Middleware\EnsureWheelAccess::class,
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
        // [P0 PAIEMENT EN LIGNE 2026-08-07] À poser JUSTE AVANT `idempotency` sur toute
        // route portant une commande : donne au garde gelé une branche dérivée du serveur
        // (la commande de la route) au lieu de dépendre d'une convention que l'appelant
        // doit se souvenir d'honorer dans son corps de requête. Voir la classe pour le
        // détail : un compte client porte branch_id=0 et faisait échouer le garde en 422.
        'idempotency.branch' => \App\Http\Middleware\ResolveIdempotencyBranchFromRoute::class,
        // [GOAL-J2-HEAL-02 2026-05-24] Phase J-ADV-6 PATH-1 RED P0 closer.
        // Blocks Sanctum tokens carrying the kiosk:order ability from reaching
        // /api/admin/* routes regardless of the underlying user's Spatie
        // permissions. Applied to both admin route groups in routes/api.php.
        // See BlockKioskTokenFromAdminRoutes::handle() for full rationale.
        'block_kiosk_token_admin' => \App\Http\Middleware\BlockKioskTokenFromAdminRoutes::class,

        // [TERRAIN-HEAL 2026-07-16 · KIOSK-PROFILE-ESCALATION P1 + siblings] Bloque le token de MACHINE
        // borne (name='kiosk-token') sur les endpoints PERSONNELS d'un utilisateur (profil, transactions,
        // messages, adresses, fidélité…) — empêche une borne de lire/modifier les données du user support
        // auquel elle est rattachée (P1 profil=hijack ; siblings=fuite PII/adresse). Clients (auth_token) OK.
        'block_kiosk_machine' => \App\Http\Middleware\BlockKioskMachineToken::class,
    ];
}
