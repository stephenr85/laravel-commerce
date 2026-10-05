<?php

namespace Rushing\Commerce;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Rushing\Commerce\Contracts\InvoiceIssuer;
use Rushing\Commerce\Exceptions\FakeDriverInProduction;
use Rushing\Commerce\Stripe\BillableResolver;
use Rushing\Commerce\Stripe\CashierInvoiceIssuer;
use Rushing\Commerce\Support\FakeInvoiceIssuer;
use Rushing\Commerce\Support\NoneInvoiceIssuer;

/**
 * The invoice issuer for the rail this host collects on (purchase-walkthrough BUY-02 part D), chosen exactly as
 * {@see CustomerVaults} chooses the vault: a payable Stripe invoice on `stripe`, settled-at-once and offline on `fake`
 * (refused in production), refused on `none` or custody none, and an unknown rail refused.
 */
class InvoiceIssuers
{
    public static function forRail(Container $app): InvoiceIssuer
    {
        $rail = config('commerce.custody') === 'none' ? 'none' : (string) config('commerce.driver', 'fake');

        return match ($rail) {
            'stripe' => new CashierInvoiceIssuer($app->make(BillableResolver::class)),
            'none' => new NoneInvoiceIssuer,
            'fake' => $app->environment('production') ? throw FakeDriverInProduction::make() : new FakeInvoiceIssuer,
            default => throw new InvalidArgumentException("No invoice issuer for commerce rail [{$rail}]; expected stripe, fake or none."),
        };
    }
}
