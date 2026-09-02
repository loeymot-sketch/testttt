<?php

use App\Http\Controllers\Admin\AdministratorAddressController;
use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\AnalyticController;
use App\Http\Controllers\Admin\AnalyticSectionController;
use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\ChefAddressController;
use App\Http\Controllers\Admin\ChefController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\ComposerProfileController;
use App\Http\Controllers\Admin\ComposerStepController;
use App\Http\Controllers\Admin\CookiesController;
use App\Http\Controllers\Admin\CountryCodeController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CreditBalanceReportController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomerAddressController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DefaultAccessController;
use App\Http\Controllers\Admin\DeliveryBoyAddressController;
use App\Http\Controllers\Admin\DeliveryBoyCashSessionController;
use App\Http\Controllers\Admin\DeliveryBoyController;
use App\Http\Controllers\Admin\DeliveryBoyOrderController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\EmployeeAddressController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\IngredientController;
use App\Http\Controllers\Admin\ItemAddonController;
use App\Http\Controllers\Admin\ItemAttributeController;
use App\Http\Controllers\Admin\ItemCategoryController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\ItemExtraController;
use App\Http\Controllers\Admin\ItemPhotoController;
use App\Http\Controllers\Admin\ItemsReportController;
use App\Http\Controllers\Admin\ItemVariationController;
use App\Http\Controllers\Admin\KdsSyncController;
use App\Http\Controllers\Admin\KioskMachineController;
use App\Http\Controllers\Admin\KioskSetupController;
use App\Http\Controllers\Admin\KitchenDisplaySystemController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\LoyaltySetupController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\MenuProjectionController;
use App\Http\Controllers\Admin\MenuSectionController;
use App\Http\Controllers\Admin\MenuTemplateController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\MyOrderDetailsController;
use App\Http\Controllers\Admin\NotificationAlertController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\Observability\SyncOverviewController;
use App\Http\Controllers\Admin\Pilotage\InterrupteurController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OfferItemController;
use App\Http\Controllers\Admin\OnlineOrderController;
use App\Http\Controllers\Admin\OrderHistoryController;
use App\Http\Controllers\Admin\OrderSetupController;
use App\Http\Controllers\Admin\OrderStatusScreenController;
use App\Http\Controllers\Admin\OtpController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\PaymentGatewayController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\Pos\CashDrawerController;
use App\Http\Controllers\Admin\Pos\CashDrawerSessionController;
use App\Http\Controllers\Admin\Pos\CustomerNfcLookupController;
use App\Http\Controllers\Admin\Pos\FloorplanController;
use App\Http\Controllers\Admin\Pos\ParkedOrderController;
use App\Http\Controllers\Admin\Pos\PosReceiptPrintController;
use App\Http\Controllers\Admin\PosCategoryController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\PosOrderController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PushNotificationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalesReportController;
use App\Http\Controllers\Admin\SimpleUserController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Admin\SliderController;
use App\Http\Controllers\Admin\SmsGatewayController;
use App\Http\Controllers\Admin\SocialMediaController;
use App\Http\Controllers\Admin\PurchasingScanController;
use App\Http\Controllers\Admin\RawMaterialAdjustController;
use App\Http\Controllers\Admin\StockRuptureDashboardController;
use App\Http\Controllers\Admin\UnifiedStockViewController;
use App\Http\Controllers\Admin\SubscriberController;
use App\Http\Controllers\Admin\TableOrderController as AdminTableOrderController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\Admin\TimeSlotController;
use App\Http\Controllers\Admin\TimezoneController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\WaiterAddressController;
use App\Http\Controllers\Admin\WaiterController;
use App\Http\Controllers\Auth\DeactivateController;
use App\Http\Controllers\Auth\DeviceSessionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GuestSignupController;
use App\Http\Controllers\Auth\KioskMachineLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Frontend\AddressController as FrontendAddressController;
use App\Http\Controllers\Frontend\BranchController as FrontendBranchController;
use App\Http\Controllers\Frontend\CookiesController as FrontendCookiesController;
use App\Http\Controllers\Frontend\CountryCodeController as FrontendCountryCodeController;
use App\Http\Controllers\Frontend\CouponController as FrontendCouponController;
use App\Http\Controllers\Frontend\DeliveryBoyOrderController as FrontendDeliveryBoyOrderController;
use App\Http\Controllers\Frontend\ItemCategoryController as FrontendItemCategoryController;
use App\Http\Controllers\Frontend\ItemController as FrontendItemController;
use App\Http\Controllers\Frontend\LanguageController as FrontendLanguageController;
use App\Http\Controllers\Frontend\MessageController as FrontendMessageController;
use App\Http\Controllers\Frontend\OfferController as FrontendOfferController;
use App\Http\Controllers\Frontend\OrderController as FrontendOrderController;
use App\Http\Controllers\Frontend\PageController as FrontendPageController;
use App\Http\Controllers\Frontend\ProfileController;
use App\Http\Controllers\Frontend\SettingController;
use App\Http\Controllers\Frontend\SliderController as FrontendSliderController;
use App\Http\Controllers\Frontend\SubscriberController as FrontendSubscriberController;
use App\Http\Controllers\Frontend\TimeSlotController as FrontendTimeSlotController;
use App\Http\Controllers\Frontend\TokenStoreController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthzController;
use App\Http\Controllers\Table\DiningTableController as TableDiningTableController;
use App\Http\Controllers\Table\ItemCategoryController as TableItemCategoryController;
use App\Http\Controllers\Table\OrderController as TableOrderController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Health check endpoints (no auth required)
Route::get('/health', [HealthController::class, 'full']);
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

// [GAP-HUNT 2026-05-25 Phase A.1 / OPS-GATE-1] Public uptime probe.
// Compact JSON for UptimeRobot / Cronitor / Better Stack. Separate from
// /api/health because /healthz is owner-friendly contract (db|redis|ws|
// fiscal|queue_pending) and never exposes /api/health's verbose subsystems.
Route::get('/healthz', HealthzController::class);

Route::match(['get', 'post'], '/login', function () {
    return response()->json(['errors' => 'unauthenticated'], 401);
})->name('login');

// [AUDIT-P1] Added apiKey: token refresh must authenticate the client app, not be public.
Route::match(['get', 'post'], '/refresh-token', [RefreshTokenController::class, 'refreshToken'])->middleware(['installed', 'apiKey']);

// [UBER-EATS 2026-07-01] Webhook Uber Eats — PUBLIC (Uber n'a pas notre apiKey ; l'auth = signature
// HMAC-SHA256 vérifiée dans le controller). URL à enregistrer sur le dashboard Uber :
//   https://<domaine>/api/webhooks/uber
Route::post('/webhooks/uber', [\App\Http\Controllers\Webhook\UberWebhookController::class, 'handle'])
    ->middleware(['installed', 'throttle:60,1'])
    ->name('webhooks.uber');

// [W5 Mollie 2026-07-20] Webhook Mollie — PUBLIC (Mollie n'a pas notre apiKey ; modèle de
// sécurité Mollie documenté = le POST ne porte qu'un id, la vérité est re-fetchée via
// GET /v2/payments/{id} authentifié par NOTRE clé → un POST forgé ne marque jamais PAID).
// FAIL-CLOSED : 503 tant que payment.mollie (flag+clé) absent. URL à enregistrer côté Mollie :
//   https://<domaine>/api/webhook/mollie
Route::post('/webhook/mollie', [\App\Http\PaymentGateways\Gateways\Mollie::class, 'handleWebhook'])
    ->middleware(['installed', 'throttle:60,1'])
    ->name('webhooks.mollie');

Route::prefix('auth')->middleware(['installed', 'apiKey', 'localization'])->name('auth.')->namespace('Auth')->group(function () {
    // [SEC-02] Rate limiting — login lockout (named limiter)
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login-lockout');

    // [iter15-mega-fix D-001 2026-05-10] Dedicated kiosk-login limiter (30/min by
    // username|ip) replaces the human `login-lockout` (10/10min) — kiosk machines
    // self-retry on boot and during component lifecycle, the human cap was
    // self-DoSing legitimate bornes. See RouteServiceProvider::configureRateLimiting.
    Route::post('/kiosk-login', [KioskMachineLoginController::class, 'login'])
        ->middleware('throttle:kiosk-login');

    Route::prefix('forgot-password')->name('forgot-password.')->group(function () {
        // [SEC-02] Rate limiting — 3 tentatives par heure (anti-spam SMS)
        Route::post('/', [ForgotPasswordController::class, 'forgotPassword'])
            ->middleware('throttle:3,60');
        Route::post('/verify-code', [ForgotPasswordController::class, 'verifyCode'])
            ->middleware('throttle:5,1');
        Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword'])
            ->middleware('throttle:5,1');
    });

    Route::prefix('signup')->name('signup.')->group(function () {
        // [GAP-20-2] OTP send: 5/min (was 10) — limits SMS flood.
        Route::post('/otp', [SignupController::class, 'otp'])
            ->middleware('throttle:otp-send'); // [SEC MISSION-31] par-identifiant + plafond global (anti XFF-spoof)
        // [GAP-20-2] OTP verify: 3 per 5 minutes — anti brute-force.
        Route::post('/verify', [SignupController::class, 'verify'])
            ->middleware('throttle:3,5');
        Route::post('/register', [SignupController::class, 'register'])
            ->middleware('throttle:10,1');
    });

    Route::prefix('guest-signup')->name('guest-signup.')->group(function () {
        // [GAP-20-2] OTP send: 5 per minute (was 10) — limits SMS flood abuse.
        Route::post('/otp', [GuestSignupController::class, 'otp'])
            ->middleware('throttle:otp-send'); // [SEC MISSION-31] par-identifiant + plafond global (anti XFF-spoof)
        // [WAVE C EMAIL-OTP 2026-07-28] Canal EMAIL du code signup (mandat owner :
        // pas de SMS). Même throttle strict que /otp — endpoint public = vecteur
        // d'abus (spam email). Verify réutilise /verify ci-dessous, inchangé.
        Route::post('/email-otp', [GuestSignupController::class, 'emailOtp'])
            ->middleware('throttle:otp-send'); // [SEC MISSION-31] par-identifiant + plafond global (anti XFF-spoof)
        // [APP MOBILE 2026-09-02 — GOAL_APP_MOBILE_APPSTORE §A1] Connexion « e-mail d'abord » :
        // {email} → connu (code envoyé à l'e-mail du compte) / inconnu ; {email, first_name, phone}
        // → inscription. Même débit que les autres envois de code (endpoint public = vecteur d'abus).
        Route::post('/email-login', [GuestSignupController::class, 'emailLogin'])
            ->middleware('throttle:otp-send');
        // [GAP-20-2] OTP verify: 3 per 5 minutes — prevents brute-force of 4-digit codes.
        // A 4-digit OTP has 10,000 combinations; at 3 attempts/5min the attacker needs
        // ~2,778 hours to exhaust all codes, well beyond the 5-minute expiry window.
        Route::post('/verify', [GuestSignupController::class, 'verify'])
            ->middleware('throttle:3,5');
    });

    /**
     * [APPS 2026-08-19] Connexion Apple / Google des applications iOS et Android.
     *
     * Le corps ne porte qu'un jeton d'identité signé par le fournisseur ; TOUT est
     * revérifié côté serveur (signature RS256 contre le trousseau public, émetteur,
     * destinataire, expiration) — voir SocialIdentityVerifier. Un jeton décodé sans être
     * vérifié « marche » parfaitement en test et laisse prendre n'importe quel compte.
     *
     * Débit limité comme les autres portes d'authentification : l'endpoint est PUBLIC,
     * donc c'est aussi une surface d'abus (fabrication de jetons en rafale).
     */
    Route::prefix('social')->name('social.')->group(function () {
        Route::post('/{provider}', [\App\Http\Controllers\Auth\SocialAuthController::class, 'login'])
            ->whereIn('provider', ['apple', 'google'])
            ->middleware('throttle:10,1');
    });

    Route::middleware('auth:sanctum')->group(function () {
        // [A1 cycle 5 · GOAL_WEB_ADVERSARIAL 2026-08-05 · P1 SÉCURITÉ] `verify.api` (e-mail
        // vérifié) gardait la DÉCONNEXION alors que la CONNEXION ne l'exige pas : asymétrie
        // vérifiée sur `POST /api/auth/login`, qui n'a pas ce middleware. On pouvait donc se
        // connecter sans jamais pouvoir se déconnecter — 401 « Please verify your email », le
        // jeton CONSERVÉ, et le front qui avale l'échec silencieusement. Mesuré en base :
        // 58 jetons vivants appartiennent à des comptes non vérifiés.
        // Se déconnecter est un CONTRÔLE DE SÉCURITÉ : il doit fonctionner inconditionnellement
        // pour quiconque présente un jeton valide — refuser la révocation d'une session, c'est
        // refuser au client le seul moyen de reprendre la main sur son compte.
        // Le parcours web client n'était pas touché (GuestSignupController renseigne le champ) ;
        // le trou visait les jetons émis par LoginController (staff/admin).
        Route::post('/logout', [LoginController::class, 'logout']);
        Route::post('/kiosk-logout', [KioskMachineLoginController::class, 'logout']);

        // [MULTI-DEVICE 2026-08-07] Écran « Appareils connectés ». Même
        // raisonnement que /logout juste au-dessus : gérer ses propres
        // sessions est un CONTRÔLE DE SÉCURITÉ, il ne passe donc pas par
        // `verify.api` ni par une permission — sinon on retire à l'exploitant
        // le seul moyen de couper l'accès d'une tablette perdue.
        // `block_kiosk_machine` en revanche est requis : un jeton MACHINE de
        // borne ne doit pas pouvoir lister ni révoquer les sessions du compte
        // auquel il est rattaché (il pourrait éteindre la caisse).
        Route::middleware('block_kiosk_machine')->group(function () {
            /**
             * [APPS 2026-08-19] Complète un compte ouvert par connexion Apple/Google avec
             * son numéro de téléphone — l'exploitation doit pouvoir appeler le client.
             *
             * Dans ce groupe `block_kiosk_machine` par défense en profondeur : une BORNE
             * n'a aucun numéro personnel à déclarer. Le contrôleur refait le contrôle,
             * mais compter sur une seule garde pour une écriture sur le compte, c'est
             * accepter qu'un jour un déplacement de route l'emporte en silence.
             */
            Route::post('/social/phone', [\App\Http\Controllers\Auth\SocialAuthController::class, 'attacherTelephone'])
                ->middleware('throttle:10,1');

            Route::get('/devices', [DeviceSessionController::class, 'index']);
            Route::patch('/devices/{token}', [DeviceSessionController::class, 'update'])
                ->whereNumber('token');
            Route::delete('/devices/{token}', [DeviceSessionController::class, 'destroy'])
                ->whereNumber('token');
        });

        Route::middleware('verify.api')->group(function () {
            // La SUPPRESSION de compte reste derrière la vérification d'e-mail : c'est une
            // action destructrice et irréversible, pas un moyen de reprendre la main.
            Route::post('/delete-account', [DeactivateController::class, 'deleteAccount']);
        });
    });

    Route::post('/authcheck', function () {
        if (Auth::check()) {
            $user = Auth::user();
            $role = $user->roles[0] ?? null;
            if (! $role) {
                return response()->json(['status' => true]);
            }

            $menuService = app(\App\Services\MenuService::class);
            $permissionService = app(\App\Services\PermissionService::class);

            $permission = \App\Http\Resources\PermissionResource::collection($permissionService->permission($role));
            $menus = \App\Http\Resources\MenuResource::collection(collect($menuService->menu($role)));
            $defaultPermission = \App\Libraries\AppLibrary::defaultPermission($permission);
            $defaultMenu = (object) \App\Libraries\AppLibrary::defaultMenu($menuService->menu($role), $defaultPermission);

            // [BUG-AUTH FIX] Apply landing_url override — same logic as LoginController lines 82-85
            // Without this, POS Operator loses their correct redirect URL after a page refresh
            if (! empty($role->landing_url)) {
                $defaultPermission->url = $role->landing_url;
            }

            return response()->json([
                'status' => true,
                'token' => null,
                'branch_id' => (int) $user->branch_id,
                'user' => new \App\Http\Resources\UserResource($user),
                'menu' => $menus,
                'permission' => $permission,
                'defaultPermission' => $defaultPermission,
                'defaultMenu' => $defaultMenu,
            ]);
        }

        return response()->json(['status' => false]);
    });
});

