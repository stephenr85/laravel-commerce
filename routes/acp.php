<?php

use Illuminate\Support\Facades\Route;
use Rushing\Commerce\Acp\Http\CheckoutSessionController;

/*
|--------------------------------------------------------------------------
| ACP Agentic Checkout routes
|--------------------------------------------------------------------------
|
| The neutral sell-side protocol surface an external shopping agent drives.
| Route *names* are brand-blind (`commerce.checkout.*`); the host supplies the
| prefix + middleware group when it calls `Route::commerceAcpRoutes()`. Resource
| paths mirror the Agentic Commerce Protocol (`checkout_sessions`).
|
*/

Route::post('checkout_sessions', [CheckoutSessionController::class, 'create'])
    ->name('commerce.checkout.create');

Route::get('checkout_sessions/{id}', [CheckoutSessionController::class, 'show'])
    ->name('commerce.checkout.show');

Route::post('checkout_sessions/{id}/complete', [CheckoutSessionController::class, 'complete'])
    ->name('commerce.checkout.complete');
