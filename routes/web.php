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


Route::get('/', [RootController::class, 'index'])->middleware(['installed'])->name('home');
Route::prefix('payment')->name('payment.')->middleware(['installed'])->group(function () {
    Route::get('/{order}/pay', [PaymentController::class, 'index'])->name('index');
    Route::post('/{order}/pay', [PaymentController::class, 'payment'])->name('store');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/success', [PaymentController::class, 'success'])->name('success');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/fail', [PaymentController::class, 'fail'])->name('fail');
    Route::match(['get', 'post'], '/{paymentGateway:slug}/{order}/cancel', [PaymentController::class, 'cancel'])->name('cancel');
    Route::get('/successful/{order}', [PaymentController::class, 'successful'])->name('successful');
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

Route::get('/{any}', [RootController::class, 'index'])->middleware(['installed'])->where(['any' => '.*']);