/* all routes must be singular word */
Route::prefix('profile')->name('profile.')->middleware(['installed', 'apiKey', 'auth:sanctum', 'block_kiosk_machine', 'localization'])->group(function () {
    Route::get('/', [ProfileController::class, 'profile']);
    Route::match(['put', 'patch'], '/', [ProfileController::class, 'update']);
    Route::match(['put', 'patch'], '/change-password', [ProfileController::class, 'changePassword']);
    Route::post('/change-image', [ProfileController::class, 'changeImage']);
});

// Passerelle locale Free Pro/Asterisk : authentification HMAC dédiée, aucune
// session utilisateur et aucune filiale acceptée depuis le JSON.
Route::prefix('voice-order/gateway')->name('voiceOrder.gateway.')
    ->middleware(['installed', 'throttle:voice-order-gateway'])
    ->group(function () {
        Route::post('/events', [\App\Http\Controllers\VoiceOrderGatewayController::class, 'event'])->name('events');
        Route::post('/authorize-media', [\App\Http\Controllers\VoiceOrderGatewayController::class, 'authorizeMedia'])->name('authorizeMedia');
    });

// [BLUE 2026-05-08 / B3-S5 P1] Throttle séparé pour /menu/availability/toggle :
// pendant rush, caissier toggle item OOS + submit commande peut hitter 429
// self-DoS s'il partage le bucket admin-mutation (30/min) avec POST /admin/pos.
// On extrait UNIQUEMENT le toggle availability avec un bucket dédié 60/min.
// IMPORTANT : groupe sibling (PAS imbriqué) pour éviter le stacking — sinon
// Laravel additionne les middlewares throttle et la limite effective devient
// min(30, 60) = 30/min, ce qui ne résout PAS le self-DoS. Récurrent RED-R3
// → ORCHESTRATOR → B3.
// [GOAL Phase F.1 2026-05-23] Replaced hardcoded `throttle:60,1` with the named
// `menu-availability` limiter (defined in RouteServiceProvider). Same 60/min
// default for backwards compatibility, but now env-configurable
// (MENU_AVAILABILITY_RATE_LIMIT). Local dev raises to 1000/min to absorb
// bulk-86 fan-out from StockRuptureDashboard (manager toggling many items
// at once during rush). NF525 chain unaffected — no fiscal write here.
// [GOAL-J2-HEAL-02 2026-05-24] block_kiosk_token_admin inserted right after
// auth:sanctum (token user resolved) and BEFORE localization + throttle so a
// stolen kiosk token never pollutes the admin-mutation rate bucket. Closes
// J-ADV-6 PATH-1 RED P0 (empirically verified — see middleware docblock).
Route::prefix('admin')->name('admin.')->middleware(['installed', 'apiKey', 'auth:sanctum', 'block_kiosk_token_admin', 'localization', 'throttle:menu-availability'])->group(function () {
    Route::post('/menu/availability/toggle', [AvailabilityController::class, 'toggle'])
        ->name('menu.availability.toggle');

    // [F-016a-BIS] Branch-scoped manual rupture endpoints for extras and
    // variations. Same throttle bucket as the item toggle endpoint above
    // because cashiers/managers may chain multiple 86 actions during rush
    // and we don't want this to share the global admin-mutation bucket.
    Route::post('/menu/availability/extra/toggle', [AvailabilityController::class, 'toggleExtra'])
        ->name('menu.availability.extra.toggle');
    Route::post('/menu/availability/variation/toggle', [AvailabilityController::class, 'toggleVariation'])
        ->name('menu.availability.variation.toggle');
});

