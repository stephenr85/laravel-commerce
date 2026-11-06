<?php

namespace Rushing\Commerce;

use Rushing\Commerce\Contracts\SettlesFundingOnSetup;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Enums\PurchaseKind;
use Rushing\Commerce\Exceptions\FakeDriverInProduction;

/**
 * Reads the ACTIVE money-in rail's funding policy (owner-review-20261102 FUNDING): does a plan's confirmed card setup
 * fund the plan by itself? The rail declares it through {@see SettlesFundingOnSetup}; a rail that declares nothing keeps
 * BUY-04's rule and answers false (funding lands with the first charged Bill).
 *
 * The shared preconditions are checked here, so no rail has to repeat them: a succeeded Subscription purchase that
 * charged nothing, made on the rail that is active now (custody `none` makes that `none`, which never funds). The fake
 * rail refuses to exist in production ({@see FakeDriverInProduction}); that refusal reads as "does not fund", never as
 * an error on a provider's webhook.
 */
final class FundingPolicy
{
    public static function fundsOnSetup(Purchase $setup): bool
    {
        if ($setup->kind !== PurchaseKind::Subscription
            || ! $setup->payment->succeeded()
            || $setup->payment->amount->minorUnits !== 0) {
            return false;
        }

        try {
            $rail = app(MoneyInManager::class)->driver();
        } catch (FakeDriverInProduction) {
            return false;
        }

        return $rail->name() === $setup->payment->driver
            && $rail instanceof SettlesFundingOnSetup
            && $rail->fundsOnSetup($setup);
    }
}
