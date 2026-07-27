<?php

namespace Rushing\Commerce\Drivers;

use Illuminate\Support\Str;
use Rushing\Commerce\Contracts\MoneyInDriver;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Refund;
use Rushing\Commerce\Enums\PaymentStatus;
use Throwable;

/**
 * Records the same neutral DTOs a real driver would and touches no network — the
 * deterministic path for seeders and feature tests. It is the single new test seam
 * the rest of the engine reuses.
 *
 * By default every pay() succeeds. To exercise the off-session reload lifecycle a
 * test *scripts* outcomes: each fake* call pushes one outcome onto a FIFO queue that
 * pay() consumes in order, so the whole failure taxonomy (decline / step-up /
 * transient-infra) is reproducible with `fake` — no Stripe involved. An empty queue
 * falls back to success, so unscripted callers are unchanged.
 */
class FakeDriver implements MoneyInDriver
{
    /** @var list<Throwable|array{status: PaymentStatus, errorCode: string|null}> */
    private static array $script = [];

    public function name(): string
    {
        return 'fake';
    }

    public function pay(Order $order): Payment
    {
        $outcome = array_shift(self::$script);

        // A transient-infra outcome throws — the orchestrating queue retries the same
        // idempotency key, exactly as a real network/rate_limit error would.
        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        $status = $outcome['status'] ?? PaymentStatus::Succeeded;
        $errorCode = $outcome['errorCode'] ?? null;

        return new Payment(
            id: (string) Str::uuid(),
            orderId: $order->id,
            amount: $order->total,
            status: $status,
            driver: $this->name(),
            providerRef: 'fake_'.Str::lower(Str::random(16)),
            errorCode: $errorCode,
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

    /** Script the next pay() to succeed. */
    public static function fakeSuccess(int $times = 1): void
    {
        self::push(['status' => PaymentStatus::Succeeded, 'errorCode' => null], $times);
    }

    /** Script the next pay() as a hard card decline (instrument-decline class). */
    public static function fakeDecline(string $code = 'card_declined', int $times = 1): void
    {
        self::push(['status' => PaymentStatus::Failed, 'errorCode' => $code], $times);
    }

    /** Script the next pay() as an off-session step-up (SCA-required class). */
    public static function fakeRequiresAction(string $code = 'authentication_required', int $times = 1): void
    {
        self::push(['status' => PaymentStatus::RequiresAction, 'errorCode' => $code], $times);
    }

    /** Script the next pay() to throw — a transient-infra outcome the queue retries. */
    public static function fakeTransientError(?Throwable $error = null, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            self::$script[] = $error ?? new \RuntimeException('fake transient charge error');
        }
    }

    /** Reset the scripted outcomes — call between tests that script the driver. */
    public static function clearFakes(): void
    {
        self::$script = [];
    }

    /**
     * @param  array{status: PaymentStatus, errorCode: string|null}  $outcome
     */
    private static function push(array $outcome, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            self::$script[] = $outcome;
        }
    }
}
