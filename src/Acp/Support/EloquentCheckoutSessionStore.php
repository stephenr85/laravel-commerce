<?php

namespace Rushing\Commerce\Acp\Support;

use Rushing\Commerce\Acp\Contracts\CheckoutSessionStore;
use Rushing\Commerce\Acp\Data\CheckoutSession;
use Rushing\Commerce\Acp\Models\AcpCheckoutSession;

/**
 * The default {@see CheckoutSessionStore}: persists the session DTO as an opaque
 * payload in `commerce_acp_checkout_sessions`. A host swaps this for its own store
 * by rebinding the contract.
 */
class EloquentCheckoutSessionStore implements CheckoutSessionStore
{
    public function put(CheckoutSession $session): void
    {
        AcpCheckoutSession::query()->updateOrCreate(
            ['id' => $session->id],
            [
                'status' => $session->status->value,
                'payload' => $session->toArray(),
            ],
        );
    }

    public function find(string $id): ?CheckoutSession
    {
        $record = AcpCheckoutSession::query()->find($id);

        return $record?->toSession();
    }
}
