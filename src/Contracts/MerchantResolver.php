<?php

declare(strict_types=1);

namespace Rushing\Commerce\Contracts;

use Rushing\Commerce\Data\Merchant;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Data\Payment;

/**
 * Resolves the billing party — the Merchant whose provider account collects for a
 * given Order (and reverses a given Payment). The package ships a trivial
 * single-merchant resolver from config; a tenancy-aware host resolves the billing
 * party as `parent_tenant_id ?? self` so a brokered child charges on its broker's
 * account. The platform is never merchant-of-record: there is no "collect on behalf
 * of" — a broker collecting for its own sub-tenants is the broker being a Merchant.
 */
interface MerchantResolver
{
    public function forOrder(Order $order): Merchant;

    public function forPayment(Payment $payment): Merchant;
}
