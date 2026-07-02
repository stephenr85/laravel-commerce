<?php

declare(strict_types=1);

namespace Rushing\Commerce\Drivers;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Enums\PaymentStatus;

/**
 * Records the same neutral DTOs a real driver would and touches no network — the
 * deterministic path for seeders and feature tests. It is the single new test seam
 * the rest of the engine reuses.
 */
final class FakeDriver implements MoneyInDriver
{
    public function name(): string
    {
        return 'fake';
    }

    public function pay(Order $order): Payment
    {
        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: PaymentStatus::Succeeded,
            driver: $this->name(),
            providerRef: 'fake_'.Str::lower(Str::random(16)),
        );
    }

    public function refund(Payment $payment): Refund
    {
        return new Refund(
            id: (string) Str::uuid(),
            paymentId: $payment->id,
            amount: $payment->amount,
            driver: $this->name(),
            providerRef: 'fake_re_'.Str::lower(Str::random(16)),
        );
    }
}
