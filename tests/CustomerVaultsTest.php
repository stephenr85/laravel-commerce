<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Rushing\Commerce\CustomerVaults;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Events\PaymentMethodsChanged;
use Rushing\Commerce\Exceptions\MoneyInUnavailable;
use Rushing\Commerce\Stripe\BillableResolver;
use Rushing\Commerce\Stripe\CashierCustomerVault;
use Rushing\Commerce\Support\FakeCustomerVault;

/*
 * The card vault follows the rail (purchase-walkthrough BUY-02 part B4, rule BUY-3): on `fake` it is offline (card
 * saving settles with nothing sent), on `stripe` it is the adapter's Cashier vault over the host's billable, and on
 * `none` (or custody none, C-3) it refuses to save or charge a card.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(BillableResolver::class, new class implements BillableResolver
    {
        public function forCustomer(Customer $customer): Model
        {
            throw new RuntimeException('not reached in these tests');
        }
    });
});

it('is the adapter Cashier vault on the stripe rail', function () {
    config(['commerce.driver' => 'stripe', 'commerce.custody' => 'hosted']);

    expect(CustomerVaults::forRail(app()))->toBeInstanceOf(CashierCustomerVault::class);
});

it('is an offline vault on the fake rail that still announces card changes', function () {
    config(['commerce.driver' => 'fake', 'commerce.custody' => 'hosted']);
    Event::fake([PaymentMethodsChanged::class]);
    $vault = CustomerVaults::forRail(app());
    $customer = new Customer(id: 'tenant-acme');
    $merchant = new Merchant(id: 'default');

    expect($vault)->toBeInstanceOf(FakeCustomerVault::class)
        ->and($vault->resolveCustomer($customer, $merchant)->providerRef)->toStartWith('fake_cus_')
        ->and($vault->beginSetup($customer, $merchant)->clientSecret)->toStartWith('fake_seti_')
        ->and($vault->paymentMethods($customer, $merchant))->toBe([]);

    $vault->setDefaultPaymentMethod($customer, $merchant, 'pm_x');
    $vault->forgetPaymentMethod($customer, $merchant, 'pm_x');
    Event::assertDispatchedTimes(PaymentMethodsChanged::class, 2);
});

it('refuses to save or charge a card on the none rail, including custody none over a stripe driver', function () {
    foreach ([['none', 'hosted'], ['stripe', 'none']] as [$driver, $custody]) {
        config(['commerce.driver' => $driver, 'commerce.custody' => $custody]);
        $vault = CustomerVaults::forRail(app());
        $customer = new Customer(id: 'tenant-acme');
        $merchant = new Merchant(id: 'default');

        expect(fn () => $vault->beginSetup($customer, $merchant))->toThrow(MoneyInUnavailable::class)
            ->and(fn () => $vault->resolveCustomer($customer, $merchant))->toThrow(MoneyInUnavailable::class)
            ->and($vault->paymentMethods($customer, $merchant))->toBe([]);
    }
});

it('refuses the fake vault in production, as the fake driver does (review-r1)', function () {
    config(['commerce.driver' => 'fake', 'commerce.custody' => 'hosted']);
    $previous = app()['env'];
    app()['env'] = 'production';
    try {
        expect(fn () => CustomerVaults::forRail(app()))->toThrow(Rushing\Commerce\Exceptions\FakeDriverInProduction::class);
    } finally {
        app()['env'] = $previous;
    }
});
