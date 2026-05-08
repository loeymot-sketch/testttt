<?php


use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\OtpController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\ChefController;
use App\Http\Controllers\Admin\ItemController;
use App\Http\Controllers\Admin\MailController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SiteController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\ThemeController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\SliderController;
use App\Http\Controllers\Admin\WaiterController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CookiesController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\AnalyticController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\PosOrderController;
use App\Http\Controllers\Admin\TimeSlotController;
use App\Http\Controllers\Admin\TimezoneController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ItemAddonController;
use App\Http\Controllers\Admin\ItemExtraController;
use App\Http\Controllers\Admin\OfferItemController;
use App\Http\Controllers\Auth\DeactivateController;
use App\Http\Controllers\Admin\OrderSetupController;
use App\Http\Controllers\Admin\KioskSetupController;
use App\Http\Controllers\Admin\LoyaltySetupController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\SimpleUserController;
use App\Http\Controllers\Admin\SmsGatewayController;
use App\Http\Controllers\Admin\SubscriberController;
use App\Http\Controllers\Auth\GuestSignupController;
use App\Http\Controllers\Frontend\ProfileController;
use App\Http\Controllers\Frontend\SettingController;
use App\Http\Controllers\Admin\ChefAddressController;
use App\Http\Controllers\Admin\CountryCodeController;
use App\Http\Controllers\Admin\DeliveryBoyController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\AvailabilityController;
use App\Http\Controllers\Admin\StockToggleController;
use App\Http\Controllers\Admin\ItemsReportController;
use App\Http\Controllers\Admin\MenuProjectionController;
use App\Http\Controllers\Admin\MenuSectionController;
use App\Http\Controllers\Admin\OnlineOrderController;
use App\Http\Controllers\Admin\PosCategoryController;
use App\Http\Controllers\Admin\SalesReportController;
use App\Http\Controllers\Admin\SocialMediaController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Admin\ItemCategoryController;
use App\Http\Controllers\Admin\DeliveryPlatformController;
use App\Http\Controllers\Admin\DeliveryPlatformHealthController;
use App\Http\Controllers\Admin\KioskMachineController;
use App\Http\Controllers\Admin\MenuTemplateController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\AdministratorController;
use App\Http\Controllers\Admin\DefaultAccessController;
use App\Http\Controllers\Admin\ItemAttributeController;
use App\Http\Controllers\Admin\ItemVariationController;
use App\Http\Controllers\Admin\WaiterAddressController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Frontend\TokenStoreController;
use App\Http\Controllers\Admin\MyOrderDetailsController;
use App\Http\Controllers\Admin\PaymentGatewayController;
use App\Http\Controllers\Admin\AnalyticSectionController;
use App\Http\Controllers\Admin\CustomerAddressController;
use App\Http\Controllers\Admin\EmployeeAddressController;
use App\Http\Controllers\Admin\DeliveryBoyOrderController;
use App\Http\Controllers\Admin\PushNotificationController;
use App\Http\Controllers\Auth\KioskMachineLoginController;
use App\Http\Controllers\Admin\NotificationAlertController;
use App\Http\Controllers\Admin\OrderStatusScreenController;
use App\Http\Controllers\Admin\DeliveryBoyAddressController;
use App\Http\Controllers\Admin\CreditBalanceReportController;
use App\Http\Controllers\Admin\AdministratorAddressController;
use App\Http\Controllers\Admin\KitchenDisplaySystemController;
use App\Http\Controllers\Table\OrderController as TableOrderController;
use App\Http\Controllers\Frontend\ItemController as FrontendItemController;
use App\Http\Controllers\Frontend\PageController as FrontendPageController;
use App\Http\Controllers\Frontend\OfferController as FrontendOfferController;
use App\Http\Controllers\Frontend\OrderController as FrontendOrderController;
use App\Http\Controllers\Frontend\BranchController as FrontendBranchController;
use App\Http\Controllers\Frontend\CouponController as FrontendCouponController;
use App\Http\Controllers\Frontend\SliderController as FrontendSliderController;
use App\Http\Controllers\Admin\TableOrderController as AdminTableOrderController;
use App\Http\Controllers\Frontend\AddressController as FrontendAddressController;
use App\Http\Controllers\Frontend\CookiesController as FrontendCookiesController;
use App\Http\Controllers\Frontend\MessageController as FrontendMessageController;
use App\Http\Controllers\Frontend\LanguageController as FrontendLanguageController;
use App\Http\Controllers\Frontend\TimeSlotController as FrontendTimeSlotController;
use App\Http\Controllers\Table\DiningTableController as TableDiningTableController;
use App\Http\Controllers\Table\ItemCategoryController as TableItemCategoryController;
use App\Http\Controllers\Frontend\SubscriberController as FrontendSubscriberController;
use App\Http\Controllers\Frontend\CountryCodeController as FrontendCountryCodeController;
use App\Http\Controllers\Frontend\ItemCategoryController as FrontendItemCategoryController;
use App\Http\Controllers\Frontend\DeliveryBoyOrderController as FrontendDeliveryBoyOrderController;
use App\Http\Controllers\HealthController;
// [P0-4 / KIO-6] Fallback fiscal receipt PDF endpoint (download + email).
use App\Http\Controllers\PdfReceiptController;


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

