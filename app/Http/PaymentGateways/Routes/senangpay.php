<?php

use Illuminate\Support\Facades\Route;
use App\Http\PaymentGateways\Gateways\Senangpay;

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

Route::prefix('payment')->name('payment.')->middleware(['installed'])->group(function () {
    // [Wave 1 P1 SYNC-RED-02 2026-05-18] POST-only — GET leaks hash + transaction_id
    //   into Nginx access logs.
    // [Wave 1 P1 SYNC-RED-01 2026-05-18] throttle:60,1 → mitigates LAN HMAC-CPU DoS.
    Route::post('/senangpay-webhook/', [Senangpay::class, 'webhook'])
        ->middleware(['throttle:60,1'])
        ->name('senangpay.webhook');
});
