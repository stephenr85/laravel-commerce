<?php

namespace Rushing\Commerce;

use Illuminate\Contracts\Container\Container;
use Rushing\Commerce\Contracts\CustomerVault;
use Rushing\Commerce\Stripe\BillableResolver;
use Rushing\Commerce\Stripe\CashierCustomerVault;
use Rushing\Commerce\Support\FakeCustomerVault;
use Rushing\Commerce\Support\NoneCustomerVault;

/**
 * The card vault for the rail this host collects on (purchase-walkthrough BUY-02 part B4): the adapter's Cashier vault
 * on `stripe` (over the host's {@see BillableResolver}), an offline vault on `fake`, a refusing vault on `none`, and
 * custody `none` forces the none vault as it forces the none rail (C-3). Opt-in: a host binds
 * `CustomerVault::class => fn ($app) => CustomerVaults::forRail($app)`; a host that binds its own vault is untouched.
 */
class CustomerVaults
{
    public static function forRail(Container $app): CustomerVault
    {
        $rail = config('commerce.custody') === 'none' ? 'none' : (string) config('commerce.driver', 'fake');

        return match ($rail) {
            'stripe' => new CashierCustomerVault($app->make(BillableResolver::class)),
            'none' => new NoneCustomerVault,
            default => new FakeCustomerVault,
        };
    }
}
