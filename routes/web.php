<?php

use App\Http\Controllers\Admin\AdminPosV4Controller;
use App\Http\Controllers\Frontend\PaymentController;
use App\Http\Controllers\Frontend\RootController;
use App\Http\Controllers\Installer\InstallerController;
use App\Http\PaymentGateways\Gateways\Paytm;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::prefix('install')->name('installer.')->middleware(['web'])->group(function () {
    Route::get('/', [InstallerController::class, 'index'])->name('index');
    Route::get('/requirement', [InstallerController::class, 'requirement'])->name('requirement');
    Route::get('/permission', [InstallerController::class, 'permission'])->name('permission');
    Route::get('/license', [InstallerController::class, 'license'])->name('license');
    Route::post('/license', [InstallerController::class, 'licenseStore'])->name('licenseStore');
    Route::get('/site', [InstallerController::class, 'site'])->name('site');
    Route::post('/site', [InstallerController::class, 'siteStore'])->name('siteStore');
    Route::get('/database', [InstallerController::class, 'database'])->name('database');
    Route::post('/database', [InstallerController::class, 'databaseStore'])->name('databaseStore');
    Route::get('/final', [InstallerController::class, 'final'])->name('final');
    Route::get('/final-store', [InstallerController::class, 'finalStore'])->name('finalStore');
});


// [STOREFRONT-REMOVAL 2026-06-25] V1 LOCAL Le Cayenne = AUCUN site vitrine public
// (commande en ligne hors périmètre V1). La racine et les pages vitrine
// Accueil/Menu/Offres redirigent vers l'admin → l'owner arrive sur login →
// dashboard → caisse / écran cuisine / borne. Le SPA admin/caisse/KDS/borne
// reste servi par le catch-all plus bas. Rollback : remettre la ligne
// `Route::get('/', [RootController::class, 'index'])...` d'origine.
Route::redirect('/', '/login')->name('home');
Route::redirect('/menu', '/login')->name('legacy.menu');
Route::redirect('/offers', '/login')->name('legacy.offers');
Route::redirect('/offres', '/login')->name('legacy.offres');
Route::prefix('payment')->name('payment.')->middleware(['installed'])->group(function () {
    Route::get('/{order}/pay', [PaymentController::class, 'index'])->name('index');
    Route::post('/{order}/pay', [PaymentController::class, 'payment'])->name('store');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/success', [PaymentController::class, 'success'])->name('success');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/fail', [PaymentController::class, 'fail'])->name('fail');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/cancel', [PaymentController::class, 'cancel'])->name('cancel');
    Route::get('/successful/{order}', [PaymentController::class, 'successful'])->name('successful');
});

// [GOAL RUPTURE-CARNET 2026-07-15 / W4] Carnet — mini-app web mobile à code PIN
// (notes + dépenses + acomptes + photos factures). Registre interne HORS NF525.
// Déclaré AVANT le catch-all. Session Laravel standard (groupe web implicite).
Route::prefix('carnet')->name('daily-book.')->middleware(['installed'])->group(function () {
    Route::get('/', function () {
        return view('daily-book');
    })->name('index');

    Route::post('/api/pin', [\App\Http\Controllers\DailyBook\DailyBookAuthController::class, 'unlock'])
        ->middleware('throttle:daily-book-pin')->name('pin');
    Route::get('/api/status', [\App\Http\Controllers\DailyBook\DailyBookAuthController::class, 'status'])->name('status');

    Route::middleware([\App\Http\Middleware\EnsureDailyBookPin::class])->group(function () {
        Route::post('/api/lock', [\App\Http\Controllers\DailyBook\DailyBookAuthController::class, 'lock'])->name('lock');
        Route::get('/api/entries', [\App\Http\Controllers\DailyBook\DailyBookEntryController::class, 'index'])->name('entries.index');
        Route::post('/api/entries', [\App\Http\Controllers\DailyBook\DailyBookEntryController::class, 'store'])->name('entries.store');
        Route::delete('/api/entries/{entry}', [\App\Http\Controllers\DailyBook\DailyBookEntryController::class, 'destroy'])->name('entries.destroy');
        Route::get('/api/entries/{entry}/photo', [\App\Http\Controllers\DailyBook\DailyBookEntryController::class, 'photo'])->name('entries.photo');
        Route::get('/api/summary/month', [\App\Http\Controllers\DailyBook\DailyBookSummaryController::class, 'month'])->name('summary.month');
    });
});

