<?php

use Illuminate\Support\Facades\Route;
use Rushing\Commerce\Http\FakeRailController;

// The fake rail's hosted leg (BUY-02): mounted by a host through Route::commerceFakeRailRoutes(), inside `web`.
Route::get('confirm/{payment}', [FakeRailController::class, 'show'])->name('commerce.fake.confirm');
Route::post('confirm/{payment}', [FakeRailController::class, 'confirm'])->name('commerce.fake.confirm.store');
Route::get('portal', [FakeRailController::class, 'portal'])->name('commerce.fake.portal');
