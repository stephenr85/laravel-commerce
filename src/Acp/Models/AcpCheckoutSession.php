<?php

namespace Rushing\Commerce\Acp\Models;

use Illuminate\Database\Eloquent\Model;
use Rushing\Commerce\Acp\Data\CheckoutSession;

/**
 * The persisted in-flight ACP checkout session. The protocol DTO is stored as an
 * opaque `payload` so the substrate never constrains the session's shape; `status`
 * is denormalized for cheap lookups. Rebuilds the DTO via {@see CheckoutSession::from()}.
 *
 * @property string $id
 * @property string $status
 * @property array $payload
 */
class AcpCheckoutSession extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return config('commerce.table_names.acp_checkout_sessions', 'commerce_acp_checkout_sessions');
    }

    public function toSession(): CheckoutSession
    {
        return CheckoutSession::from($this->payload);
    }
}