// [PRE-PROD HARDENING / SYN-2 / P0-7] Pusher heartbeat client→server ack.
// Auth-only (sanctum), no `installed`/`apiKey` group: clients that hold
// a valid bearer token must be able to ack regardless of installation
// state. The endpoint is silent-fail by design (cf. PusherAckController).
// Mounted top-level so it follows the same pattern as /api/health.
Route::post('/internal/pusher-ack', [\App\Http\Controllers\Internal\PusherAckController::class, 'store'])
    ->middleware(['auth:sanctum'])
    ->name('internal.pusherAck');

Route::match(['get', 'post'], '/login', function () {
    return response()->json(['errors' => 'unauthenticated'], 401);
})->name('login');

// [AUDIT-P1] Added apiKey: token refresh must authenticate the client app, not be public.
Route::match(['get', 'post'], '/refresh-token', [RefreshTokenController::class, 'refreshToken'])->middleware(['installed', 'apiKey']);

Route::prefix('auth')->middleware(['installed', 'apiKey', 'localization'])->name('auth.')->namespace('Auth')->group(function () {
    // [SEC-02] Rate limiting — login lockout (named limiter)
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login-lockout');

    Route::post('/kiosk-login', [KioskMachineLoginController::class, 'login'])
        ->middleware('throttle:login-lockout');

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
            ->middleware('throttle:5,1');
        // [GAP-20-2] OTP verify: 3 per 5 minutes — anti brute-force.
        Route::post('/verify', [SignupController::class, 'verify'])
            ->middleware('throttle:3,5');
        Route::post('/register', [SignupController::class, 'register'])
            ->middleware('throttle:10,1');
    });

    Route::prefix('guest-signup')->name('guest-signup.')->group(function () {
        // [GAP-20-2] OTP send: 5 per minute (was 10) — limits SMS flood abuse.
        Route::post('/otp', [GuestSignupController::class, 'otp'])
            ->middleware('throttle:5,1');
        // [GAP-20-2] OTP verify: 3 per 5 minutes — prevents brute-force of 4-digit codes.
        // A 4-digit OTP has 10,000 combinations; at 3 attempts/5min the attacker needs
        // ~2,778 hours to exhaust all codes, well beyond the 5-minute expiry window.
        Route::post('/verify', [GuestSignupController::class, 'verify'])
            ->middleware('throttle:3,5');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('verify.api')->group(function () {
            Route::post('/logout', [LoginController::class, 'logout']);
            Route::post('/kiosk-logout', [KioskMachineLoginController::class, 'logout']);
            Route::post('/delete-account', [DeactivateController::class, 'deleteAccount']);
        });

        // [SEC-1 / P1-11] Kiosk token refresh — caller must hold a still-valid
        // kiosk token (kiosk:order ability). Issues a new token with the same
        // ability and a fresh kiosk_expiration TTL, then revokes the old one.
        // Throttled to prevent token churn / log noise (60/min/token is plenty
        // for an axios interceptor: realistically <1/day per kiosk).
        Route::post('/kiosk-refresh-token', [KioskMachineLoginController::class, 'refresh'])
            ->middleware(['abilities:kiosk:order', 'throttle:60,1'])
            ->name('kiosk-refresh-token');
    });

    Route::post('/authcheck', function () {
        if (Auth::check()) {
            $user = Auth::user();
            $role = $user->roles[0] ?? null;
            if (!$role) {
                return response()->json(['status' => true]);
            }

            $menuService       = app(\App\Services\MenuService::class);
            $permissionService = app(\App\Services\PermissionService::class);

            $permission        = \App\Http\Resources\PermissionResource::collection($permissionService->permission($role));
            $menus             = \App\Http\Resources\MenuResource::collection(collect($menuService->menu($role)));
            $defaultPermission = \App\Libraries\AppLibrary::defaultPermission($permission);
            $defaultMenu       = (object) \App\Libraries\AppLibrary::defaultMenu($menuService->menu($role), $defaultPermission);

            // [BUG-AUTH FIX] Apply landing_url override — same logic as LoginController lines 82-85
            // Without this, POS Operator loses their correct redirect URL after a page refresh
            if (!empty($role->landing_url)) {
                $defaultPermission->url = $role->landing_url;
            }

            return response()->json([
                'status'            => true,
                'token'             => null,
                'branch_id'         => (int) $user->branch_id,
                'user'              => new \App\Http\Resources\UserResource($user),
                'menu'              => $menus,
                'permission'        => $permission,
                'defaultPermission' => $defaultPermission,
                'defaultMenu'       => $defaultMenu,
            ]);
        }
        return response()->json(['status' => false]);
    });
});


