<?php

namespace Rushing\Commerce\Acp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The **minimal order** a completed agentic checkout records — enough to say the
 * sale happened, what it referenced (by opaque ref), which Payment settled it, and
 * the agent-provenance trace that drove it. Deliberately thin: the comprehensive
 * order ledger is the deferred commerce substrate (PRD _Lunar boundary_).
 *
 * @property string $id
 * @property string $checkout_session_id
 * @property string $currency
 * @property int $amount_minor
 * @property array $item_refs
 * @property string $payment_id
 * @property string|null $provider_ref
 * @property string $driver
 * @property string|null $receipt_id
 * @property array|null $provenance
 */
class AcpOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_refs' => 'array',
        'provenance' => 'array',
        'amount_minor' => 'integer',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return config('commerce.table_names.acp_orders', 'commerce_acp_orders');
    }
}
