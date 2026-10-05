<?php

use Illuminate\Support\Facades\Route;

/*
 * purchase-walkthrough BUY-08 (lead 13:23): the ACP sell-side checkout charges an agent's delegate token and has not had
 * its money review, so `Route::commerceAcpRoutes()` mounts NOTHING unless `commerce.acp.sell_side` opts in (default off),
 * and nothing at all for a host whose custody is `none` (posture), whatever the flag says.
 */

function mountAcp(): bool
{
    Route::prefix('t'.uniqid())->group(fn () => Route::commerceAcpRoutes());
    app('router')->getRoutes()->refreshNameLookups();

    return Route::has('commerce.checkout.complete');
}

it('mounts nothing by default', function () {
    // The shipped default, read from the package's own config file (no env override in this suite).
    $shipped = (require __DIR__.'/../config/commerce.php')['acp']['sell_side'];
    config(['commerce.custody' => 'hosted', 'commerce.acp.sell_side' => $shipped]);

    expect($shipped)->toBeFalse()->and(mountAcp())->toBeFalse();
});

it('mounts the protocol when the host opts in', function () {
    config(['commerce.custody' => 'hosted', 'commerce.acp.sell_side' => true]);

    expect(mountAcp())->toBeTrue();
});

it('mounts nothing for a host whose custody is none, even opted in', function () {
    config(['commerce.custody' => 'none', 'commerce.acp.sell_side' => true]);

    expect(mountAcp())->toBeFalse();
});