// [GOAL-J2-HEAL-02 2026-05-24] block_kiosk_token_admin — same rationale as the
// menu-availability group above. This is the BIG /api/admin/* group (POS,
// catalog, settings, customers, cash drawer, etc.) and is the primary blast
// radius of the J-ADV-6 PATH-1 RED P0 escalation. Order matters: after
// auth:sanctum so $request->user() and currentAccessToken() are populated;
// before localization so blocked kiosk requests don't consume admin-mutation
// throttle quota.
Route::prefix('admin')->name('admin.')->middleware(['installed', 'apiKey', 'auth:sanctum', 'block_kiosk_token_admin', 'localization', 'throttle:admin-mutation'])->group(function () {
    Route::prefix('voice-order')->name('voiceOrder.')
        ->middleware(['permission:pos', 'throttle:voice-order-admin'])
        ->group(function () {
            Route::get('/snapshot', [\App\Http\Controllers\Admin\VoiceOrderAssistantController::class, 'snapshot'])->name('snapshot');
            Route::get('/calls/{callId}', [\App\Http\Controllers\Admin\VoiceOrderAssistantController::class, 'show'])->name('show');
            Route::post('/calls/{callId}/consent', [\App\Http\Controllers\Admin\VoiceOrderAssistantController::class, 'consent'])->name('consent');
            Route::post('/calls/{callId}/extract', [\App\Http\Controllers\Admin\VoiceOrderAssistantController::class, 'extract'])->name('extract');
            Route::post('/calls/{callId}/link-order', [\App\Http\Controllers\Admin\VoiceOrderAssistantController::class, 'linkOrder'])->name('linkOrder');
        });

    Route::prefix('default-access')->name('default-access.')->group(function () {
        Route::get('/', [DefaultAccessController::class, 'index']);
        Route::post('/', [DefaultAccessController::class, 'storeOrUpdate']);
    });

    // [V1 SECTION 5] Dual-channel menu SSOT projection (read-only, admin-only).
    Route::get('/menu-projection', [MenuProjectionController::class, 'show'])
        ->name('menu-projection.show');
    // [CV1-V1-CLOSEOUT-001 T-DEEP-AVAIL-API-01] Endpoint admin pour AvailabilityService::setMaxDailyQty (M2 V2 task 2.5).
    Route::post('/menu/availability/max-daily-qty', [AvailabilityController::class, 'setMaxDailyQty'])
        ->name('menu.availability.max-daily-qty');

    // [F-016a-BIS] Read-only aggregate (items + extras + variations marked
    // unavailable on the branch). Powers the StockManager dashboard.
    Route::get('/menu/availability/branch/{branch}', [AvailabilityController::class, 'showBranchAvailability'])
        ->whereNumber('branch')
        ->name('menu.availability.branch.show');
    Route::get('/stock/scan-rupture/last-summary', [StockRuptureDashboardController::class, 'lastSummary'])
        ->name('stock.scan-rupture.last-summary');
    Route::get('/stock/low-alerts', [StockRuptureDashboardController::class, 'lowAlerts'])
        ->name('stock.low-alerts');
    // [Mission 1 — Stock-Rupture UI Simplification 2026-05-21]
    // Unified read endpoint powering the "Produits & Stock" admin page
    // (single SSOT view of categories + extras + variations with binary
    // per-branch availability). Bulk-query, no N+1.
    Route::get('/stock/catalog-overview', [StockRuptureDashboardController::class, 'catalogOverview'])
        ->name('stock.catalog-overview');
    Route::post('/stock/scan-rupture/run', [StockRuptureDashboardController::class, 'run'])
        ->name('stock.scan-rupture.run');
    // [PHASE 3d — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Lecture seule : matières
    // premières + boissons dans un seul tableau + section « à acheter ». Gate
    // items_show (écran de lecture, comme catalog-overview). ADDITIF, HORS NF525.
    Route::get('/stock/unified-overview', [UnifiedStockViewController::class, 'overview'])
        ->name('stock.unified-overview');

    // [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Scan facture IA → propositions
    // (crée un PurchaseDocument draft + PurchaseLine proposed ; l'owner valide
    // ensuite via PurchaseService). Domaine NEUF, ADDITIF, HORS NF525.
    Route::post('/purchasing/scan', [PurchasingScanController::class, 'scan'])
        ->name('purchasing.scan');
    // [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] Options cible pour l'écran (dropdowns).
    Route::get('/purchasing/targets', [PurchasingScanController::class, 'targets'])
        ->name('purchasing.targets');
    // [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3c] Validation owner : applique en
    // stock les propositions confirmées (proposed → validated → PurchaseService).
    // Même gate permission:items_create. Domaine NEUF, ADDITIF, HORS NF525.
    Route::post('/purchasing/{document}/validate', [PurchasingScanController::class, 'apply'])
        ->name('purchasing.validate');

    // [GOAL_CAYENNE_FINITION_2026-08-13 / §6 Vague 5] Ajustement inventaire manuel
    // (casse / vol / pesée fausse) — la seule porte d'écriture manquante du domaine
    // matière première (RawMaterialStockService::adjust() existait, testée, sans
    // appelant). `history` = lecture des derniers ajustements (gate items_show),
    // `adjust` = écriture (gate items_create, même famille que /purchasing/scan).
    // `idempotency` : protection double-submit HTTP (opt-in via X-Idempotency-Key ;
    // no-op si l'en-tête est absent ou si `idempotency.enabled` est false).
    Route::get('/raw-materials/{rawMaterial}/movements', [RawMaterialAdjustController::class, 'history'])
        ->name('raw-materials.movements');
    Route::post('/raw-materials/{rawMaterial}/adjust', [RawMaterialAdjustController::class, 'adjust'])
        ->middleware('idempotency')
        ->name('raw-materials.adjust');

    // [UBER-PHOTO 2026-08-10 · owner] Commande Uber PHOTOGRAPHIÉE sur la tablette → lecture →
    // aperçu cuisine → validation humaine → commande réelle (écran de cuisine, caisse, ticket
    // imprimé « UBER EATS » + nom du client). Le canal fonctionne SANS l'accès production Uber.
    // Porte : permission:pos-orders|pos, comme la liste « commandes en cours » de la caisse.
    // Domaine NEUF, ADDITIF, HORS NF525.
    //
    // [REMISES 2026-08-12] Ces 4 routes avaient été retirées le 2026-08-10 à 22h30 parce
    // qu'elles étaient parties SEULES en production, vers une classe que le serveur n'avait
    // pas (`route:list` en erreur, 500 sur tout appel authentifié). La condition posée alors
    // — « elles ne reviennent qu'avec leur contrôleur, leur modèle, leur fournisseur et leur
    // migration, dans le MÊME commit » — est remplie par ce commit. Une route ne vaut jamais
    // mieux que la classe qu'elle appelle : les deux voyagent désormais ensemble.
    Route::post('/uber/photo/scan', [\App\Http\Controllers\Admin\UberPhotoCaptureController::class, 'scan'])
        ->name('uber.photo.scan');
    Route::get('/uber/photo/recent', [\App\Http\Controllers\Admin\UberPhotoCaptureController::class, 'recent'])
        ->name('uber.photo.recent');
    Route::post('/uber/photo/{capture}/confirm', [\App\Http\Controllers\Admin\UberPhotoCaptureController::class, 'confirm'])
        ->whereNumber('capture')->name('uber.photo.confirm');
    Route::post('/uber/photo/{capture}/discard', [\App\Http\Controllers\Admin\UberPhotoCaptureController::class, 'discard'])
        ->whereNumber('capture')->name('uber.photo.discard');
    // [RÉIMPRESSION 2026-08-12 · owner] Ressort le ticket cuisine d'une commande déjà envoyée :
    // le papier se perd, et rephotographier créerait une SECONDE commande donc un second plat.
    Route::post('/uber/photo/{capture}/reprint', [\App\Http\Controllers\Admin\UberPhotoCaptureController::class, 'reprint'])
        ->whereNumber('capture')->name('uber.photo.reprint');

    Route::prefix('setting')->name('setting.')->group(function () {
        Route::prefix('company')->name('company.')->group(function () {
            Route::get('/', [CompanyController::class, 'index']);
            Route::match(['put', 'patch'], '/', [CompanyController::class, 'update']);
        });

        Route::prefix('site')->name('site.')->group(function () {
            Route::get('/', [SiteController::class, 'index']);
            Route::match(['put', 'patch'], '/', [SiteController::class, 'update']);
        });

        Route::prefix('order-setup')->name('order-setup.')->group(function () {
            Route::get('/', [OrderSetupController::class, 'index']);
            Route::match(['put', 'patch'], '/', [OrderSetupController::class, 'update']);
        });

        Route::prefix('kiosk-setup')->name('kiosk-setup.')->group(function () {
            Route::get('/', [KioskSetupController::class, 'index']);
            Route::match(['put', 'patch'], '/', [KioskSetupController::class, 'update']);
        });

        Route::prefix('loyalty-setup')->name('loyalty-setup.')->group(function () {
            Route::get('/', [LoyaltySetupController::class, 'index']);
            Route::match(['put', 'patch'], '/', [LoyaltySetupController::class, 'update']);
        });

        Route::prefix('mail')->name('mail.')->group(function () {
            Route::get('/', [MailController::class, 'index']);
            Route::match(['put', 'patch'], '/', [MailController::class, 'update']);
        });

        Route::prefix('currency')->name('currency.')->group(function () {
            Route::get('/', [CurrencyController::class, 'index']);
            Route::get('/show/{currency}', [CurrencyController::class, 'show']);
            Route::post('/', [CurrencyController::class, 'store']);
            Route::match(['put', 'patch'], '/{currency}', [CurrencyController::class, 'update']);
            Route::delete('/{currency}', [CurrencyController::class, 'destroy']);
        });

        Route::prefix('tax')->name('tax.')->group(function () {
            Route::get('/', [TaxController::class, 'index']);
            Route::get('/show/{tax}', [TaxController::class, 'show']);
            Route::post('/', [TaxController::class, 'store']);
            Route::match(['put', 'patch'], '/{tax}', [TaxController::class, 'update']);
            Route::delete('/{tax}', [TaxController::class, 'destroy']);
        });

        Route::prefix('item-category')->name('item-category.')->group(function () {
            Route::get('/', [ItemCategoryController::class, 'index']);
            Route::get('/export', [ItemCategoryController::class, 'export']);
            Route::get('/download-sample', [ItemCategoryController::class, 'downloadSample']);
            Route::post('/import/file', [ItemCategoryController::class, 'import']);
            Route::get('/show/{itemCategory}', [ItemCategoryController::class, 'show']);
            Route::post('/', [ItemCategoryController::class, 'store']);
            Route::match(['post', 'put', 'patch'], '/{itemCategory}', [ItemCategoryController::class, 'update']);
            Route::delete('/{itemCategory}', [ItemCategoryController::class, 'destroy']);
            Route::post('/sort/category', [ItemCategoryController::class, 'sortCategory']);
        });

        Route::prefix('item-attribute')->name('item-attribute.')->group(function () {
            Route::get('/', [ItemAttributeController::class, 'index']);
            Route::get('/show/{itemAttribute}', [ItemAttributeController::class, 'show']);
            Route::post('/', [ItemAttributeController::class, 'store']);
            Route::match(['put', 'patch'], '/{itemAttribute}', [ItemAttributeController::class, 'update']);
            Route::delete('/{itemAttribute}', [ItemAttributeController::class, 'destroy']);
        });

        Route::prefix('slider')->name('slider.')->group(function () {
            Route::get('/', [SliderController::class, 'index']);
            Route::get('/show/{slider}', [SliderController::class, 'show']);
            Route::post('/', [SliderController::class, 'store']);
            Route::match(['post', 'put', 'patch'], '/{slider}', [SliderController::class, 'update']);
            Route::delete('/{slider}', [SliderController::class, 'destroy']);
        });

        Route::prefix('branch')->name('branch.')->group(function () {
            Route::get('/', [BranchController::class, 'index']);
            Route::get('/show/{branch}', [BranchController::class, 'show']);
            Route::post('/', [BranchController::class, 'store']);
            Route::match(['put', 'patch'], '/{branch}', [BranchController::class, 'update']);
            Route::match(['put', 'patch'], '/zone/{branch}', [BranchController::class, 'updateZone']);
            Route::delete('/{branch}', [BranchController::class, 'destroy']);
            Route::get('/lat-long/{branch}', [BranchController::class, 'showByLatLong']);
        });

        Route::prefix('menu-section')->name('menu-section.')->group(function () {
            Route::get('/', [MenuSectionController::class, 'index']);
        });

        Route::prefix('menu-template')->name('menu-template.')->group(function () {
            Route::get('/', [MenuTemplateController::class, 'index']);
            Route::get('/show/{menuTemplate}', [MenuTemplateController::class, 'show']);
            Route::post('/', [MenuTemplateController::class, 'store']);
            Route::match(['put', 'patch'], '/{menuTemplate}', [MenuTemplateController::class, 'update']);
            Route::delete('/{menuTemplate}', [MenuTemplateController::class, 'destroy']);
        });

        Route::prefix('page')->name('page.')->group(function () {
            Route::get('/', [PageController::class, 'index']);
            Route::get('/show/{page}', [PageController::class, 'show']);
            Route::post('/', [PageController::class, 'store']);
            Route::match(['post', 'put', 'patch'], '/{page}', [PageController::class, 'update']);
            Route::delete('/{page}', [PageController::class, 'destroy']);
        });

        Route::prefix('license')->name('license.')->group(function () {
            Route::get('/', [LicenseController::class, 'index']);
            Route::match(['put', 'patch'], '/', [LicenseController::class, 'update']);
        });

        Route::prefix('theme')->name('theme.')->group(function () {
            Route::get('/', [ThemeController::class, 'index']);
            Route::post('/', [ThemeController::class, 'update']);
        });

        Route::prefix('sms-gateway')->name('sms-gateway.')->group(function () {
            Route::get('/', [SmsGatewayController::class, 'index']);
            Route::match(['put', 'patch'], '/', [SmsGatewayController::class, 'update']);
        });

        Route::prefix('payment-gateway')->name('payment-gateway.')->group(function () {
            Route::get('/', [PaymentGatewayController::class, 'index']);
            Route::match(['put', 'patch'], '/', [PaymentGatewayController::class, 'update']);
        });

        Route::prefix('notification')->name('notification.')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::post('/', [NotificationController::class, 'update']);
        });

        Route::prefix('social-media')->name('social-media.')->group(function () {
            Route::get('/', [SocialMediaController::class, 'index']);
            Route::match(['put', 'patch'], '/', [SocialMediaController::class, 'update']);
        });

        Route::prefix('analytic')->name('analytic.')->group(function () {
            Route::get('/', [AnalyticController::class, 'index']);
            Route::get('/show/{analytic}', [AnalyticController::class, 'show']);
            Route::post('/', [AnalyticController::class, 'store']);
            Route::match(['put', 'patch'], '/{analytic}', [AnalyticController::class, 'update']);
            Route::delete('/{analytic}', [AnalyticController::class, 'destroy']);
        });

        Route::prefix('analytic-section')->name('analytic-section.')->group(function () {
            Route::get('/{analytic}', [AnalyticSectionController::class, 'index']);
            Route::post('/{analytic}', [AnalyticSectionController::class, 'store']);
            Route::match(
                ['put', 'patch'],
                '/{analytic}/{analyticSection}',
                [AnalyticSectionController::class, 'update']
            );
            Route::delete('/{analytic}/{analyticSection}', [AnalyticSectionController::class, 'destroy']);
        });

        Route::prefix('otp')->name('otp.')->group(function () {
            Route::get('/', [OtpController::class, 'index']);
            Route::match(['put', 'patch'], '/', [OtpController::class, 'update']);
        });

        Route::prefix('role')->name('role.')->group(function () {
            Route::get('/', [RoleController::class, 'index']);
            Route::post('/', [RoleController::class, 'store']);
            Route::get('/show/{role}', [RoleController::class, 'show']);
            Route::match(['put', 'patch'], '/{role}', [RoleController::class, 'update']);
            Route::delete('/{role}', [RoleController::class, 'destroy']);
        });

        Route::prefix('permission')->name('permission.')->group(function () {
            Route::get('/{role}', [PermissionController::class, 'index']);
            Route::match(['put', 'patch'], '/{role}', [PermissionController::class, 'update']);
        });

        Route::prefix('cookies')->name('cookies.')->group(function () {
            Route::get('/', [CookiesController::class, 'index']);
            Route::match(['put', 'patch'], '/', [CookiesController::class, 'update']);
        });

        Route::prefix('time-slot')->name('time-slot.')->group(function () {
            Route::get('/', [TimeSlotController::class, 'index']);
            Route::post('/', [TimeSlotController::class, 'store']);
            Route::delete('/{timeSlot}', [TimeSlotController::class, 'destroy']);
        });

        Route::prefix('language')->name('language.')->group(function () {
            Route::get('/', [LanguageController::class, 'index']);
            Route::post('/', [LanguageController::class, 'store']);
            Route::get('/show/{language}', [LanguageController::class, 'show']);
            Route::match(['post', 'put', 'patch'], '/update/{language}', [LanguageController::class, 'update']);
            Route::delete('/{language}', [LanguageController::class, 'destroy']);

            Route::get('/file-list/{language:code}', [LanguageController::class, 'fileList']);
            Route::post('/file-text', [LanguageController::class, 'fileText']);
            Route::post('/file-text/store', [LanguageController::class, 'fileTextStore']);
        });

        Route::prefix('notification-alert')->name('notification-alert.')->group(function () {
            Route::get('/', [NotificationAlertController::class, 'index']);
            Route::match(['put', 'patch'], '/', [NotificationAlertController::class, 'update']);
        });

        Route::prefix('kiosk-machine')->name('kiosk-machine.')->group(function () {
            Route::get('/', [KioskMachineController::class, 'index']);
            Route::get('/show/{kioskMachine}', [KioskMachineController::class, 'show']);
            Route::post('/', [KioskMachineController::class, 'store']);
            Route::match(['put', 'patch'], '/{kioskMachine}', [KioskMachineController::class, 'update']);
            Route::post('/change-status/{kioskMachine}', [KioskMachineController::class, 'changeStatus']);
            Route::delete('/{kioskMachine}', [KioskMachineController::class, 'destroy']);
            Route::post('/logout/{kioskMachine}', [KioskMachineController::class, 'logout']);
        });
    });

    Route::prefix('subscriber')->name('subscriber.')->group(function () {
        Route::get('/', [SubscriberController::class, 'index']);
        Route::delete('/{subscriber}', [SubscriberController::class, 'destroy']);
        Route::get('/export', [SubscriberController::class, 'export']);
        Route::post('/send-email', [SubscriberController::class, 'sendEmail']);
    });

    Route::prefix('customer')->name('customer.')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('/show/{customer}', [CustomerController::class, 'show']);
        Route::match(['post', 'put', 'patch'], '/{customer}', [CustomerController::class, 'update']);
        Route::delete('/{customer}', [CustomerController::class, 'destroy']);

        Route::get('/export', [CustomerController::class, 'export']);
        Route::post('/change-password/{customer}', [CustomerController::class, 'changePassword']);
        Route::post('/change-image/{customer}', [CustomerController::class, 'changeImage']);

        Route::get('/my-order/{customer}', [CustomerController::class, 'myOrder']);

        Route::get('/address/{customer}', [CustomerAddressController::class, 'index']);
        Route::get('/address/show/{customer}/{address}', [CustomerAddressController::class, 'show']);
        Route::post('/address/{customer}', [CustomerAddressController::class, 'store']);
        Route::match(['put', 'patch'], '/address/{customer}/{address}', [CustomerAddressController::class, 'update']);
        Route::delete('/address/{customer}/{address}', [CustomerAddressController::class, 'destroy']);
    });

    Route::prefix('waiter')->name('waiter.')->group(function () {
        Route::get('/', [WaiterController::class, 'index']);
        Route::post('/', [WaiterController::class, 'store']);
        Route::get('/show/{waiter}', [WaiterController::class, 'show']);
        Route::match(['post', 'put', 'patch'], '/{waiter}', [WaiterController::class, 'update']);
        Route::delete('/{waiter}', [WaiterController::class, 'destroy']);

        Route::get('/export', [WaiterController::class, 'export']);
        Route::post('/change-password/{waiter}', [WaiterController::class, 'changePassword']);
        Route::post('/change-image/{waiter}', [WaiterController::class, 'changeImage']);

        Route::get('/my-order/{waiter}', [WaiterController::class, 'myOrder']);

        Route::get('/address/{waiter}', [WaiterAddressController::class, 'index']);
        Route::get('/address/show/{waiter}/{address}', [WaiterAddressController::class, 'show']);
        Route::post('/address/{waiter}', [WaiterAddressController::class, 'store']);
        Route::match(['put', 'patch'], '/address/{waiter}/{address}', [WaiterAddressController::class, 'update']);
        Route::delete('/address/{waiter}/{address}', [WaiterAddressController::class, 'destroy']);
    });

    Route::prefix('chef')->name('chef.')->group(function () {
        Route::get('/', [ChefController::class, 'index']);
        Route::post('/', [ChefController::class, 'store']);
        Route::get('/show/{chef}', [ChefController::class, 'show']);
        Route::match(['post', 'put', 'patch'], '/{chef}', [ChefController::class, 'update']);
        Route::delete('/{chef}', [ChefController::class, 'destroy']);

        Route::get('/export', [ChefController::class, 'export']);
        Route::post('/change-password/{chef}', [ChefController::class, 'changePassword']);
        Route::post('/change-image/{chef}', [ChefController::class, 'changeImage']);

        Route::get('/my-order/{chef}', [ChefController::class, 'myOrder']);

        Route::get('/address/{chef}', [ChefAddressController::class, 'index']);
        Route::get('/address/show/{chef}/{address}', [ChefAddressController::class, 'show']);
        Route::post('/address/{chef}', [ChefAddressController::class, 'store']);
        Route::match(['put', 'patch'], '/address/{chef}/{address}', [ChefAddressController::class, 'update']);
        Route::delete('/address/{chef}/{address}', [ChefAddressController::class, 'destroy']);
    });

    Route::prefix('my-order')->name('my-order.')->group(function () {
        // [Admin S-1 P0 IDOR heal — 2026-05-18] MyOrderDetailsController has
        // ZERO permission middleware at the controller level (unlike its 6
        // consumer SPA peers Customer/Waiter/DeliveryBoy/Chef/Administrator/
        // Employee, each of which gates `*_show`). Pre-heal, any authenticated
        // user who guessed a valid (user_id, order_id) pair could read the
        // full order payload (PII, addresses, payment). Apply alternation
        // OR-gate covering ALL 6 consumer SPA flows. Sentinel:
        // tests/Feature/Sentinels/MyOrderDetailsAuthzSentinelTest.php
        Route::get('/show/{user}/{order}', [MyOrderDetailsController::class, 'orderDetails'])
            ->middleware('permission:customers_show|waiters_show|delivery-boys_show|chefs_show|administrators_show|employees_show');
    });

    Route::prefix('employee')->name('employee.')->group(function () {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::get('/show/{employee}', [EmployeeController::class, 'show']);
        Route::match(['put', 'patch'], '/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/{employee}', [EmployeeController::class, 'destroy']);

        Route::get('/export', [EmployeeController::class, 'export']);
        Route::post('/change-password/{employee}', [EmployeeController::class, 'changePassword']);
        Route::post('/change-image/{employee}', [EmployeeController::class, 'changeImage']);

        Route::get('/my-order/{employee}', [EmployeeController::class, 'myOrder']);

        Route::get('/address/{employee}', [EmployeeAddressController::class, 'index']);
        Route::get('/address/show/{employee}/{address}', [EmployeeAddressController::class, 'show']);
        Route::post('/address/{employee}', [EmployeeAddressController::class, 'store']);
        Route::match(['put', 'patch'], '/address/{employee}/{address}', [EmployeeAddressController::class, 'update']);
        Route::delete('/address/{employee}/{address}', [EmployeeAddressController::class, 'destroy']);
    });

    Route::prefix('delivery-boy')->name('delivery-boy.')->group(function () {
        Route::get('/', [DeliveryBoyController::class, 'index']);
        Route::post('/', [DeliveryBoyController::class, 'store']);
        Route::get('/show/{deliveryBoy}', [DeliveryBoyController::class, 'show']);
        Route::match(['put', 'patch'], '/{deliveryBoy}', [DeliveryBoyController::class, 'update']);
        Route::delete('/{deliveryBoy}', [DeliveryBoyController::class, 'destroy']);

        Route::get('/export', [DeliveryBoyController::class, 'export']);
        Route::post('/change-password/{deliveryBoy}', [DeliveryBoyController::class, 'changePassword']);
        Route::post('/change-image/{deliveryBoy}', [DeliveryBoyController::class, 'changeImage']);

        Route::get('/my-order/{deliveryBoy}', [DeliveryBoyController::class, 'myOrder']);
        Route::get('/delivered-order/{deliveryBoy}', [DeliveryBoyOrderController::class, 'deliveredOrder']);
        Route::get('/delivered-order/show/{deliveryBoy}/{order}', [DeliveryBoyOrderController::class, 'deliveredOrderDetails']);

        Route::get('/address/{deliveryBoy}', [DeliveryBoyAddressController::class, 'index']);
        Route::get('/address/show/{deliveryBoy}/{address}', [DeliveryBoyAddressController::class, 'show']);
        Route::post('/address/{deliveryBoy}', [DeliveryBoyAddressController::class, 'store']);
        Route::match(
            ['put', 'patch'],
            '/address/{deliveryBoy}/{address}',
            [DeliveryBoyAddressController::class, 'update']
        );
        Route::delete('/address/{deliveryBoy}/{address}', [DeliveryBoyAddressController::class, 'destroy']);
    });

    // [V1.0.2-LIVREUR-CASH] Delivery boy cash session — open/close/reconcile a
    // livreur's float at the start/end of a shift. Mirrors the POS cash-drawer
    // session pattern (L815) but scoped to the delivery_boy_id rather than the
    // cashier user_id. Mutations carry idempotency middleware so retries from
    // flaky tablets don't open multiple sessions for the same livreur.
    // Controller: app/Http/Controllers/Admin/DeliveryBoyCashSessionController.php
    // BUILD-1 (round-4 brief 2026-05-18).
    // [RECONCILE 2026-05-18] BUILD-1 canonical contract : URL prefix = `cash-sessions` (plural),
    // permissions enforced in DeliveryBoyCashSessionController::__construct via
    // `permission:delivery-boys_show` (read) + `permission:delivery-boys` (mutations).
    // Route-level middleware reduced to `idempotency` on POST only (avoid double permission gate).
    Route::prefix('delivery-boy/cash-sessions')->name('delivery-boy.cash-sessions.')->group(function () {
        Route::get('/', [DeliveryBoyCashSessionController::class, 'index'])
            ->name('index');
        Route::post('/open', [DeliveryBoyCashSessionController::class, 'open'])
            ->middleware('idempotency')
            ->name('open');
        Route::get('/{session}', [DeliveryBoyCashSessionController::class, 'show'])
            ->whereNumber('session')
            ->name('show');
        Route::post('/{session}/close', [DeliveryBoyCashSessionController::class, 'close'])
            ->whereNumber('session')
            ->middleware('idempotency')
            ->name('close');
        Route::post('/{session}/reconcile', [DeliveryBoyCashSessionController::class, 'reconcile'])
            ->whereNumber('session')
            ->middleware('idempotency')
            ->name('reconcile');
    });

    Route::prefix('coupon')->name('coupon.')->group(function () {
        Route::get('/', [CouponController::class, 'index']);
        Route::get('/show/{coupon}', [CouponController::class, 'show']);
        Route::post('/', [CouponController::class, 'store']);
        Route::match(['post', 'put', 'patch'], '/{coupon}', [CouponController::class, 'update']);
        Route::delete('/{coupon}', [CouponController::class, 'destroy']);
        Route::get('/export', [CouponController::class, 'export']);
        // [PROMO-DASH-2026-05-06] Toggle status ACTIVE <-> INACTIVE
        Route::post('/toggle-status/{coupon}', [CouponController::class, 'toggleStatus'])->name('toggleStatus');
    });

    Route::prefix('offer')->name('offer.')->group(function () {
        Route::get('/', [OfferController::class, 'index']);
        Route::get('/show/{offer}', [OfferController::class, 'show']);
        Route::post('/', [OfferController::class, 'store']);
        Route::match(['post', 'put', 'patch'], '/{offer}', [OfferController::class, 'update']);
        Route::delete('/{offer}', [OfferController::class, 'destroy']);
        Route::get('/export', [OfferController::class, 'export']);
        Route::post('/change-image/{offer}', [OfferController::class, 'changeImage']);

        Route::get('/item/{offer}', [OfferItemController::class, 'index']);
        Route::post('/item/{offer}', [OfferItemController::class, 'store']);
        Route::delete('/item/{offer}/{offerItem}', [OfferItemController::class, 'destroy']);
    });

    Route::prefix('item')->name('item.')->group(function () {

        Route::get('/', [ItemController::class, 'index']);
        Route::get('/lookup-barcode/{code}', [ItemController::class, 'lookupBarcode'])->where('code', '[^/]+');
        Route::get('/show/{item}', [ItemController::class, 'show']);
        Route::post('/', [ItemController::class, 'store']);
        Route::post('/{item}/duplicate', [ItemController::class, 'duplicate'])->name('duplicate');
        Route::match(['post', 'put', 'patch'], '/{item}', [ItemController::class, 'update']);
        Route::delete('/{item}', [ItemController::class, 'destroy']);
        Route::post('/change-image/{item}', [ItemController::class, 'changeImage']);
        Route::get('/export', [ItemController::class, 'export']);
        Route::get('/download-sample', [ItemController::class, 'downloadSample']);
        Route::post('/import/file', [ItemController::class, 'import']);
        Route::get('/details/{item}', [ItemController::class, 'itemDetails']);

        Route::get('/variation/{item}', [ItemVariationController::class, 'index']);
        Route::get('/variation/group-by-attribute/{item}', [ItemVariationController::class, 'listGroupByAttribute']);
        Route::post('/variation/{item}', [ItemVariationController::class, 'store']);
        Route::match(['put', 'patch'], '/variation/{item}/{itemVariation}', [ItemVariationController::class, 'update']);
        Route::delete('/variation/{item}/{itemVariation}', [ItemVariationController::class, 'destroy']);
        Route::get('/variation/{item}/show/{itemVariation}', [ItemVariationController::class, 'show']);

        Route::get('/extra/{item}', [ItemExtraController::class, 'index']);
        Route::post('/extra/{item}', [ItemExtraController::class, 'store']);
        Route::match(['put', 'patch'], '/extra/{item}/{itemExtra}', [ItemExtraController::class, 'update']);
        Route::delete('/extra/{item}/{itemExtra}', [ItemExtraController::class, 'destroy']);
        Route::get('/extra/{item}/show/{itemExtra}', [ItemExtraController::class, 'show']);

        // [PILOTAGE 2026-08-09] Donner une photo à une OPTION depuis l'admin.
        // Jusqu'ici la photo d'un supplément ou d'une variation était déduite de
        // son NOM via config/menu_images.php : il fallait un développeur ET un
        // accès au serveur. Ces deux routes ferment ce trou — même contrat que
        // `item/change-image`, la photo posée ici prime sur la table par nom.
        Route::post('/extra/{item}/{itemExtra}/change-image', [ItemExtraController::class, 'changeImage']);
        Route::delete('/extra/{item}/{itemExtra}/change-image', [ItemExtraController::class, 'removeImage']);
        Route::post('/variation/{item}/{itemVariation}/change-image', [ItemVariationController::class, 'changeImage']);
        Route::delete('/variation/{item}/{itemVariation}/change-image', [ItemVariationController::class, 'removeImage']);

        Route::get('/addon/{item}', [ItemAddonController::class, 'index']);
        Route::post('/addon/{item}', [ItemAddonController::class, 'store']);
        Route::delete('/addon/{item}/{itemAddon}', [ItemAddonController::class, 'destroy']);
    });
    Route::post('/items/{item}/photo', [ItemPhotoController::class, 'store'])->name('items.photo.store');

    Route::prefix('ingredients')->name('ingredients.')->middleware('permission:ingredients_manage')->group(function () {
        Route::get('/', [IngredientController::class, 'index'])->name('index');
        Route::get('/{globalId}/usage', [IngredientController::class, 'usage'])
            ->where('globalId', '[a-z]+:[0-9]+')
            ->name('usage');
        Route::get('/{globalId}', [IngredientController::class, 'show'])
            ->where('globalId', '[a-z]+:[0-9]+')
            ->name('show');
        Route::match(['put', 'patch'], '/{globalId}/availability', [IngredientController::class, 'toggleAvailability'])
            ->where('globalId', '[a-z]+:[0-9]+')
            ->name('availability.toggle');
    });

    Route::prefix('composer')->name('composer.')->group(function () {
        Route::middleware('permission:catalog.compose')->group(function () {
            Route::middleware('wizard.per_item_demo')->group(function () {
                Route::get('/items/{item}/profile', [ComposerProfileController::class, 'show']);
                Route::post('/items/{item}/profile', [ComposerProfileController::class, 'store']);
                // [CV1-WIZARD-COMPOSABLE-001 T-WC-TEMPLATES-01] Apply named starter template
                Route::post('/items/{item}/apply-template', [ComposerProfileController::class, 'applyTemplate']);
                // [CV1-WIZARD-COMPOSABLE-001 T-WC-SOURCE-PICKER-01] Available source candidates for picker
                Route::get('/items/{item}/available-sources', [ComposerProfileController::class, 'availableSources']);
            });
            Route::get('/categories/{category}/profile', [ComposerProfileController::class, 'showForCategory']);
            Route::post('/categories/{category}/profile', [ComposerProfileController::class, 'storeForCategory']);
            Route::post('/categories/{category}/apply-template', [ComposerProfileController::class, 'applyTemplateToCategory']);
            Route::get('/categories/{category}/available-sources', [ComposerProfileController::class, 'availableSourcesForCategory']);
            // [GOAL DASHBOARD-PILOTABLE 2026-09-02] Ce que la caisse lit vraiment (version publiée,
            // couverture produit par produit) + resynchronisation des produits avec le wizard publié.
            Route::get('/categories/{category}/runtime', [ComposerProfileController::class, 'runtimeForCategory']);
            Route::post('/categories/{category}/materialize', [ComposerProfileController::class, 'materializeCategory']);
            // Bibliothèque de pages de wizard réutilisables (choix + prix), copies privées par catégorie.
            Route::get('/wizard-pages', [\App\Http\Controllers\Admin\WizardPageController::class, 'index']);
            Route::post('/wizard-pages', [\App\Http\Controllers\Admin\WizardPageController::class, 'store']);
            Route::get('/wizard-pages/{wizardPage}', [\App\Http\Controllers\Admin\WizardPageController::class, 'show']);
            Route::match(['put', 'patch'], '/wizard-pages/{wizardPage}', [\App\Http\Controllers\Admin\WizardPageController::class, 'update']);
            Route::delete('/wizard-pages/{wizardPage}', [\App\Http\Controllers\Admin\WizardPageController::class, 'destroy']);
            Route::post('/wizard-pages/{wizardPage}/duplicate-for-category/{category}', [\App\Http\Controllers\Admin\WizardPageController::class, 'duplicateForCategory']);
            Route::middleware('wizard.per_item_profile_guard')->group(function () {
                Route::match(['put', 'patch'], '/profiles/{profile}', [ComposerProfileController::class, 'update']);
                Route::get('/profiles/{profile}/diff', [ComposerProfileController::class, 'diff']);
                Route::post('/profiles/{profile}/unpublish', [ComposerProfileController::class, 'unpublish']);
                Route::post('/profiles/{profile}/steps', [ComposerStepController::class, 'store']);
                Route::match(['put', 'patch'], '/steps/{step}', [ComposerStepController::class, 'update']);
                Route::delete('/steps/{step}', [ComposerStepController::class, 'destroy']);
            });
        });
        Route::post('/profiles/{profile}/publish', [ComposerProfileController::class, 'publish'])
            ->middleware('permission:catalog.publish');
    });

    /*
    | ROUE — validation au comptoir. C'est un droit de DONNER un lot : réservé aux comptes caisse,
    | et la branche vient du COMPTE, jamais de la requête (sinon on valide chez le voisin).
    */
    Route::post('/wheel/unlock-token', [\App\Http\Controllers\Admin\Wheel\WheelUnlockController::class, 'issue'])
        ->middleware('throttle:60,1')
        ->name('wheel.unlockToken');

    /*
    | ROUE — LA PASSE VERS LES ÉCRANS, depuis une caisse DÉJÀ connectée.
    |
    | [2026-08-13 · propriétaire : « ce n'est pas le bouton, c'est le code PIN »] Le caissier a une
    | session applicative valide, mais elle vit dans un jeton Bearer que le navigateur n'attache
    | JAMAIS à une navigation de document. Cliquer un lien vers un écran de la roue arrivait donc
    | anonyme, et redemandait le code — plusieurs fois par service.
    |
    | L'application, elle, PEUT appeler cette route : elle a le jeton. On lui rend une adresse
    | signée, courte et à usage unique. Le débit est limité serré : cette route fabrique des
    | laissez-passer, on n'en distribue pas en rafale.
    */
    Route::post('/wheel/screen-pass', [\App\Http\Controllers\Admin\Wheel\WheelAccessController::class, 'screenPass'])
        ->middleware('throttle:20,1')
        ->name('wheel.screenPass');

    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/walk-in-customer', [PosController::class, 'walkInCustomer'])
            ->middleware('throttle:pos-quote')
            ->name('walk-in-customer');
        Route::post('/quote', [PosController::class, 'quote'])
            ->middleware('throttle:pos-quote')
            ->name('quote');
        Route::post('/', [PosController::class, 'store'])->middleware(['throttle:pos-order-create', 'idempotency']);
        Route::get('/counter-collect/pending', function () {
            abort_unless(auth()->user()?->can('pos'), 403);

            // [GOAL-CAISSE-UNIFIED W-ENC + delta-(B) 2026-05-30] Unified collection
            // queue: Borne (kiosk Plan B) AND Caisse walk-in routed through
            // pos.walkin_route_to_counter. Both are PENDING_COUNTER; the Borne
            // clause is byte-identical to the original (kiosk + KIOSK/TAKEAWAY type)
            // so existing behavior is preserved, and the added clause surfaces
            // pos-origin counter-deferred orders (source_surface='pos' +
            // COUNTER_DEFERRED). With the flag OFF, no pos order is deferred so the
            // result set is unchanged.
            // [PERF N+1 2026-07-31] Eager-load des relations lues par OrderDetailsResource
            // (user/address/branch/deliveryBoy/coupon/transaction/diningTable/payments) — sinon
            // ~9 requetes lazy PAR commande a chaque tick de polling (cap 200 → jusqu'a ~1800).
            $query = \App\Models\Order::with(['orderItems.orderItem', 'user', 'address', 'branch', 'deliveryBoy', 'coupon', 'transaction', 'diningTable', 'payments'])
                ->where('payment_status', \App\Enums\PaymentStatus::PENDING_COUNTER)
                // [ENCAISSEMENT-ROBUSTE 2026-07-01] Une commande ANNULÉE ne doit jamais rester
                // dans la file d'encaissement (sinon « fantôme » qui 422 à l'encaissement).
                // [SELF-AUDIT R3 P2 2026-07-05 — fantôme incaissable] La file d'encaissement excluait
                // seulement CANCELED, mais confirmCounterPayment REFUSE aussi REJECTED/RETURNED
                // (PaymentService:325). Un remboursement pré-Z (RETURNED) d'une commande Plan-B non
                // encaissée laissait payment_status=PENDING_COUNTER → la commande restait à VIE dans la
                // file /admin/encaissement, non encaissable. On aligne sur le set terminal du sceau.
                ->whereNotIn('status', [\App\Enums\OrderStatus::CANCELED, \App\Enums\OrderStatus::REJECTED, \App\Enums\OrderStatus::RETURNED])
                ->where(function ($q) {
                    $q->where(function ($k) {
                        $k->where('source_surface', 'kiosk')
                            ->whereIn('order_type', [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY]);
                    })->orWhere(function ($p) {
                        $p->where('source_surface', 'pos')
                            ->where('pos_payment_method', \App\Enums\PosPaymentMethod::COUNTER_DEFERRED);
                    })->orWhere(function ($tel) {
                        // [C4-CAISSE-TELEPHONE 2026-07-07] Commande téléphone caisse (paiement différé)
                        // → source_surface='phone' + COUNTER_DEFERRED. Sans cette clause, la commande
                        // téléphone serait INVISIBLE en caisse donc INENCAISSABLE (même famille de bug
                        // que le filet anti-NULL ci-dessous). Miroir de la garde assertCounterDeferredOrder.
                        $tel->where('source_surface', 'phone')
                            ->where('pos_payment_method', \App\Enums\PosPaymentMethod::COUNTER_DEFERRED);
                    })->orWhere(function ($web) {
                        // [P1-3 2026-07-18] Commande WEB à emporter acceptée sans paiement en ligne
                        // (carte web OFF, mandat owner) : SYNC-WEB-KDS-01 la bascule en PENDING_COUNTER
                        // pour la visibilité cuisine et OnlineOrderController complète le marqueur
                        // COUNTER_DEFERRED (takeaway COD) → 4e origine LÉGITIME de counter-collect. Sans
                        // cette clause, la commande web PENDING_COUNTER reste INVISIBLE en caisse donc
                        // INENCAISSABLE (vente perdue). Miroir strict des clauses pos/phone + de la garde
                        // assertCounterDeferredOrder (qui autorise 'web'). La LIVRAISON web n'a PAS le
                        // marqueur COUNTER_DEFERRED (encaissée au doorstep) → naturellement exclue ici.
                        $web->where('source_surface', 'web')
                            ->where('pos_payment_method', \App\Enums\PosPaymentMethod::COUNTER_DEFERRED);
                    })->orWhere(function ($n) {
                        // [ENCAISSEMENT-ROBUSTE 2026-07-01] Filet anti-NULL : une commande borne
                        // PENDING_COUNTER dont le tag source_surface manque (donnée héritée) resterait
                        // INVISIBLE en caisse donc INENCAISSABLE. On la rattrape par le type kiosk/emporter.
                        $n->whereNull('source_surface')
                            ->whereIn('order_type', [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY]);
                    });
                })
                ->orderBy('created_at');

            $branchId = (int) (auth()->user()?->branch_id ?? 0);
            if ($branchId > 0) {
                $query->where('branch_id', $branchId);
            }

            // [abuse-e2e P3 heal 2026-05-30] Cap raised 50→200. Oldest-first
            // (created_at ASC) is the correct FIFO collection order — collect the
            // longest-waiting customer first. The old 50-cap silently hid orders
            // beyond 50 (a real V1 single-box gap once a backlog builds: a
            // waiting-to-pay customer became invisible to the cashier). 200 is far
            // beyond any realistic single-restaurant uncollected backlog while
            // staying bounded.
            return \App\Http\Resources\OrderDetailsResource::collection($query->limit(200)->get());
        })->middleware('throttle:pos-order-update')->name('counter-collect.pending');
        // [WEB-CAISSE-SYNC 2026-07-13] File des commandes WEB en attente (à traiter en caisse).
        // Le paiement carte en ligne étant OFF (mandat owner), toute commande web = règlement au
        // comptoir → créée PENDING/UNPAID + source_surface='web'. Contrairement à la borne (client
        // sur place → auto-accept + cuisine immédiate), une commande web distante NE DOIT PAS
        // auto-cuisiner ; l'opérateur l'accepte quand le client arrive. Cette route READ-ONLY la
        // fait remonter sur l'écran caisse (le panneau borne « à encaisser » filtre kiosk/pos/phone,
        // PAS web). Aucun changement de statut/paiement ici — l'accept se fait via le flux Commandes
        // existant. Miroir volontaire de counter-collect/pending (branch-scope, cap 200, FIFO).
        Route::get('/web-orders/pending', function () {
            abort_unless(auth()->user()?->can('pos'), 403);

            // [PERF N+1 2026-07-31] Meme eager-load que counter-collect (relations OrderDetailsResource).
            $query = \App\Models\Order::with(['orderItems.orderItem', 'user', 'address', 'branch', 'deliveryBoy', 'coupon', 'transaction', 'diningTable', 'payments'])
                // [AUDIT-B D3 2026-08-06 · P1] web ≡ delivery : FrontendOrder::creating force
                // source_surface='delivery' dès que order_type=DELIVERY — une commande LIVRAISON
                // site PENDING était donc INVISIBLE ici (et le janitor, correctement étendu à
                // 'delivery', l'ANNULAIT après TTL = perte de commande silencieuse). Même
                // équivalence que les gardes web/delivery (OrderService:2248) et les lanes janitor.
                ->whereIn('source_surface', ['web', 'delivery'])
                ->where('status', \App\Enums\OrderStatus::PENDING)
                // [OWNER 2026-08-04 R1 SÉCU] Une carte web PENDING+UNPAID = paiement en LIGNE
                // en vol : pilotée par le paiement (payée → auto-cuisine ; échouée → annulée),
                // JAMAIS « à accepter » par le caissier. On l'exclut de la file caisse pour
                // qu'elle ne soit ni acceptable ni source de double-encaissement. Les commandes
                // comptoir (COD/UNPAID) et les cartes DÉJÀ payées (promues) ne sont pas ici.
                ->where(function ($q) {
                    $q->where('payment_method', '!=', \App\Enums\PaymentGateway::CARD)
                        ->orWhere('payment_status', '!=', \App\Enums\PaymentStatus::UNPAID);
                })
                ->orderBy('created_at');

            $branchId = (int) (auth()->user()?->branch_id ?? 0);
            if ($branchId > 0) {
                $query->where('branch_id', $branchId);
            }

            return \App\Http\Resources\OrderDetailsResource::collection($query->limit(200)->get());
        })->middleware('throttle:pos-order-update')->name('web-orders.pending');

        // [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] File des commandes WEB **DÉJÀ PAYÉES**, parties
        // seules en cuisine.
        //
        // POURQUOI CETTE ROUTE EXISTE — un trou entre deux gardes justes
        // ---------------------------------------------------------------
        // La file `web-orders/pending` ci-dessus exige `status = PENDING` ET exclut
        // `CARD + UNPAID`. Une commande web réglée par carte n'entre donc dans ce panneau
        // À AUCUN INSTANT de sa vie : pendant sa fenêtre PENDING elle est CARD+UNPAID
        // (exclue, à raison — paiement en vol, le caissier ne doit pas l'« accepter ») ;
        // dès que le paiement tombe elle est promue ACCEPT→PREPARING (exclue, plus PENDING).
        // Résultat mesuré en production le 2026-08-10 : la commande #440 (31,40 € encaissés,
        // 4 articles) n'a produit AUCUN signal en caisse — pas de ligne, pas de bip — et le
        // ticket cuisine n'est jamais sorti (aucune imprimante en base, cf. la file
        // kitchen-tickets). Elle n'existait QUE sur l'écran KDS. Le client a attendu.
        //
        // Ce panneau est le signal manquant : il montre ce que la cuisine a reçu SANS que
        // la caisse en soit informée. LECTURE SEULE — aucun changement de statut ni de
        // paiement ici (la commande est déjà payée et déjà acceptée par le flux paiement ;
        // proposer un bouton « Accepter » rejouerait une transition déjà faite).
        //
        // Fenêtre et statuts calqués sur le BOARD CUISINE (KitchenReleaseRule +
        // oss.stale_window_hours) : ce panneau et le KDS montrent le même ensemble, donc
        // « vu en caisse » et « vu en cuisine » ne peuvent pas diverger. PREPARED est
        // volontairement exclu — une commande finie remonte dans « Prêt à livrer »
        // (loadReadyOrders), pas ici. Miroir volontaire de web-orders/pending :
        // même portée branche, même cap 200, même tri FIFO.
        Route::get('/web-orders/paid', function () {
            abort_unless(auth()->user()?->can('pos'), 403);

            $query = \App\Models\Order::with(['orderItems.orderItem', 'user', 'address', 'branch', 'deliveryBoy', 'coupon', 'transaction', 'diningTable', 'payments'])
                // Même équivalence web ≡ delivery que la file PENDING : FrontendOrder::creating
                // force source_surface='delivery' dès que order_type=DELIVERY.
                ->whereIn('source_surface', ['web', 'delivery'])
                ->where('payment_status', \App\Enums\PaymentStatus::PAID)
                ->whereIn('status', [\App\Enums\OrderStatus::ACCEPT, \App\Enums\OrderStatus::PREPARING])
                // Borne basse identique au board cuisine : sans elle, un vieux payé jamais bumpé
                // (il en existe — #333 du 2026-08-03) squatterait le panneau à vie et le bip
                // deviendrait du bruit que l'équipe apprendrait à ignorer.
                ->where('order_datetime', '>=', now()->subHours((int) config('oss.stale_window_hours', 8)))
                ->orderBy('created_at');

            $branchId = (int) (auth()->user()?->branch_id ?? 0);
            if ($branchId > 0) {
                $query->where('branch_id', $branchId);
            }

            return \App\Http\Resources\OrderDetailsResource::collection($query->limit(200)->get());
        })->middleware('throttle:pos-order-update')->name('web-orders.paid');

        // [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] File des tickets cuisine réclamée par le PC
        // caisse. Le serveur ne peut pas joindre l'imprimante (hébergeur ≠ réseau du restaurant),
        // donc c'est le poste qui vient chercher — même modèle que le ticket promo. Les octets se
        // lisent ensuite sur `orders/{order}/escpos?ticket=kitchen`, qui sait déjà rendre sans
        // aucune row `Printer`. PAS d'idempotence ici : une réclamation rejouée depuis un cache
        // rendrait un ticket déjà pris et le papier ne sortirait jamais.
        // [429 EN SERVICE 2026-08-13] Ces deux routes sont un SONDAGE, pas une mutation admin.
        // Elles partaient toutes les 5 s depuis chaque écran d'administration ouvert et vidaient
        // le seau `admin-mutation` (60/min, prévu pour du CRUD) : le service voyait « trop de
        // requêtes, réessayez plus tard ». On les sort de ce seau et on les met sur leur propre
        // mesure, calée sur leur rythme réel. Le plafond demeure — simplement au bon endroit.
        Route::post('/kitchen-tickets/pending', [\App\Http\Controllers\Admin\Pos\KitchenTicketQueueController::class, 'pending'])
            ->middleware('throttle:print-queue-poll')
            ->withoutMiddleware('throttle:admin-mutation')
            ->name('kitchen-tickets.pending');
        Route::post('/kitchen-tickets/{order}/ack', [\App\Http\Controllers\Admin\Pos\KitchenTicketQueueController::class, 'acknowledge'])
            ->whereNumber('order')
            ->middleware('throttle:print-queue-poll')
            ->withoutMiddleware('throttle:admin-mutation')
            ->name('kitchen-tickets.ack');

        // [CAISSE-HEALTH 2026-07-30] Santé SYSTÈME pour le poste de commande : temps réel (socket +
        // worker outbox) + chaîne fiscale NF525. READ-ONLY. L'opérateur voit une dégradation AVANT
        // que des commandes se perdent en silence (soketi « connecté » alors que le worker est DOWN).
        Route::get('/system-health', \App\Http\Controllers\Admin\PosSystemHealthController::class)
            ->middleware(['permission:pos', 'throttle:pos-order-update'])
            ->name('system-health');

        // [OWNER REPAS-PERSONNEL/PERTES 2026-07-31] Sorties de stock hors-vente (repas personnel /
        // perte) depuis la caisse : trace horodatée + décrément du stock direct. permission:pos.
        Route::get('/stock-outflow/items', [\App\Http\Controllers\Admin\PosStockOutflowController::class, 'items'])
            ->middleware('permission:pos')->name('stock-outflow.items');
        Route::get('/stock-outflow/recent', [\App\Http\Controllers\Admin\PosStockOutflowController::class, 'recent'])
            ->middleware('permission:pos')->name('stock-outflow.recent');
        Route::post('/stock-outflow', [\App\Http\Controllers\Admin\PosStockOutflowController::class, 'store'])
            ->middleware(['permission:pos', 'throttle:pos-order-update', 'idempotency'])->name('stock-outflow.store');
        Route::post('/counter-collect/{order}/confirm', function (\App\Models\Order $order, \Illuminate\Http\Request $request) {
            abort_unless(auth()->user()?->can('pos'), 403);

            try {
                $validated = $request->validate([
                    'mode' => ['required', 'integer'],
                    // [DEEP-R2b] borne haute anti fat-finger (5700 au lieu de 57,00 →
                    // ticket fiscal « RENDU : 5 643,00 € »).
                    'received' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
                    'note' => ['nullable', 'string', 'max:255'],
                    // [GOAL-8AXES V6 T-3.3.1 2026-08-05] Multi-tender à l'ENCAISSEMENT
                    // (owner : « 12 € en carte, le reste en espèces »). Les règles
                    // monétaires fines (somme au centime, modes, TPE par tranche CARD)
                    // sont dans SplitPaymentService::validateBreakdown — pas dupliquées.
                    'payment_breakdown' => ['nullable', 'array', 'max:'.(int) config('split_payment.max_tranches', 12)],
                    'payment_breakdown.*' => ['array'],
                    'payment_breakdown.*.mode' => ['required_with:payment_breakdown', 'integer'],
                    'payment_breakdown.*.amount' => ['required_with:payment_breakdown', 'numeric', 'min:0.01', 'max:9999.99'],
                    'payment_breakdown.*.tendered' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
                    'payment_breakdown.*.terminal_id' => ['nullable', 'integer', 'min:1'],
                    'payment_breakdown.*.note' => ['nullable', 'string', 'max:200'],
                ]);

                return new \App\Http\Resources\OrderDetailsResource(app(\App\Services\PaymentService::class)->confirmCounterPayment(
                    $order,
                    (int) $validated['mode'],
                    array_key_exists('received', $validated) ? (float) $validated['received'] : null,
                    $validated['note'] ?? null,
                    $validated['payment_breakdown'] ?? null
                ));
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
                throw $http;
            } catch (\Illuminate\Validation\ValidationException $validation) {
                throw $validation;
            } catch (\App\Exceptions\Payment\PaymentAlreadyCollectedException $alreadyCollected) {
                // [GOAL-K2-HEAL-01 2026-05-24] Phase K.4 H9 P1 + J-CASCADE H9
                // — typed catch MUST live above the generic Exception fallback
                // so the 409 conversion happens before the 422 default. The
                // exception extends \RuntimeException (not HttpException) on
                // purpose; see PaymentAlreadyCollectedException docblock for
                // the rationale (Handler.php:105-113 would otherwise downgrade
                // any HttpException to 422). error_code lets the frontend
                // modal branch on a stable identifier instead of parsing the
                // (translated) message.
                return response()->json([
                    'status' => false,
                    'message' => $alreadyCollected->getMessage(),
                    'error_code' => 'payment_already_collected',
                    'order_id' => $alreadyCollected->orderId,
                    'collected_by_user_id' => $alreadyCollected->collectedByUserId,
                    'collected_at' => $alreadyCollected->collectedAt,
                ], 409);
            } catch (\Exception $exception) {
                return response(['status' => false, 'message' => $exception->getMessage()], 422);
            }
        })->middleware(['throttle:pos-order-update', 'idempotency'])->name('counter-collect.confirm');
        Route::post('/counter-collect/{order}/cancel', function (\App\Models\Order $order, \Illuminate\Http\Request $request) {
            abort_unless(auth()->user()?->can('pos'), 403);

            try {
                $validated = $request->validate([
                    'reason' => ['nullable', 'string', 'max:255'],
                ]);

                return new \App\Http\Resources\OrderDetailsResource(app(\App\Services\PaymentService::class)->cancelCounterPayment(
                    $order,
                    $validated['reason'] ?? null
                ));
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
                throw $http;
            } catch (\Illuminate\Validation\ValidationException $validation) {
                throw $validation;
            } catch (\Exception $exception) {
                return response(['status' => false, 'message' => $exception->getMessage()], 422);
            }
        })->middleware(['throttle:pos-order-update', 'idempotency'])->name('counter-collect.cancel');
        Route::post('/collect-kiosk-cash/{order}', function (\App\Models\Order $order) {
            abort_unless(auth()->user()?->can('pos'), 403);

            try {
                return new \App\Http\Resources\OrderDetailsResource(app(\App\Services\OrderService::class)->collectKioskCash($order));
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $http) {
                throw $http;
            } catch (\Exception $exception) {
                return response(['status' => false, 'message' => $exception->getMessage()], 422);
            }
        })->middleware(['throttle:pos-order-update', 'idempotency'])->name('collect-kiosk-cash');
        // [SELF-AUDIT R3 P3 2026-07-05 — dérive d'autorisation] print-receipt écrit une trace NF525
        // (audit) + incrémente le compteur d'impression, print-kitchen déclenche une impression : ces
        // routes n'avaient AUCUNE garde permission (seul `idempotency` + groupe admin) → un Chef sans
        // `pos`/`pos-orders` pouvait les appeler. On les aligne sur les opérations POS sœurs.
        Route::post('/orders/{order}/print-receipt', [PosReceiptPrintController::class, 'increment'])->middleware(['permission:pos-orders|pos', 'idempotency'])->name('orders.print-receipt');
        // [PRINT-SAGA 2026-06-24] Kitchen production ticket → best-effort ESC/POS (no fiscal audit).
        Route::post('/orders/{order}/print-kitchen', [PosReceiptPrintController::class, 'kitchen'])->middleware(['permission:pos-orders|pos', 'idempotency'])->name('orders.print-kitchen');
        // [CAISSE-BRIDGE 2026-06-28] Octets ESC/POS rendus serveur (base64) → le frontend les POSTe
        // au pont local caisse pour une impression SILENCIEUSE (le cloud Linux ne joint pas l'USB). Lecture seule.
        Route::get('/orders/{order}/escpos', [\App\Http\Controllers\Admin\Pos\PosTicketBytesController::class, 'show'])->name('orders.escpos-bytes');
        // [CUSTOMER-DISPLAY 2026-06-28] Refresh the SAGA pole display (total / welcome). Best-effort, no fiscal.
        Route::post('/customer-display', [\App\Http\Controllers\Admin\Pos\PosCustomerDisplayController::class, 'update'])->name('customer-display.update');
        Route::prefix('parked-orders')->name('parked-orders.')->group(function () {
            Route::get('/', [ParkedOrderController::class, 'index'])->name('index');
            Route::post('/', [ParkedOrderController::class, 'store'])->name('store');
            Route::get('/{id}', [ParkedOrderController::class, 'show'])->name('show');
            Route::delete('/{id}', [ParkedOrderController::class, 'destroy'])->name('destroy');
        });
        Route::prefix('floorplan')->name('floorplan.')->group(function () {
            Route::get('/state', [FloorplanController::class, 'state'])->name('state');
            Route::post('/transfer', [FloorplanController::class, 'transfer'])->name('transfer');
            Route::post('/{tableId}/assign', [FloorplanController::class, 'assign'])->name('assign');
            Route::post('/{tableId}/release', [FloorplanController::class, 'release'])->name('release');
        });
        Route::post('/cash-drawer/open', [CashDrawerController::class, 'open'])->middleware('idempotency')->name('cash-drawer.open');
        // [AUDIT-F-003] Cash drawer SESSION management — distinct du hardware open above.
        Route::prefix('cash-drawer/sessions')->name('cash-drawer.sessions.')->group(function () {
            Route::get('/current', [CashDrawerSessionController::class, 'current'])->name('current');
            Route::post('/open', [CashDrawerSessionController::class, 'open'])->middleware('idempotency')->name('open');
            Route::post('/{session}/close', [CashDrawerSessionController::class, 'close'])
                ->whereNumber('session')
                ->middleware('idempotency')
                ->name('close');
            Route::post('/{session}/reconcile', [CashDrawerSessionController::class, 'reconcile'])
                ->whereNumber('session')
                ->middleware('idempotency')
                ->name('reconcile');
            Route::get('/{session}/movements', [CashDrawerSessionController::class, 'movements'])
                ->whereNumber('session')
                ->name('movements');
        });
        Route::post('/customers/lookup-by-nfc', [CustomerNfcLookupController::class, 'lookup'])->name('customers.lookup-by-nfc');
    });

    // [FLYER PROMO UBER 2026-08-07] Ticket promotionnel nominatif imprimé à la
    // caisse. `pending` et `acknowledge` sont MUTANTS (ils posent un verrou de
    // réclamation et incrémentent un compteur) : ils sont donc en POST, jamais
    // en GET — un GET rejoué par un préchargement navigateur consommerait des
    // tentatives d'impression en silence.
    Route::prefix('promo-flyer')->name('promoFlyer.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\PromoFlyerController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\Admin\PromoFlyerController::class, 'store'])->name('store');
        Route::get('/settings', [App\Http\Controllers\Admin\PromoFlyerController::class, 'settings'])->name('settings');
        Route::match(['put', 'patch'], '/settings', [App\Http\Controllers\Admin\PromoFlyerController::class, 'updateSettings'])->name('settings.update');
        // [429 EN SERVICE 2026-08-13] Même traitement que la file cuisine, et pour la même raison.
        // C'est CETTE route qui encaissait les refus mesurés en production (1130 sur 4746 appels) :
        // un sondage toutes les 5 s par écran ouvert, dans un seau de 60/min prévu pour du CRUD.
        // Elle n'avait même pas de mesure à elle — seul `admin-mutation` la bornait.
        Route::post('/pending', [App\Http\Controllers\Admin\PromoFlyerController::class, 'pending'])
            ->middleware('throttle:print-queue-poll')
            ->withoutMiddleware('throttle:admin-mutation')
            ->name('pending');
        Route::get('/{flyer}/escpos', [App\Http\Controllers\Admin\PromoFlyerController::class, 'escpos'])->whereNumber('flyer')->name('escpos');
        Route::post('/{flyer}/ack', [App\Http\Controllers\Admin\PromoFlyerController::class, 'acknowledge'])
            ->whereNumber('flyer')
            ->middleware('throttle:print-queue-poll')
            ->withoutMiddleware('throttle:admin-mutation')
            ->name('ack');
        // Gestion : relancer une impression ratée, annuler un code émis par erreur.
        Route::post('/{flyer}/reprint', [App\Http\Controllers\Admin\PromoFlyerController::class, 'reprint'])->whereNumber('flyer')->name('reprint');
        Route::post('/{flyer}/revoke', [App\Http\Controllers\Admin\PromoFlyerController::class, 'revoke'])->whereNumber('flyer')->name('revoke');
    });

    Route::prefix('printers')->name('printers.')->group(function () {
        Route::get('/', [PrinterController::class, 'index'])->name('index');
        Route::post('/', [PrinterController::class, 'store'])->name('store');
        Route::get('/{printer}', [PrinterController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '/{printer}', [PrinterController::class, 'update'])->name('update');
        Route::delete('/{printer}', [PrinterController::class, 'destroy'])->name('destroy');
        Route::post('/{printer}/test-print', [PrinterController::class, 'testPrint'])->name('test-print');
    });

    // [Wave F F-2 / Sprint 1C] Payment terminals (TPE) — per-TPE fee tracking.
    Route::prefix('payment-terminals')->name('paymentTerminals.')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\PaymentTerminalController::class, 'index'])->name('index');
        Route::post('/', [App\Http\Controllers\Admin\PaymentTerminalController::class, 'store'])->name('store');
        Route::get('/{payment_terminal}', [App\Http\Controllers\Admin\PaymentTerminalController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '/{payment_terminal}', [App\Http\Controllers\Admin\PaymentTerminalController::class, 'update'])->name('update');
        Route::delete('/{payment_terminal}', [App\Http\Controllers\Admin\PaymentTerminalController::class, 'destroy'])->name('destroy');
    });

    // [GOAL-CAISSE-UNIFIED W-HIST 2026-05-30] Unified read-only order history
    // (/admin/historique) — Borne + Caisse + walk-in + delivery + online in ONE
    // view. Thin read layer over OrderService::list; no source filter forced
    // server-side. Distinct from pos-order (POS-source) and online-order (web).
    Route::prefix('order-history')->name('orderHistory.')->group(function () {
        Route::get('/', [OrderHistoryController::class, 'index'])->name('index');
        Route::get('show/{order}', [OrderHistoryController::class, 'show'])->name('show');
    });

    Route::prefix('pos-order')->name('posOrder.')->group(function () {
        Route::get('/', [PosOrderController::class, 'index']);
        // [COMMANDES EN SOUFFRANCE 2026-08-19] Non terminées ANTÉRIEURES à la journée de service.
        // Depuis la fenêtre glissante du tableau de suivi, elles étaient devenues invisibles :
        // 577 en base au 2026-08-19, dont 486 payées, la plus ancienne du 2026-05-28. Lecture
        // seule ; les actions passent par les routes existantes (change-status, refund…), qui
        // gardent toutes leurs permissions. `throttle` aligné sur les lectures du tableau.
        Route::get('/stale', [PosOrderController::class, 'stale'])
            ->middleware('throttle:60,1')
            ->name('stale');
        Route::get('show/{order}', [PosOrderController::class, 'show']);
        Route::delete('/{order}', [PosOrderController::class, 'destroy']);
        Route::get('/export', [PosOrderController::class, 'export']);
        // [V1.0.2-IDEMP-01] Idempotency added on change-status — see
        // reports/test-e2e/goal-2026-05-18/round-4/build-5-routes-evidence.md.
        // Status A→B transitions: middleware uses payload hash so A→B vs A→A
        // produce different keys; replay of identical A→B is safe no-op via
        // controller state-machine guards.
        Route::post('/change-status/{order}', [PosOrderController::class, 'changeStatus'])
            ->middleware(['throttle:pos-order-update', 'idempotency'])
            ->name('change-status');
        Route::post('/change-payment-status/{order}', [PosOrderController::class, 'changePaymentStatus'])
            ->middleware(['throttle:pos-order-update', 'idempotency']);
        Route::post('/select-delivery-boy/{order}', [PosOrderController::class, 'selectDeliveryBoy'])
            ->middleware(['throttle:pos-order-update', 'idempotency']);
        // [SPRINT-5] Quick re-order — returns structured cart payload for rapid re-import
        Route::get('/reorder-items/{order}', [PosOrderController::class, 'reorderItems'])->name('reorderItems');
        // [P11-FZH / F-VERIFY-08-02] NF525 counter-entry refund — creates a mirror order
        // in the current Z window (parent stays immutable). Use this instead of
        // changeStatus → RETURNED for orders sealed by a closed Z report.
        Route::post('/{order}/refund-with-counter-entry', [PosOrderController::class, 'refundWithCounterEntry'])
            ->middleware(['throttle:pos-order-update', 'idempotency'])
            ->name('refundWithCounterEntry');
        // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier loyalty redeem
        // (Option B per plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md). New
        // standalone controller (PosController.php is DIRTY — observe-only).
        // Permission gate `pos.redeem-loyalty` enforced inside the FormRequest.
        Route::post('/{order}/redeem-loyalty', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'redeem'])
            ->middleware(['throttle:pos-order-update', 'idempotency'])
            ->name('redeem-loyalty');
        // [FIDÉLITÉ COMPTOIR 2026-08-10 · propriétaire] RATTACHER le client à la vente, pour que ses
        // points lui soient crédités. Mesuré : 1411 ventes de caisse arrivées à DELIVERED, UNE SEULE
        // rattachée à un client — le crédit fonctionnait, personne ne pouvait dire à qui créditer.
        // Porte `permission:pos` (faire cumuler n'est pas dépenser) ; `idempotency` parce qu'un
        // double appui ne doit pas relancer deux fois le crédit.
        Route::post('/{order}/attach-loyalty', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'attachCustomer'])
            ->middleware(['throttle:pos-order-update', 'idempotency'])
            ->name('attach-loyalty');
    });

    Route::prefix('online-order')->name('onlineOrder.')->group(function () {
        Route::get('/', [OnlineOrderController::class, 'index']);
        Route::get('/show/{order}', [OnlineOrderController::class, 'show']);
        // [TERRAIN-HEAL 2026-07-16 · DEAD-ROUTE-ONLINE] route DELETE morte retirée :
        // OnlineOrderController::destroy n'existe pas → 500 sur appel. Aucune UI ne l'appelle.
        Route::get('/export', [OnlineOrderController::class, 'export']);
        Route::get('/pdf', [OnlineOrderController::class, 'pdf']);
        // [V1.0.2-IDEMP-01] idempotency on online-order change-status — see L856 comment.
        Route::post('/change-status/{order}', [OnlineOrderController::class, 'changeStatus'])
            ->middleware('idempotency')
            ->name('change-status');
        Route::post('/change-payment-status/{order}', [OnlineOrderController::class, 'changePaymentStatus'])->middleware('idempotency');
        Route::post('/select-delivery-boy/{order}', [OnlineOrderController::class, 'selectDeliveryBoy'])->middleware('idempotency');
    });

    Route::prefix('table-order')->name('tableOrder.')->group(function () {
        Route::get('/', [AdminTableOrderController::class, 'index']);
        Route::get('/show/{order}', [AdminTableOrderController::class, 'show']);
        // [TERRAIN-HEAL 2026-07-16 · DEAD-ROUTE-TABLE] route DELETE morte retirée :
        // TableOrderController::destroy n'existe pas → 500 sur appel. Aucune UI ne l'appelle.
        Route::get('/export', [AdminTableOrderController::class, 'export']);
        // [V1.0.2-IDEMP-01] idempotency on table-order change-status — see L856 comment.
        Route::post('/change-status/{order}', [AdminTableOrderController::class, 'changeStatus'])
            ->middleware('idempotency')
            ->name('change-status');
        Route::post('/change-payment-status/{order}', [AdminTableOrderController::class, 'changePaymentStatus'])->middleware('idempotency');
        Route::post('/token-create/{order}', [AdminTableOrderController::class, 'tokenCreate']);
    });

    Route::prefix('push-notification')->name('push-notification.')->group(function () {
        Route::get('/', [PushNotificationController::class, 'index']);
        Route::post('/', [PushNotificationController::class, 'store']);
        Route::get('/show/{pushNotification}', [PushNotificationController::class, 'show']);
        Route::delete('/{pushNotification}', [PushNotificationController::class, 'destroy']);
        Route::get('/export', [PushNotificationController::class, 'export']);
    });

    Route::prefix('administrator')->name('administrator.')->group(function () {
        Route::get('/', [AdministratorController::class, 'index']);
        Route::get('/show/{administrator}', [AdministratorController::class, 'show']);
        Route::post('/', [AdministratorController::class, 'store']);
        Route::match(['post', 'put', 'patch'], '/{administrator}', [AdministratorController::class, 'update']);
        Route::delete('/{administrator}', [AdministratorController::class, 'destroy']);

        Route::get('/export', [AdministratorController::class, 'export']);
        Route::post('/change-password/{administrator}', [AdministratorController::class, 'changePassword']);
        Route::post('/change-image/{administrator}', [AdministratorController::class, 'changeImage']);

        Route::get('/my-order/{administrator}', [AdministratorController::class, 'myOrder']);

        Route::get('/address/{administrator}', [AdministratorAddressController::class, 'index']);
        Route::get('/address/show/{administrator}/{address}', [AdministratorAddressController::class, 'show']);
        Route::post('/address/{administrator}', [AdministratorAddressController::class, 'store']);
        Route::match(
            ['put', 'patch'],
            '/address/{administrator}/{address}',
            [AdministratorAddressController::class, 'update']
        );
        Route::delete('/address/{administrator}/{address}', [AdministratorAddressController::class, 'destroy']);
    });

    Route::prefix('timezone')->name('timezone.')->group(function () {
        Route::get('/', [TimezoneController::class, 'index']);
    });

    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/total-sales', [DashboardController::class, 'totalSales']);
        Route::get('/total-orders', [DashboardController::class, 'totalOrders']);
        Route::get('/total-customers', [DashboardController::class, 'totalCustomers']);
        Route::get('/total-menu-items', [DashboardController::class, 'totalMenuItems']);
        Route::get('/order-statistics', [DashboardController::class, 'orderStatistics']);
        Route::get('/order-summary', [DashboardController::class, 'orderSummary']);
        Route::get('/sales-summary', [DashboardController::class, 'salesSummary']);
        Route::get('/customer-states', [DashboardController::class, 'customerStates']);
        Route::get('/top-customers', [DashboardController::class, 'topCustomers']);
        Route::get('/featured-items', [DashboardController::class, 'featuredItems']);
        Route::get('/popular-items', [DashboardController::class, 'mostPopularItems']);
        // [PHASE-6] Nouveaux endpoints Dashboard Boss
        Route::get('/realtime-report', [DashboardController::class, 'realtimeReport']);
        Route::get('/sla-alerts', [DashboardController::class, 'slaAlerts']);
        Route::get('/channel-statistics', [DashboardController::class, 'channelStatistics']);
        Route::get('/audit-trail', [DashboardController::class, 'auditTrail']);
        // [V102-08 HEAL-3 2026-05-26] One-click EOD PDF synthesis for owner/
        // accountant. POST (per spec) ; permission `pos-manage-fiscal` enforced
        // in DashboardController::__construct (separate from :dashboard).
        Route::post('/eod-pdf', [DashboardController::class, 'eodPdf']);
    });

    Route::prefix('sales-report')->name('sales-report.')->group(function () {
        Route::get('/', [SalesReportController::class, 'index']);
        Route::get('/export', [SalesReportController::class, 'export']);
        Route::get('/pdf', [SalesReportController::class, 'pdf']);
        Route::get('/overview', [SalesReportController::class, 'salesReportOverview']);
    });

    Route::prefix('items-report')->name('items-report.')->group(function () {
        Route::get('/', [ItemsReportController::class, 'index']);
        Route::get('/export', [ItemsReportController::class, 'export']);
        Route::get('/pdf', [ItemsReportController::class, 'pdf']);
    });

    Route::prefix('credit-balance-report')->name('credit-balance-report.')->group(function () {
        Route::get('/', [CreditBalanceReportController::class, 'index']);
        Route::get('/export', [CreditBalanceReportController::class, 'export']);
    });

    // [Wave O — O4 2026-05-20] Admin daily cash sessions read-only report.
    // Owner request : « voir les caisses chaque jour, début + fin, et toutes
    // les transactions de chaque jour ». Reuses pos-manage-fiscal permission
    // (cohérent avec Z/X reports — cash drawer reconciliation EST fiscal data).
    Route::prefix('cash-sessions-report')->name('cash-sessions-report.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\CashSessionReportController::class, 'index'])->name('index');
    });

    // [Wave X — X4 2026-05-21] Admin unified cash & transactions overview.
    // Owner mandate : « toutes les commandes encaissées (POS direct, borne
    // cash-collected, livreur) au MÊME ENDROIT en base ». Sibling to O4:
    // O4 lists CashDrawerSession rows day-by-day; X4 lists every Transaction
    // across all sources with derived source + mode buckets for daily écart
    // reconciliation. Reuses the `cash-sessions-report` permission (same
    // role gate — Admin + Branch Manager).
    Route::prefix('cash-overview')->name('cash-overview.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\CashOverviewController::class, 'index'])->name('index');
    });

    Route::prefix('message')->name('message.')->middleware(['auth:sanctum', 'block_kiosk_machine'])->group(function () {
        Route::get('/', [MessageController::class, 'index']);
        Route::get('/show/{message}', [MessageController::class, 'show']);
        Route::post('/', [MessageController::class, 'store']);
        // [NC-MSG-UPDATE-DEAD heal 2026-06-01] Removed dead route — MessageController has no
        // update() method (index/show/store/destroy/changeStatus only); PUT/PATCH 500'd.
        Route::delete('/{message}', [MessageController::class, 'destroy']);
        Route::get('/change-status/{message}/{customer}', [MessageController::class, 'changeStatus']);
    });

    Route::prefix('country-code')->name('country-code.')->group(function () {
        Route::get('/', [CountryCodeController::class, 'index']);
        Route::get('/show/{country}', [CountryCodeController::class, 'show']);
    });

    Route::prefix('transaction')->name('transaction.')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/export', [TransactionController::class, 'export']);
    });

    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [SimpleUserController::class, 'index']);
        Route::post('/', [SimpleUserController::class, 'store']);
        Route::get('/address/{customer}', [SimpleUserController::class, 'addresses']);
        Route::post('/address/{customer}', [SimpleUserController::class, 'storeAddress']);
        Route::match(['put', 'patch'], '/address/{customer}/{address}', [SimpleUserController::class, 'updateAddress']);
    });

    // [FIDÉLITÉ COMPTOIR 2026-08-10 · propriétaire] IDENTIFIER le client au comptoir.
    //
    // Le chaînon qui manquait à toute la fidélité de caisse : le logiciel savait créditer
    // (`AwardLoyaltyPointsOnDelivery` lit `orders.loyalty_customer_code`), débiter
    // (`pos-order/{order}/redeem-loyalty`), et la commande de caisse acceptait déjà le champ de
    // rattachement (`PosOrderRequest:215`, persisté `OrderService:1181`). Personne ne pouvait dire
    // QUI est le client — d'où 2 lignes de gain « surface caisse » dans toute la base.
    //
    // Trois entrées : téléphone (le moyen préféré du propriétaire), code fidélité, ou QR scanné avec
    // la caméra de la tablette (il n'y a pas de lecteur de code-barres).
    //
    // Porte : `permission:pos` via la FormRequest — identifier n'est pas débiter, un caissier qui n'a
    // que le droit de faire cumuler doit pouvoir rattacher. Débit limité : la route dit si un numéro
    // possède un compte, c'est un oracle d'énumération.
    Route::prefix('pos-loyalty')->name('posLoyalty.')->group(function () {
        Route::post('/lookup', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'lookup'])
            ->middleware('throttle:pos-loyalty-lookup')
            ->name('lookup');

        // L'HISTORIQUE des points d'un client — « pourquoi j'ai ce solde ? ». En GET : c'est une
        // lecture, sans effet, rejouable. Le grand-livre existait et immuable depuis des mois ; il
        // n'était lu nulle part, donc un solde contesté ne se défendait pas.
        Route::get('/history', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'history'])
            ->middleware('throttle:pos-loyalty-lookup')
            ->name('history');

        // INSCRIRE un client au comptoir. `idempotency` parce qu'un double appui sur « Créer » ne
        // doit pas produire deux comptes — et parce que le service lui-même retrouve un numéro déjà
        // connu, la protection est double, pas unique.
        Route::post('/customers', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'createCustomer'])
            ->middleware(['throttle:pos-loyalty-lookup', 'idempotency'])
            ->name('customers.store');

        // [FIDÉLITÉ COMPTOIR 2026-08-14 · propriétaire] CRÉDITER manuellement un montant en euros
        // sur le compte d'un client, hors vente. `idempotency` : un double appui ne doit pas
        // créditer deux fois le même geste commercial.
        Route::post('/credit-manual', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'creditManual'])
            ->middleware(['throttle:pos-loyalty-lookup', 'idempotency'])
            ->name('credit-manual');

        // [FIDÉLITÉ COMPTOIR 2026-08-14 · propriétaire] RETIRER manuellement des points — correction
        // d'un sur-crédit sans jamais annuler l'écriture déjà posée (grand-livre append-only).
        Route::post('/deduct-manual', [\App\Http\Controllers\Admin\PosLoyaltyController::class, 'deductManual'])
            ->middleware(['throttle:pos-loyalty-lookup', 'idempotency'])
            ->name('deduct-manual');
    });

    Route::prefix('pos-category')->name('pos-category.')->group(function () {
        Route::get('/', [PosCategoryController::class, 'index']);
    });

    Route::prefix('dining-table')->name('dining-table.')->group(function () {
        Route::get('/', [DiningTableController::class, 'index']);
        Route::get('/show/{diningTable}', [DiningTableController::class, 'show']);
        Route::post('/', [DiningTableController::class, 'store']);
        Route::match(['post', 'put', 'patch'], '/{diningTable}', [DiningTableController::class, 'update']);
        Route::delete('/{diningTable}', [DiningTableController::class, 'destroy']);
        Route::get('/export', [DiningTableController::class, 'export']);
    });
    Route::prefix('kds-order')->name('kdsOrder.')->group(function () {
        Route::get('/', [KitchenDisplaySystemController::class, 'index']);
        // [V1.0.2-IDEMP-01] idempotency on kds change-status — see L856 comment.
        // KDS already uses OrderStateMachine guards so replay is provably no-op
        // when target == current (StateMachine throws InvalidTransition on dup).
        Route::post('/change-status/{order}', [KitchenDisplaySystemController::class, 'changeStatus'])
            ->middleware(['idempotency', 'throttle:kds-bump'])
            ->name('change-status');
        Route::get('/items', [KitchenDisplaySystemController::class, 'orderItems']);
        // [F-03 / Lot 1.C] Adaptive polling fallback when WebSocket is degraded.
        Route::get('/sync', [KdsSyncController::class, 'sync']);
        // [Wave X3 2026-05-21] KDS Historique du jour — read-only V1 day-history viewer.
        // Returns today's PREPARED/OUT_FOR_DELIVERY/DELIVERED orders for the
        // branch (admin sees all), sorted updated_at desc, capped 50. Revert
        // (PREPARED → PREPARING) deferred V1.0.2 (OrderStateMachine §7 LOCK).
        Route::get('/history-today', [KitchenDisplaySystemController::class, 'historyToday'])
            ->middleware('throttle:60,1')
            ->name('history-today');
        // [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B] Chef
        // "Annuler bump" within 60s. Compensating action — orders.status is
        // NOT mutated. See KitchenDisplaySystemController::recall +
        // KitchenDisplaySystemOrderService::recall for the NF525-safe append-only
        // invariant proof. Mirrors `change-status` middleware so idempotent
        // replays + per-bump rate-limiting apply identically.
        Route::post('/recall/{order}', [KitchenDisplaySystemController::class, 'recall'])
            ->middleware(['idempotency', 'throttle:kds-bump'])
            ->name('recall');
        // [REMETTRE-EN-PRÉPARATION 2026-08-13 · owner] « Au cas où je valide une commande alors
        // qu'elle n'est pas terminée. » Distinct de `recall` juste au-dessus, qui ne touche
        // JAMAIS au statut (contrat verrouillé) et expire en 60 s : celui-ci fait réellement
        // REVENIR la commande en préparation, sans fenêtre de temps — la borne est le statut
        // (seule une commande PRÊTE se rouvre), pas un minuteur.
        Route::post('/reopen/{order}', [KitchenDisplaySystemController::class, 'reopen'])
            ->middleware(['idempotency', 'throttle:kds-bump'])
            ->name('reopen');
    });

    // [NEW-04] Observability surface — non-blocking telemetry rollups + ingestion.
    Route::prefix('observability')->name('observability.')->group(function () {
        Route::get('/sync-overview', [SyncOverviewController::class, 'index'])->name('sync-overview');

        // [PILOTAGE 2026-08-09] « Est-ce que ça va ? » en un seul appel : les cinq
        // contrôles de healthz, la fraîcheur de la dernière sauvegarde et le
        // battement du planificateur. Agrège ce qui existe déjà, n'invente aucune
        // mesure — jusqu'ici le système se surveillait sans rien en dire.
        Route::get('/system-health', [SyncOverviewController::class, 'systemHealth'])->name('system-health');

        // [PILOTAGE 2026-08-09] Les bascules actionnables sans deploiement.
        // Liste BLANCHE cote service : `idempotency.enabled` en est exclu
        // volontairement — c'est une protection NF525, pas une option.
        Route::get('/interrupteurs', [InterrupteurController::class, 'index'])->name('interrupteurs.index');
        Route::put('/interrupteurs/{nom}', [InterrupteurController::class, 'update'])->name('interrupteurs.update');
        Route::post('/client-metrics', [SyncOverviewController::class, 'clientMetrics'])
            ->middleware('throttle:60,1')
            ->name('client-metrics');

        // [CV1-OBSERVABILITY-OUTBOX-001] Outbox pipeline dashboard. Admin/Tenant
        // Admin only — read aggregates the entire fleet (all branches), and
        // retry / drain mutate the queue. Role gate is enforced inside the
        // controller via `role:Admin|Tenant Admin` middleware (see __construct).
        Route::get('/outbox', [SyncOverviewController::class, 'outboxOverview'])->name('outbox.index');
        Route::post('/outbox/retry-failed', [SyncOverviewController::class, 'outboxRetryFailed'])
            ->middleware('throttle:10,1')
            ->name('outbox.retry');
        Route::post('/outbox/drain-failed', [SyncOverviewController::class, 'outboxDrainFailed'])
            ->middleware('throttle:5,1')
            ->name('outbox.drain');
    });
    Route::prefix('oss-order')->name('ossOrder.')->group(function () {
        Route::get('/', [OrderStatusScreenController::class, 'index']);
        Route::get('/popular-items', [OrderStatusScreenController::class, 'mostPopularItems']);
    });

    // [POS-9.4.9 / POS-GA-F-01/02] Fiscal Z/X report endpoints — NF525 compliance.
    // [POS-9-H.3.1 / F-C7] Mutating fiscal endpoints (open/close) carry
    // a dedicated throttle: 10 req/min per authenticated user. A legitimate
    // operator opens and closes at most 1 Z per day per branch — so 10/min
    // is still generous, yet blocks any accidental retry-storm or a
    // hostile actor from spamming `open` to inflate `z_reports.sequence_no`
    // (which is monotonic, gap-free, and signed — each wasted sequence is
    // a permanent accounting artefact).
    Route::prefix('fiscal')->name('fiscal.')->group(function () {
        Route::prefix('z-report')->name('zReport.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'index']);
            Route::post('/open', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'open'])
                ->middleware('throttle:10,1');
            Route::post('/close', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'close'])
                ->middleware('throttle:10,1');
            Route::get('/{zReport}', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'show']);
            Route::get('/{zReport}/pdf', [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'pdf']);
        });
        Route::get('/x-report', [\App\Http\Controllers\Admin\Fiscal\XReportController::class, 'show'])
            ->name('xReport.show');
    });
});