// [GOAL MEGA W-MOBILE 2026-07-22] Stock mobile — mini-app web à code PIN pour
// l'owner (voir les ruptures « à acheter » + basculer dispo/rupture depuis le
// téléphone). Miroir du Carnet (HORS NF525, stock uniquement). Blade autonome
// (inline vanilla JS) — PAS la SPA admin, PAS de Sanctum. Le toggle délègue au
// SSOT AvailabilityService::toggle. Déclaré AVANT le catch-all. Fail-closed :
// MOBILE_STOCK_PIN vide => accès entièrement refusé.
Route::prefix('m')->name('mobile-stock.')->middleware(['installed'])->group(function () {
    Route::get('/', function () {
        return view('mobile-stock');
    })->name('index');

    Route::post('/api/pin', [\App\Http\Controllers\Mobile\MobileStockAuthController::class, 'unlock'])
        ->middleware('throttle:mobile-stock-pin')->name('pin');
    Route::get('/api/status', [\App\Http\Controllers\Mobile\MobileStockAuthController::class, 'status'])->name('status');

    Route::middleware([\App\Http\Middleware\EnsureMobileStockPin::class])->group(function () {
        Route::post('/api/lock', [\App\Http\Controllers\Mobile\MobileStockAuthController::class, 'lock'])->name('lock');
        Route::get('/api/catalog', [\App\Http\Controllers\Mobile\MobileStockController::class, 'catalog'])->name('catalog');
        Route::post('/api/toggle', [\App\Http\Controllers\Mobile\MobileStockController::class, 'toggle'])->name('toggle');
        // [HEAL F3 2026-07-24] Rupture d'un INGRÉDIENT (extra/variation), pas
        // seulement d'un produit entier. Délègue au MÊME SSOT que caisse/cuisine
        // (AvailabilityService::toggleExtra/toggleVariation) — 0 chemin parallèle.
        Route::post('/api/toggle-extra', [\App\Http\Controllers\Mobile\MobileStockController::class, 'toggleExtra'])->name('toggle-extra');
    });
});

// [POS-V4 W2 #1 2026-04-26] Dedicated POS V4 entry — MUST be declared BEFORE
// the catch-all below so Laravel matches it first. Serves admin-pos-v4.blade.php
// (loads pos-app.js, NOT app.js). See docs/design/ADR_POS_V4_DEDICATED_ENTRY.md.
// Pattern: {any?} captures sub-routes (e.g. /admin/pos-v4/floorplan) so the
// Vue Router on the client can handle deep links without server bouncing them
// to the legacy SPA. Rollback: delete this Route::get line + the use import.
Route::get('/admin/pos-v4/{any?}', [AdminPosV4Controller::class, 'index'])
    ->middleware(['installed'])
    ->where(['any' => '.*'])
    ->name('admin.pos.v4');

// Kiosk lockdown: these legacy/admin bundle names must not fall through to the SPA.
Route::get('/js/{forbiddenKioskAsset}', static fn () => abort(404))
    ->where('forbiddenKioskAsset', 'kiosk(?:-admin)?\.js(?:\.LICENSE\.txt)?');

// [C-5 e2e 2026-07-04] Téléchargement ROBUSTE des ponts d'impression (PC caisse/borne).
// AVANT le catch-all SPA (sinon /dl/*.txt était absorbé par la SPA → HTML au lieu du script
// → `node` crash `SyntaxError: Unexpected token '<'`). Sert le fichier JS VERSIONNÉ du repo en
// texte brut : aucune dépendance à `public/dl` ni à la config nginx. `Invoke-WebRequest .../dl/
// caisse-bridge.js -OutFile` récupère le vrai script.
Route::get('/dl/{bridge}', function (string $bridge) {
    $map = [
        'caisse-bridge.js' => base_path('tools/caisse-bridge/caisse-bridge.js'),
        'borne-bridge.js' => base_path('tools/borne/bridge.js'),
    ];
    abort_unless(isset($map[$bridge]) && is_file($map[$bridge]), 404);

    return response(file_get_contents($map[$bridge]), 200, [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Content-Disposition' => 'attachment; filename="' . $bridge . '"',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('bridge', '[a-z0-9\-]+\.js')->name('dl.bridge');

// [C-001 test-e2e 2026-07-17] Un ASSET manquant (chunk périmé, image disparue…)
// doit répondre 404 — pas la SPA en 200 text/html (pages blanches « tout vert »,
// classe de l'incident paiement borne 2026-07-07 ; les fichiers EXISTANTS sont
// servis par le serveur web avant Laravel, seuls les manquants arrivent ici).
Route::get('/{any}', [RootController::class, 'index'])
    ->middleware(['installed'])
    ->where(['any' => '^(?i)(?!.*\.(?:js|mjs|css|map|png|jpe?g|webp|gif|svg|ico|woff2?|ttf|otf|eot|mp4|webm|json|txt)$).*$']);
