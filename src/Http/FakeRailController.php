<?php

namespace Rushing\Commerce\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Rushing\Commerce\Data\Payment;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Drivers\FakeDriver;
use Rushing\Commerce\Enums\PaymentStatus;
use Rushing\Commerce\Events\PurchaseCompleted;

/**
 * The fake rail's hosted leg (purchase-walkthrough BUY-02, ruling C-11; rule BUY-3): a local stand-in for a provider's
 * checkout and billing portal, so a browser walks the same redirect a real rail sends it on, with zero outbound calls.
 * Confirming settles into exactly the {@see PurchaseCompleted} a webhook translator dispatches, once.
 */
class FakeRailController
{
    public function show(string $payment): Response
    {
        abort_if(app()->environment('production'), 404);
        $pending = Cache::get(FakeDriver::PENDING.$payment);
        abort_if($pending === null, 404);

        $amount = $pending['purchase']->payment->amount;
        $label = number_format($amount->minorUnits / 100, 2).' '.$amount->currency;

        return response(<<<HTML
            <!doctype html><html><head><meta charset="utf-8"><title>Fake checkout</title></head>
            <body><h1>Fake checkout</h1><p>No money moves: this is the <code>fake</code> commerce rail.</p>
            <p>Amount: {$label}</p>
            <form method="post"><input type="hidden" name="_token" value="{$this->csrf()}"><button type="submit">Confirm payment</button></form>
            </body></html>
            HTML);
    }

    public function confirm(string $payment): RedirectResponse
    {
        abort_if(app()->environment('production'), 404);
        // One-shot: pull, so a replayed confirm settles nothing twice.
        $pending = Cache::pull(FakeDriver::PENDING.$payment);
        abort_if($pending === null, 404);

        /** @var Purchase $purchase */
        $purchase = $pending['purchase'];
        $declineAt = config('commerce.fake.decline_minor_units');
        $declined = $declineAt !== null && $purchase->payment->amount->minorUnits === (int) $declineAt;

        if (! $declined) {
            PurchaseCompleted::dispatch(new Purchase(
                id: $purchase->id,
                orderId: $purchase->orderId,
                kind: $purchase->kind,
                payment: new Payment(
                    id: $purchase->payment->id,
                    orderId: $purchase->payment->orderId,
                    amount: $purchase->payment->amount,
                    status: PaymentStatus::Succeeded,
                    driver: $purchase->payment->driver,
                    providerRef: $purchase->payment->providerRef,
                    merchantId: $purchase->payment->merchantId,
                    split: $purchase->payment->split,
                ),
                receipt: $purchase->receipt,
                beneficiaryId: $purchase->beneficiaryId,
                grant: $purchase->grant,
            ));
        }

        $return = str_replace('{CHECKOUT_SESSION_ID}', (string) $purchase->payment->providerRef, (string) ($pending['returnUrl'] ?? '/'));

        return redirect()->to($return)->with('commerce.fake', $declined ? 'declined' : 'paid');
    }

    public function portal(): Response
    {
        abort_if(app()->environment('production'), 404);
        $return = e((string) request()->query('return', '/'));

        return response("<!doctype html><html><head><meta charset=\"utf-8\"><title>Fake billing portal</title></head><body><h1>Fake billing portal</h1><p>No money moves: this is the <code>fake</code> commerce rail.</p><a href=\"{$return}\">Return</a></body></html>");
    }

    private function csrf(): string
    {
        return app()->bound('session') ? (string) csrf_token() : '';
    }
}