Route::prefix('frontend')->name('frontend.')->middleware(['installed', 'apiKey', 'localization'])->group(function () {
    Route::prefix('setting')->name('setting.')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
    });

    Route::prefix('page')->name('page.')->group(function () {
        Route::get('/', [FrontendPageController::class, 'index']);
        Route::get('/show/{page:slug}', [FrontendPageController::class, 'show']);
        Route::get('/page-info/{page}', [FrontendPageController::class, 'show']);
    });

    Route::prefix('subscriber')->name('subscriber.')->group(function () {
        // [SEC-26-2] Rate limit subscriber spam: 5 subscriptions/min per IP
        Route::post('/', [FrontendSubscriberController::class, 'store'])
            ->middleware('throttle:5,1');
    });

    Route::prefix('address')->name('address.')->middleware(['auth:sanctum', 'block_kiosk_machine'])->group(function () {
        Route::get('/', [FrontendAddressController::class, 'index']);
        Route::get('/{address}', [FrontendAddressController::class, 'show']);
        Route::get('/show/{address}', [FrontendAddressController::class, 'show']);
        Route::post('/', [FrontendAddressController::class, 'store']);
        Route::match(['put', 'patch'], '/{address}', [FrontendAddressController::class, 'update']);
        Route::delete('/{address}', [FrontendAddressController::class, 'destroy']);
    });

    Route::prefix('branch')->name('branch.')->group(function () {
        Route::get('/', [FrontendBranchController::class, 'index']);
        Route::get('/show/{branch}', [FrontendBranchController::class, 'show']);
        Route::get('/lat-long', [FrontendBranchController::class, 'showByLatLong']);
    });

    // [iter15-mega-fix C-016 2026-05-10] Public OSS read for customer wall
    // display. The customer screen at `/admin/order-status-screen` is
    // mounted on a public TV — landing on a 401 (because the SPA fired
    // GET /api/admin/oss-order without a session) made the columns silently
    // empty. This sibling endpoint returns the same `CDSOrderDetailsResource`
    // payload (id / order_serial_no / token / queue_number / order_type /
    // status — no PII) scoped to a branch resolved from `?branch_id=` or
    // the first active branch. Throttle 120/min/IP: customer screens poll
    // every 5–60s and a fleet of walls behind one NAT must not 429.
    // [Sprint H5-B Z4-P2-05 2026-05-17] Throttle moved to named limiter
    // `oss-public` (60/min/IP). See App\Providers\RouteServiceProvider for
    // rationale (anti branch_id enumeration on unauthenticated wall feed).
    Route::get('/oss-order', [\App\Http\Controllers\Admin\OrderStatusScreenController::class, 'publicIndex'])
        ->middleware('throttle:oss-public')
        ->name('oss-order.public');
    Route::get('/oss-order/popular-items', [\App\Http\Controllers\Admin\OrderStatusScreenController::class, 'publicMostPopularItems'])
        ->middleware('throttle:oss-public')
        ->name('oss-order.popular-items.public');

    Route::prefix('language')->name('language.')->group(function () {
        Route::get('/', [FrontendLanguageController::class, 'index']);
        Route::get('/show/{language}', [FrontendLanguageController::class, 'show']);
    });

    // [iter15-P0-08] Ability enforcement on state-changing kiosk/customer order
    // routes is performed inside OrderRequest::authorize() (see
    // app/Http/Requests/OrderRequest.php). It checks tokenCan('kiosk:order')
    // against the caller's PersonalAccessToken abilities, with a documented
    // tolerance for non-token guard auth (TransientToken / session-resolved
    // user). Route-level `abilities:kiosk:order` middleware was considered
    // but rejected because Sanctum's CheckAbilities throws 401 when the
    // caller has no `currentAccessToken()` — that would break legitimate
    // session/guard-based callers including existing test fixtures.
    // The FormRequest path closes the original gap (OrderRequest::authorize
    // previously returned true unconditionally) without that collateral.
    // [GOAL WEB COMMANDE Wave D 2026-07-28] Estimation d'attente retrait —
    // PUBLIC (pas d'auth:sanctum : affichée AVANT login/panier sur le site),
    // lecture seule (file cuisine SSOT KitchenReleaseRule), throttle 30/min
    // (endpoint public = vecteur d'abus, §0.5.3 discipline). Déclarée AVANT le
    // groupe auth `order` pour ne pas hériter de son middleware.
    Route::get('order/wait-estimate', [FrontendOrderController::class, 'waitEstimate'])
        ->middleware('throttle:30,1')
        ->name('order.wait-estimate');

    // [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Suivi public d'une commande par
    // tracking_token opaque — PUBLIC (lien envoyé/affiché au client, pas de
    // login), lecture seule, throttle 30/min (même discipline que wait-estimate,
    // endpoint public = vecteur d'abus). Contrainte `[A-Za-z0-9]{48}` = forme
    // exacte de Str::random(48) (Order::boot()) — un token malformé ne matche
    // jamais cette route (donc jamais le contrôleur, zéro requête DB gaspillée) ;
    // il tombe sur l'attrape-tout SPA `/{any}` de routes/web.php (200 HTML), pas
    // un 404 JSON — comportement partagé par toute route API mal formée dans
    // cette app, pas spécifique à ce endpoint.
    Route::get('order/track/{trackingToken}', [FrontendOrderController::class, 'track'])
        ->where('trackingToken', '[A-Za-z0-9]{48}')
        ->middleware('throttle:30,1')
        ->name('order.track');

    // [T-C SUIVI-CLIENT 2026-08-16] QR borne → page de suivi. Throttle 30/min
    // aussi : chargé une seule fois par écran d'attente (pas de polling), la
    // borne n'appelle jamais assez souvent pour approcher la limite.
    //
    // [WAVE-D HEAL 2026-08-16 · P0] `withoutMiddleware('apiKey')` — consommé
    // par un <img :src="trackQrUrl"> BRUT (KioskWaitingComponent.vue) : un
    // navigateur ne peut PAS attacher d'en-tête custom à une requête <img>,
    // donc l'image était TOUJOURS cassée (400 "Clé API invalide", vérifié en
    // Playwright réel : naturalWidth=0) — un bug de naissance de la feature,
    // pas un accident d'environnement. `order/track` et `order/wait-estimate`
    // ci-dessus restent SOUS apiKey car ils sont consommés via axios (l'en-tête
    // est injecté par l'intercepteur, resources/js/shared/axios-setup.js) — ce
    // n'est QUE track-qr, unique consommateur <img>, qui doit en être exempté.
    // Le commentaire d'ApiKeyMiddleware::handle() le dit déjà explicitement :
    // cette clé "n'est pas un secret" (publiée en clair dans les bundles JS/
    // meta HTML) — seuls throttle:30,1 (conservé) et le format {48} du token
    // protègent réellement cette route publique, exactement comme order/track.
    Route::get('order/track-qr/{trackingToken}', [FrontendOrderController::class, 'trackQr'])
        ->where('trackingToken', '[A-Za-z0-9]{48}')
        ->withoutMiddleware('apiKey')
        ->middleware('throttle:30,1')
        ->name('order.track-qr');

    Route::prefix('order')->name('order.')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [FrontendOrderController::class, 'index']);
        Route::get('/show/{frontendOrder}', [FrontendOrderController::class, 'show']);
        // [TICKET-UNIFY 2026-07-01] Octets ESC/POS du ticket borne (client|cuisine) — MÊME
        // renderer serveur que la caisse → ticket papier identique. Garde de propriété + token borne.
        Route::get('/show/{frontendOrder}/escpos', [FrontendOrderController::class, 'escpos'])->name('escpos-bytes');
        // [RATE-FIX 2026-07-10] quote = bucket dédié « kiosk-quote » (aperçu prix, lecture seule) :
        // ne consomme plus le budget de création de commandes → fin des 429 « Trop de requêtes »
        // quand on compose/enchaîne des commandes.
        Route::post('/quote', [PosController::class, 'quote'])->middleware('throttle:kiosk-quote');
        // [APPS 2026-08-19] `require_customer_phone` : un compte ouvert par connexion
        // Apple/Google n'apporte aucun téléphone, et l'exploitation doit pouvoir appeler
        // le client (rupture, cuisson, commande non retirée). L'écran de l'application le
        // réclame déjà, mais un écran se contourne en fermant l'app — le refus vit donc
        // ici. Sans effet sur la BORNE (jeton `kiosk-token`) ni sur les clients venus par
        // le parcours téléphone, dont le compte est créé À PARTIR de leur numéro.
        Route::post('/', [FrontendOrderController::class, 'store'])->middleware(['throttle:kiosk-orders', 'require_customer_phone', 'idempotency']);
        // [V1.0.2-IDEMP-01] idempotency on frontend order change-status — see L856 comment.
        // [P0 2026-08-07] Jumelles de mollie-checkout : elles portent aussi une commande, donc
        // même garde de branche dérivée du serveur. Ces deux méthodes ne lisent PAS `branch_id`
        // dans la requête (elles utilisent `$frontendOrder->branch_id` et celui de la borne) :
        // l'injection leur est inerte, elle ne sert qu'au garde d'idempotence.
        Route::post('/change-status/{frontendOrder}', [FrontendOrderController::class, 'changeStatus'])
            ->middleware(['idempotency.branch', 'idempotency'])
            ->name('change-status');
        // [BORNE-WINDOWS] Confirm card payment from physical terminal — stores transaction_id
        Route::post('/{frontendOrder}/payment-confirm', [FrontendOrderController::class, 'paymentConfirm'])->middleware(['idempotency.branch', 'idempotency']);
        // [W5 Mollie 2026-07-20] Checkout carte web : crée le paiement Mollie (montant =
        // total scellé backend) d'une commande UNPAID du client → checkout_url hébergée.
        // FAIL-CLOSED 503 sans flag+clé (gate G-W5). Jamais de PAID ici (webhook seul).
        // [BRAIN RED 2026-08-03 P1] cardToken ⇒ la CRÉATION du paiement EST l'encaissement :
        // un retry (timeout client alors que Mollie a accepté) re-débiterait. `idempotency`
        // (comme les 3 routes sœurs) rejoue le 2xx caché pour la même X-Idempotency-Key.
        // [P0 PAIEMENT EN LIGNE 2026-08-07] `idempotency.branch` AVANT `idempotency` : la
        // branche est dérivée de la COMMANDE de la route, pas du corps envoyé par le client.
        // Sans lui, un compte de rôle « Customer » (branch_id=0, aucune borne) faisait
        // retomber le garde gelé sur `input('branch_id', -1)` → 422, et la requête
        // n'atteignait JAMAIS Mollie. Mesuré en production : 21 comptes sur 24 concernés,
        // carte comme portefeuille. Sentinelle : tests/Feature/Payment/IdempotencyBranchFromRouteTest.php
        // [OWNER 2026-08-08 · FEUILLE APPLE PAY NATIVE] Validation marchand : le navigateur
        // fournit une `validationURL` signée par Apple, on la relaie à Mollie qui répond une
        // session. Sans cette route, `ApplePaySession` ne peut pas s'ouvrir sur notre domaine et
        // il ne reste que la redirection vers une page hébergée. La route ne crée AUCUN paiement
        // et ne touche à aucune commande — d'où l'absence d'idempotence ; elle est en revanche
        // limitée en débit (une feuille par tentative) et n'accepte qu'une URL *.apple.com.
        Route::post('/applepay-session', [\App\Http\Controllers\Frontend\MolliePaymentController::class, 'applePaySession'])
            ->middleware('throttle:10,1')
            ->name('applepay-session');
        Route::post('/{frontendOrder}/mollie-checkout', [\App\Http\Controllers\Frontend\MolliePaymentController::class, 'checkout'])
            ->middleware(['idempotency.branch', 'idempotency', 'throttle:10,1'])
            ->name('mollie-checkout');
    });

    // [AUDIT-F-008] Payment confirm reconciliation queue — frontend persists TPE-approved
    // transactions whose backend confirmation failed (network blip / app crash post-TPE)
    // and replays them in batch on boot. Idempotent per UNIQUE(transaction_id).
    Route::prefix('payment')->name('payment.')->middleware(['auth:sanctum', 'throttle:5,1'])->group(function () {
        Route::post('/reconcile-pending', [\App\Http\Controllers\Frontend\PaymentReconcileController::class, 'reconcile']);
    });

    Route::prefix('offer')->name('offer.')->group(function () {
        Route::get('/', [FrontendOfferController::class, 'index']);
        Route::get('/show/{slug}', [FrontendOfferController::class, 'offerItems']);
        Route::get('/today', [FrontendOfferController::class, 'offerItemByDate']);
    });

    Route::prefix('item')->name('item.')->group(function () {
        Route::get('/', [FrontendItemController::class, 'index']);
        Route::get('/featured-items', [FrontendItemController::class, 'featuredItems']);
        Route::get('/popular-items', [FrontendItemController::class, 'mostPopularItems']);
        Route::get('/details/{item}', [FrontendItemController::class, 'itemDetails']);
        Route::get('/upsell/{item}', [FrontendItemController::class, 'upsell']);
        // [SPLASH] Smart kiosk upsell — GET /frontend/item/kiosk-upsell?item_ids=1,2,3
        Route::get('/kiosk-upsell', [FrontendItemController::class, 'kioskUpsell']);
    });

    Route::prefix('item-category')->name('item-category.')->group(function () {
        Route::get('/', [FrontendItemCategoryController::class, 'index']);
        Route::get('/show/{itemCategory:slug}', [FrontendItemCategoryController::class, 'show']);
    });

    Route::prefix('message')->name('message.')->middleware(['auth:sanctum', 'block_kiosk_machine'])->group(function () {
        Route::get('/', [FrontendMessageController::class, 'index']);
        Route::get('/show/{message}', [FrontendMessageController::class, 'show']);
        Route::post('/', [FrontendMessageController::class, 'store']);
        Route::match(['put', 'patch'], '/{message}', [FrontendMessageController::class, 'update']);
        Route::delete('/{message}', [FrontendMessageController::class, 'destroy']);
    });

    Route::prefix('time-slot')->name('time-slot.')->group(function () {
        Route::get('/today', [FrontendTimeSlotController::class, 'todayTimeSlot']);
        Route::get('/tomorrow', [FrontendTimeSlotController::class, 'tomorrowTimeSlot']);
    });

    Route::prefix('coupon')->name('coupon.')->group(function () {
        Route::get('/', [FrontendCouponController::class, 'index']);
        // [SEC-26-1] Rate limit coupon brute-force: 10 attempts/min per IP
        Route::post('/coupon-checking', [FrontendCouponController::class, 'couponChecking'])
            ->middleware('throttle:10,1');
    });

    /*
    | ROUE — surface publique. Sous `apiKey` comme le reste du frontend (cette clé n'est pas un
    | secret : elle est publiée dans le meta HTML du site). La vraie sécurité est ailleurs — le
    | tirage est serveur, le tour exige un jeton de validation signé émis au comptoir, et la porte
    | reste fermée au public tant que `wheel.enabled` est faux.
    |
    | Débit limité SERRÉ sur `spin` : c'est le seul endpoint du site qui DONNE quelque chose. Sans
    | limite, on balaye des milliers de numéros pour trouver ceux qui n'ont pas encore joué.
    */
    Route::prefix('wheel')->name('wheel.')->group(function () {
        Route::get('/config', [\App\Http\Controllers\Frontend\WheelController::class, 'config'])
            ->middleware('throttle:60,1');
        // Le SERVEUR horodate l'ouverture d'un lien : c'est la seule garde « il a pris le temps »
        // qui ne se contourne pas depuis le navigateur.
        Route::post('/step', [\App\Http\Controllers\Frontend\WheelController::class, 'step'])
            ->middleware('throttle:30,1');
        Route::post('/spin', [\App\Http\Controllers\Frontend\WheelController::class, 'spin'])
            ->middleware('throttle:10,1');
        // La RÉCLAMATION est l'endpoint qui donne réellement quelque chose : c'est lui qui crée la
        // participation, émet le code et crée le compte. Débit limité aussi serré que le tour.
        Route::post('/claim', [\App\Http\Controllers\Frontend\WheelController::class, 'claim'])
            ->middleware('throttle:10,1');
    });

    Route::prefix('slider')->name('slider.')->group(function () {
        Route::get('/', [FrontendSliderController::class, 'index']);
    });

    Route::prefix('country-code')->name('country-code.')->group(function () {
        Route::get('/', [FrontendCountryCodeController::class, 'index']);
        Route::get('/show/{country}', [FrontendCountryCodeController::class, 'show']);
    });

    Route::prefix('cookies')->name('cookies.')->group(function () {
        Route::get('/', [FrontendCookiesController::class, 'get']);
        Route::post('/', [FrontendCookiesController::class, 'set']);
    });

    Route::prefix('device-token')->name('device-token.')->middleware(['auth:sanctum'])->group(function () {
        Route::post('/web', [TokenStoreController::class, 'webToken']);
        Route::post('/mobile', [TokenStoreController::class, 'deviceToken']);
        Route::post('/kiosk', [TokenStoreController::class, 'kioskDeviceToken']);
    });

    Route::prefix('delivery-boy-order')->name('delivery-boy-order.')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [FrontendDeliveryBoyOrderController::class, 'index']);
        Route::get('/show/{order}', [FrontendDeliveryBoyOrderController::class, 'show']);
        Route::get('/count', [FrontendDeliveryBoyOrderController::class, 'orderCount']);
        // [V1.0.2-IDEMP-01] idempotency on delivery-boy change-status — see L856 comment.
        Route::post('/change-status/{order}', [FrontendDeliveryBoyOrderController::class, 'deliveryBoyOrderChangeStatus'])
            ->middleware('idempotency')
            ->name('change-status');
    });

    // [SEC-CRIT FIX] Loyalty routes now require auth:sanctum — previously fully unauthenticated
    // /register is kept public (no auth) as it creates a new loyalty account for a new customer
    // All other endpoints require a valid user session
    Route::prefix('loyalty')->name('loyalty.')->group(function () {
        // [AUDIT-P0-D] Add throttle to loyalty endpoints to prevent enumeration and mass registration.
        Route::post('/check', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'check'])->middleware(['auth:sanctum', 'throttle:10,1']);
        Route::post('/register', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'register'])->middleware('throttle:5,1');
        // [SPLASH] Kiosk reads conversion rates before showing loyalty UI
        Route::get('/config', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'config']);
    });
    Route::prefix('loyalty')->name('loyalty.auth.')->middleware(['auth:sanctum'])->group(function () {
        // [ULTRA-AUDIT Wave 2 2026-07-04] Idempotence sur le CRÉDIT de points, en miroir du
        // DÉBIT (/redeem ci-dessous) : même groupe auth:sanctum, même grand-livre
        // loyalty_transactions. Un double-POST / retry réseau créditait 2× les points + 2 lignes
        // de ledger (increment inconditionnel, aucune dédup — la contrainte UNIQUE (user_id,
        // order_id, type) ne protège pas car addPoints insère order_id=NULL → NULLs distincts sur
        // MySQL). La couche HTTP idempotency ferme la fenêtre (staff authentifié → user_id>0).
        Route::post('/add-points', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'addPoints'])
            ->middleware('idempotency');
        // [LCS-S-002 / 2026-05-19] Idempotency middleware on loyalty redeem.
        // Mobile sends Idempotency-Key header per B-02 spec but server ignored
        // it before this commit. Network retry = double-debit of points.
        // Route added to config/idempotency.php required_routes simultaneously.
        Route::post('/redeem', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'redeem'])
            ->middleware('idempotency');
        Route::get('/balance', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'balance']);
        Route::get('/history', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'history']);

        // [LCS-S-001 / 2026-05-19] Signed QR generation — authenticated customer
        // mints a fresh `lqr.<payload>.<hmac>` token (5-min TTL + anti-replay
        // nonce). Replaces the unsigned plaintext FK:<code> previously generated
        // client-side. Throttle:30/min/user matches the natural 12 mints/hour
        // (one every 5 min) with healthy retry headroom.
        Route::post('/qr', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'generateQr'])
            ->middleware('throttle:30,1')
            ->name('qr.generate');
    });

    // [C6] Kiosk observability — structured event logging for operators
    // Auth: kiosk:order ability; throttle: 30 events/min per token (prevents log spam)
    Route::post('/kiosk-event', [\App\Http\Controllers\Frontend\KioskEventController::class, 'store'])
        ->middleware(['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'])
        ->name('kiosk.event');

    /* ================================================================
     * Kiosk Design V1 — Phase 1 (master prompt)
     * ================================================================
     * Nouvelles routes kiosk scoped par `KioskMachine::branch_id`.
     * Les routes historiques (/coupon-checking, /kiosk-upsell,
     * /loyalty/register, /kiosk-event) RESTENT actives et intactes.
     */

    // 1.4 — GET /api/frontend/menu : payload unifié (1 round-trip kiosk).
    Route::get('/menu', [\App\Http\Controllers\Frontend\MenuController::class, 'kiosk'])
        ->middleware(['auth:sanctum', 'throttle:kiosk-menu', 'kiosk.locale'])
        ->name('frontend.menu.kiosk');

    // 1.5 — POST /api/frontend/pricing/preview : recalcul SSOT sans persistance.
    Route::post('/pricing/preview', [\App\Http\Controllers\Frontend\PricingPreviewController::class, 'preview'])
        ->middleware(['auth:sanctum', 'throttle:60,1', 'kiosk.locale'])
        ->name('frontend.pricing.preview');

    // 1.6 — POST /api/frontend/promo/validate : kiosk_promo prio + fallback coupons globaux.
    Route::post('/promo/validate', [\App\Http\Controllers\Frontend\PromoController::class, 'check'])
        ->middleware(['auth:sanctum', 'throttle:30,1', 'kiosk.locale'])
        ->name('frontend.promo.validate');

    // 1.7 — GET /api/frontend/upsell : suggestions via upsell_rules + fallback legacy.
    Route::get('/upsell', [\App\Http\Controllers\Frontend\UpsellController::class, 'suggest'])
        ->middleware(['auth:sanctum', 'throttle:60,1', 'kiosk.locale'])
        ->name('frontend.upsell.suggest');

    // 1.8 — POST /api/frontend/loyalty/opt-in : adhésion RGPD-compliant (consentement explicite).
    Route::post('/loyalty/opt-in', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'optIn'])
        ->middleware(['throttle:5,1'])
        ->name('frontend.loyalty.opt-in');

    // Phase 8.3 — POST /api/frontend/loyalty/scan : résolution QR/NFC kiosk.
    // Auth Sanctum + kiosk:order ability — Scan invoqué depuis le parcours
    // client. Toujours HTTP 200 pour ne pas bloquer le parcours (invariant §12).
    Route::post('/loyalty/scan', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'scan'])
        ->middleware(['auth:sanctum', 'throttle:20,1'])
        ->name('frontend.loyalty.scan');

    // 1.9 — POST /api/frontend/kiosk/event : alias slash (master prompt §1.6). Tiret historique conservé.
    // [K-6.1] Same ability enforcement as /kiosk-event — both aliases must fail-closed. [T08b]
    Route::post('/kiosk/event', [\App\Http\Controllers\Frontend\KioskEventController::class, 'store'])
        ->middleware(['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'])
        ->name('frontend.kiosk.event');

    // [C2 / K-9 ADR-5] POST /api/frontend/csp-report : endpoint anonyme pour
    // les rapports CSP envoyés par le navigateur (report-uri du meta
    // `Content-Security-Policy[-Report-Only]`). Définition déplacée hors
    // du groupe `frontend` (voir bloc juste après ce `})`) car ce groupe
    // applique `apiKey` qui rejette en 400 immédiatement les requêtes sans
    // header `x-api-key` — or le navigateur ne peut pas attacher d'header
    // applicatif sur un CSP report (invariant W3C).
});

