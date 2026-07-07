<?php

namespace Rushing\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Commerce\Data\Purchase;
use Rushing\Commerce\Enums\RedemptionStatus;

/**
 * A bearer redemption code and its lifecycle — issued → delivered → redeemed
 * (or → expired). Decoupled from any account: `redeemed_by` binds to whoever
 * claims the code, set at claim time. The engine stores the Purchase snapshot but
 * never interprets what redemption grants — that is host-owned.
 *
 * @property string $code
 * @property RedemptionStatus $status
 * @property string $payer_id
 * @property string|null $beneficiary_party_id
 * @property string|null $deliver_to
 * @property string|null $redeemed_by
 */
class Redemption extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'status' => RedemptionStatus::class,
        'purchase_snapshot' => 'array',
        'delivered_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('commerce.table_names.redemptions', 'commerce_redemptions');
    }

    public function purchase(): Purchase
    {
        return Purchase::from($this->purchase_snapshot);
    }

    public function isClaimable(): bool
    {
        return $this->status === RedemptionStatus::Issued
            || $this->status === RedemptionStatus::Delivered;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
