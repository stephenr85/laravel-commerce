<?php

namespace Rushing\Commerce\Acp\Contracts;

use Rushing\Commerce\Acp\Data\CheckoutSession;

/**
 * Where an in-flight ACP checkout session lives between the create and complete
 * calls. The default binding persists the session DTO as an opaque payload so any
 * substrate can back it; a host may rebind to its own store.
 */
interface CheckoutSessionStore
{
    public function put(CheckoutSession $session): void;

    public function find(string $id): ?CheckoutSession;
}