// [iter15-mega-fix CSP-report 400→204 2026-05-10] Route déplacée hors du
// groupe `frontend` parce que `apiKey` middleware retournait 400 sans header
// `x-api-key` (impossible côté navigateur sur un CSP report). Throttle remonté
// à 1000/min/IP : un chargement de page peut déclencher 14-17 reports d'un
// coup, et l'ancien 20/min/IP saturait → 429. Reports passifs, pas actions
// user. Conserve `installed` pour cohérence app-wide, supprime `apiKey` +
// `localization`. Path et name historiques préservés pour les tests existants
// (CspReportEndpointTest, CorrelationIdEndToEndTest, ContentSecurityPolicyHeaderTest).
Route::post('/frontend/csp-report', [\App\Http\Controllers\Frontend\CspReportController::class, 'store'])
    ->middleware(['installed', 'throttle:1000,1'])
    ->name('frontend.csp.report');

Route::prefix('table')->name('table.')->middleware(['installed', 'apiKey', 'localization'])->group(function () {

    Route::prefix('item-category')->name('item-category.')->group(function () {
        Route::get('/', [TableItemCategoryController::class, 'index']);
        Route::get('/show/{itemCategory:slug}', [TableItemCategoryController::class, 'show']);
    });

    Route::prefix('dining-table')->name('dining-table.')->group(function () {
        Route::get('/', [TableDiningTableController::class, 'index']);
        Route::get('/show/{frontendDiningTable:slug}', [TableDiningTableController::class, 'show']);
    });

    Route::prefix('dining-order')->name('dining-order.')->group(function () {
        Route::get('/show/{frontendOrder}', [TableOrderController::class, 'show']);
        // [AUDIT-P1] Dedicated throttle: table ordering is unauthenticated (QR code), 20 orders/min per IP.
        Route::post('/', [TableOrderController::class, 'store'])->middleware('throttle:20,1');
    });
});
