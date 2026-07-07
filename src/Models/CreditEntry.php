<?php

namespace Rushing\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Commerce\Contracts\UsageMeter;

/**
 * One append-only credit top-up in a party's Wallet, in a host-defined unit. This
 * is the *credit* side only — the package never records debits (usage is read from
 * the host's own ledger via a {@see UsageMeter}).
 *
 * @property string $party_id
 * @property string $unit
 * @property float $amount
 * @property string|null $purchase_id
 */
class CreditEntry extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
    ];

    public function getTable(): string
    {
        return config('commerce.table_names.credit_entries', 'commerce_credit_entries');
    }
}
