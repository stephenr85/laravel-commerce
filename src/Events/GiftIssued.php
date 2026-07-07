<?php

namespace Rushing\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Rushing\Commerce\Models\Redemption;

/**
 * Fires when a Gift is paid for and a redemption code is issued. The host reacts
 * to deliver the code (email, link) — the engine does not deliver.
 */
class GiftIssued
{
    use Dispatchable;

    public function __construct(public Redemption $redemption) {}
}