/* all routes must be singular word*/
Route::prefix('profile')->name('profile.')->middleware(['installed', 'apiKey', 'auth:sanctum', 'localization'])->group(function () {
    Route::get('/', [ProfileController::class, 'profile']);
    Route::match(['put', 'patch'], '/', [ProfileController::class, 'update']);
    Route::match(['put', 'patch'], '/change-password', [ProfileController::class, 'changePassword']);
    Route::post('/change-image', [ProfileController::class, 'changeImage']);
});

Route::prefix('admin')->name('admin.')->middleware(['installed', 'apiKey', 'auth:sanctum', 'localization', 'throttle:admin-mutation'])->group(function () {
    Route::prefix('default-access')->name('default-access.')->group(function () {
        Route::get('/', [DefaultAccessController::class, 'index']);
        Route::post('/', [DefaultAccessController::class, 'storeOrUpdate']);
    });

    // [V1 SECTION 5] Dual-channel menu SSOT projection (read-only, admin-only).
    Route::get('/menu-projection', [MenuProjectionController::class, 'show'])
        ->name('menu-projection.show');
    Route::post('/menu/availability/toggle', [AvailabilityController::class, 'toggle'])
        ->name('menu.availability.toggle');

    // [F-016b minimal] Stock manager — owner-driven manual rupture per branch.
    // Items go through AvailabilityService (existing F-016 infra). Extras and
    // variations are listed but their toggle is gated until F-016a-BIS lands.
    Route::prefix('stock')->name('stock.')->group(function () {
        Route::get('/', [StockToggleController::class, 'index'])->name('index');
        Route::post('/toggle', [StockToggleController::class, 'toggle'])->name('toggle');
        Route::get('/audit', [StockToggleController::class, 'audit'])->name('audit');
    });

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
            Route::get('/show/{itemCategory}', [ItemCategoryController::class, 'show']);
            Route::post('/', [ItemCategoryController::class, 'store']);
            Route::match(['post', 'put', 'patch'], '/{itemCategory}', [ItemCategoryController::class, 'update']);
            Route::delete('/{itemCategory}', [ItemCategoryController::class, 'destroy']);
            Route::post('/sort/category', [ItemCategoryController::class, 'sortCategory']);
            Route::get('/export', [ItemCategoryController::class, 'export']);
            Route::get('/download-sample', [ItemCategoryController::class, 'downloadSample']);
            Route::post('/import/file', [ItemCategoryController::class, 'import']);
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

    /*
     * [PARALLEL-TRACK-1.4] Delivery Platform admin surface.
     *
     * Mounted directly under /api/admin (not under /api/admin/setting)
     * because the data model is its own first-class resource: a
     * delivery_platforms row is per-branch and edited from a dedicated
     * page in the admin UI, not from the generic settings tabs.
     *
     * Permission gate is `permission:settings` (same as kiosk-machine
     * + setting/* surfaces) — see DeliveryPlatformController for the
     * per-action middleware mapping. The reveal() endpoint enforces
     * an additional Admin-role check inside the controller.
     */
    Route::prefix('delivery-platforms')->name('delivery-platforms.')->group(function () {
        Route::get('/', [DeliveryPlatformController::class, 'index']);
        Route::get('/{id}', [DeliveryPlatformController::class, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], '/{id}', [DeliveryPlatformController::class, 'update'])->whereNumber('id');
        Route::post('/{id}/toggle', [DeliveryPlatformController::class, 'toggleEnabled'])->whereNumber('id');
        Route::post('/{id}/reveal', [DeliveryPlatformController::class, 'reveal'])->whereNumber('id');
        Route::get('/{id}/webhook-url', [DeliveryPlatformController::class, 'webhookUrl'])->whereNumber('id');
        Route::get('/{id}/health', [DeliveryPlatformHealthController::class, 'show'])->whereNumber('id');
        Route::post('/{id}/test-signature', [DeliveryPlatformHealthController::class, 'testSignature'])->whereNumber('id');
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
        Route::get('/show/{user}/{order}', [MyOrderDetailsController::class, 'orderDetails']);
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

    Route::prefix('coupon')->name('coupon.')->group(function () {
        Route::get('/', [CouponController::class, 'index']);
        Route::get('/show/{coupon}', [CouponController::class, 'show']);
        Route::post('/', [CouponController::class, 'store']);
        Route::match(['post', 'put', 'patch'], '/{coupon}', [CouponController::class, 'update']);
        Route::delete('/{coupon}', [CouponController::class, 'destroy']);
        Route::get('/export', [CouponController::class, 'export']);
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
        Route::get('/show/{item}', [ItemController::class, 'show']);
        Route::post('/', [ItemController::class, 'store']);
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

        Route::get('/addon/{item}', [ItemAddonController::class, 'index']);
        Route::post('/addon/{item}', [ItemAddonController::class, 'store']);
        Route::delete('/addon/{item}/{itemAddon}', [ItemAddonController::class, 'destroy']);
    });

    Route::prefix('pos')->name('pos.')->group(function () {
        Route::post('/', [PosController::class, 'store'])->middleware('throttle:pos-order-create');
    });

    Route::prefix('pos-order')->name('posOrder.')->group(function () {
        Route::get('/', [PosOrderController::class, 'index']);
        Route::get('show/{order}', [PosOrderController::class, 'show']);
        Route::delete('/{order}', [PosOrderController::class, 'destroy']);
        Route::get('/export', [PosOrderController::class, 'export']);
        Route::post('/change-status/{order}', [PosOrderController::class, 'changeStatus'])
            ->middleware('throttle:pos-order-update');
        Route::post('/change-payment-status/{order}', [PosOrderController::class, 'changePaymentStatus'])
            ->middleware('throttle:pos-order-update');
        Route::post('/select-delivery-boy/{order}', [PosOrderController::class, 'selectDeliveryBoy'])
            ->middleware('throttle:pos-order-update');
        // [SPRINT-5] Quick re-order — returns structured cart payload for rapid re-import
        Route::get('/reorder-items/{order}', [PosOrderController::class, 'reorderItems'])->name('reorderItems');
    });

    Route::prefix('online-order')->name('onlineOrder.')->group(function () {
        Route::get('/', [OnlineOrderController::class, 'index']);
        Route::get('/show/{order}', [OnlineOrderController::class, 'show']);
        Route::delete('/{order}', [OnlineOrderController::class, 'destroy']);
        Route::get('/export', [OnlineOrderController::class, 'export']);
        Route::get('/pdf', [OnlineOrderController::class, 'pdf']);
        Route::post('/change-status/{order}', [OnlineOrderController::class, 'changeStatus']);
        Route::post('/change-payment-status/{order}', [OnlineOrderController::class, 'changePaymentStatus']);
        Route::post('/select-delivery-boy/{order}', [OnlineOrderController::class, 'selectDeliveryBoy']);
    });

    Route::prefix('table-order')->name('tableOrder.')->group(function () {
        Route::get('/', [AdminTableOrderController::class, 'index']);
        Route::get('/show/{order}', [AdminTableOrderController::class, 'show']);
        Route::delete('/{order}', [AdminTableOrderController::class, 'destroy']);
        Route::get('/export', [AdminTableOrderController::class, 'export']);
        Route::post('/change-status/{order}', [AdminTableOrderController::class, 'changeStatus']);
        Route::post('/change-payment-status/{order}', [AdminTableOrderController::class, 'changePaymentStatus']);
        Route::post('/token-create/{order}', [AdminTableOrderController::class, 'tokenCreate']);
    });

    // [P0-4 / KIO-6] Fallback fiscal receipt — download a PDF or email it
    // to the customer when the kiosk's local printing chain fails.
    // Permission gate: pos-manage-fiscal (Admin / Branch Manager).
    Route::prefix('order')->name('order.')->group(function () {
        Route::get('/{orderId}/receipt-pdf', [PdfReceiptController::class, 'download'])
            ->where('orderId', '[0-9]+')
            ->name('receipt-pdf.download');
        Route::post('/{orderId}/receipt-pdf/email', [PdfReceiptController::class, 'emailToCustomer'])
            ->where('orderId', '[0-9]+')
            ->name('receipt-pdf.email');
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

    Route::prefix('message')->name('message.')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [MessageController::class, 'index']);
        Route::get('/show/{message}', [MessageController::class, 'show']);
        Route::post('/', [MessageController::class, 'store']);
        Route::match(['put', 'patch'], '/{message}', [MessageController::class, 'update']);
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
        Route::post('/change-status/{order}', [KitchenDisplaySystemController::class, 'changeStatus']);
        Route::get('/items', [KitchenDisplaySystemController::class, 'orderItems']);
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
            Route::get('/',          [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'index']);
            Route::post('/open',     [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'open'])
                ->middleware('throttle:10,1');
            Route::post('/close',    [\App\Http\Controllers\Admin\Fiscal\ZReportController::class, 'close'])
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

    Route::prefix('address')->name('address.')->middleware(['auth:sanctum'])->group(function () {
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

    Route::prefix('language')->name('language.')->group(function () {
        Route::get('/', [FrontendLanguageController::class, 'index']);
        Route::get('/show/{language}', [FrontendLanguageController::class, 'show']);
    });

    /*
     * [P0-5 / KIO-1] Kiosk boot healthcheck endpoint.
     * Public (apiKey + localization only — NO auth:sanctum) because the
     * kiosk hits this BEFORE authenticating as a KioskMachine. Burst-safe
     * throttle (60/min) since the boot retry button can be hit repeatedly.
     * @see app/Http/Controllers/Frontend/KioskHealthController.php
     */
    Route::get('/kiosk/health', [\App\Http\Controllers\Frontend\KioskHealthController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('kiosk.health');

    Route::prefix('order')->name('order.')->middleware(['auth:sanctum'])->group(function () {
        Route::get('/', [FrontendOrderController::class, 'index']);
        Route::get('/show/{frontendOrder}', [FrontendOrderController::class, 'show']);
        Route::post('/', [FrontendOrderController::class, 'store'])->middleware('throttle:kiosk-orders');
        Route::post('/change-status/{frontendOrder}', [FrontendOrderController::class, 'changeStatus']);
        // [BORNE-WINDOWS] Confirm card payment from physical terminal — stores transaction_id
        Route::post('/{frontendOrder}/payment-confirm', [FrontendOrderController::class, 'paymentConfirm']);
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

    Route::prefix('message')->name('message.')->middleware(['auth:sanctum'])->group(function () {
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
        Route::post('/change-status/{order}', [FrontendDeliveryBoyOrderController::class, 'deliveryBoyOrderChangeStatus']);
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
        Route::post('/add-points', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'addPoints']);
        Route::post('/redeem', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'redeem']);
        Route::get('/balance', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'balance']);
        Route::get('/history', [\App\Http\Controllers\Frontend\LoyaltyController::class, 'history']);
    });

    // [C6] Kiosk observability — structured event logging for operators
    // Auth: kiosk:order ability; throttle: 30 events/min per token (prevents log spam)
    Route::post('/kiosk-event', [\App\Http\Controllers\Frontend\KioskEventController::class, 'store'])
        ->middleware(['auth:sanctum', 'throttle:30,1'])
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
        ->middleware(['auth:sanctum', 'throttle:kiosk-menu'])
        ->name('frontend.menu.kiosk');

    // 1.5 — POST /api/frontend/pricing/preview : recalcul SSOT sans persistance.
    Route::post('/pricing/preview', [\App\Http\Controllers\Frontend\PricingPreviewController::class, 'preview'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
        ->name('frontend.pricing.preview');

    // 1.6 — POST /api/frontend/promo/validate : kiosk_promo prio + fallback coupons globaux.
    Route::post('/promo/validate', [\App\Http\Controllers\Frontend\PromoController::class, 'check'])
        ->middleware(['auth:sanctum', 'throttle:30,1'])
        ->name('frontend.promo.validate');

    // 1.7 — GET /api/frontend/upsell : suggestions via upsell_rules + fallback legacy.
    Route::get('/upsell', [\App\Http\Controllers\Frontend\UpsellController::class, 'suggest'])
        ->middleware(['auth:sanctum', 'throttle:60,1'])
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
    Route::post('/kiosk/event', [\App\Http\Controllers\Frontend\KioskEventController::class, 'store'])
        ->middleware(['auth:sanctum', 'throttle:30,1'])
        ->name('frontend.kiosk.event');
});

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

/*
|--------------------------------------------------------------------------
| [PARALLEL-TRACK-1.2] Delivery-platform webhook ingest
|--------------------------------------------------------------------------
|
| External aggregators (Uber Eats / Deliveroo / Delicity) post here when
| a customer places an order on their app. Trust pipeline:
|
|   1. throttle:delivery-webhooks  → 1000 req/min keyed by platform+IP.
|   2. delivery.verify-signature   → reads raw body, validates HMAC,
|                                    swallows replays, stashes branch_id
|                                    on the request attributes.
|   3. DeliveryWebhookController   → persists the row + dispatches the
|                                    queue job + returns 202.
|
| No `apiKey` middleware here — webhooks come from third parties whose
| call surface we cannot mutate. The signature gate is the trust boundary.
| No BranchScope (sanctum not authenticated): the middleware resolves
| branch_id from the platform config row, then sets it on the request.
*/
Route::post(
    '/webhooks/delivery/{platform}/{event}',
    \App\Http\Controllers\Webhook\DeliveryWebhookController::class
)
    ->where('platform', 'uber_eats|deliveroo|delicity')
    ->where('event',    '[a-z][a-z0-9._-]{0,63}')
    ->middleware(['throttle:delivery-webhooks', 'delivery.verify-signature'])
    ->name('webhooks.delivery');