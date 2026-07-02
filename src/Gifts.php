<?php

declare(strict_types=1);

namespace Rushing\Commerce;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rushing\Commerce\Data\Beneficiary;
use Rushing\Commerce\Data\Gift;
use Rushing\Commerce\Data\Order;
use Rushing\Commerce\Enums\RedemptionStatus;
use Rushing\Commerce\Events\GiftDelivered;
use Rushing\Commerce\Events\GiftIssued;
use Rushing\Commerce\Events\GiftRedeemed;
use Rushing\Commerce\Exceptions\RedemptionException;
use Rushing\Commerce\Models\Redemption;

/**
 * The gifting spine: pay for a Purchase on someone else's behalf and issue a
 * bearer redemption code, then move that code through its lifecycle. Fulfillment
 * is via events — the host reacts; the engine never knows what a redemption grants.
 * Any Purchase gifts the same way, including a Credit pack (no special form).
 */
final class Gifts
{
    public function __construct(private MoneyIn $moneyIn) {}

    public function purchase(Order $order, Beneficiary $beneficiary, ?string $driver = null): Gift
    {
        if ($beneficiary->partyId !== null && $beneficiary->partyId === $order->customer->id) {
            throw RedemptionException::payerIsBeneficiary();
        }

        $purchase = $this->moneyIn->place($order, $driver, $beneficiary->partyId);

        $redemption = Redemption::create([
            'code' => $this->generateCode(),
            'status' => RedemptionStatus::Issued,
            'purchase_id' => $purchase->id,
            'payer_id' => $order->customer->id,
            'beneficiary_party_id' => $beneficiary->partyId,
            'deliver_to' => $beneficiary->deliverTo,
            'purchase_snapshot' => $purchase->toArray(),
            'expires_at' => $this->expiryFromConfig(),
        ]);

        GiftIssued::dispatch($redemption);

        return new Gift(
            purchase: $purchase,
            beneficiary: $beneficiary,
            redemptionCode: $redemption->code,
        );
    }

    public function deliver(Redemption $redemption): Redemption
    {
        if ($redemption->status === RedemptionStatus::Issued) {
            $redemption->forceFill([
                'status' => RedemptionStatus::Delivered,
                'delivered_at' => Carbon::now(),
            ])->save();

            GiftDelivered::dispatch($redemption);
        }

        return $redemption;
    }

    public function redeem(string $code, string $redeemedBy): Redemption
    {
        $redemption = Redemption::query()->where('code', $code)->first();

        if ($redemption === null) {
            throw RedemptionException::unknownCode($code);
        }

        if ($redemption->hasExpired()) {
            $redemption->forceFill(['status' => RedemptionStatus::Expired])->save();

            throw RedemptionException::expired($code);
        }

        if (! $redemption->isClaimable()) {
            throw RedemptionException::notClaimable($code);
        }

        $redemption->forceFill([
            'status' => RedemptionStatus::Redeemed,
            'redeemed_by' => $redeemedBy,
            'redeemed_at' => Carbon::now(),
        ])->save();

        GiftRedeemed::dispatch($redemption);

        return $redemption;
    }

    /**
     * Sweep issued/delivered codes whose window has closed. Redemption also
     * expires lazily on claim; this is the eager housekeeping path.
     */
    public function expireStale(): int
    {
        return Redemption::query()
            ->whereIn('status', [RedemptionStatus::Issued, RedemptionStatus::Delivered])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => RedemptionStatus::Expired]);
    }

    private function generateCode(): string
    {
        return Str::upper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
    }

    private function expiryFromConfig(): ?Carbon
    {
        $ttl = config('commerce.redemption.ttl_days');

        return $ttl === null ? null : Carbon::now()->addDays((int) $ttl);
    }
}
