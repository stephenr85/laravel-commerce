<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Rushing\Commerce\Data\Customer;
use Rushing\Commerce\Data\LineItem;
use Rushing\Commerce\Data\Money;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Enums\Cadence;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('.');

/**
 * A one-line Order builder so tests read as behavior, not DTO plumbing.
 */
function testOrder(
    int $minorUnits = 900,
    Cadence $cadence = Cadence::OneTime,
    ?PurchaseKind $kind = null,
    string $currency = 'USD',
): Order {
    return Order::for(
        customer: new Customer(id: 'cus_1', name: 'Ada', email: 'ada@example.test'),
        lineItems: [LineItem::for('Own-a-song', Money::of($minorUnits, $currency))],
        cadence: $cadence,
        kind: $kind,
        currency: $currency,
    );
}
