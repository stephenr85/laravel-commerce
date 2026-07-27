<?php

namespace Rushing\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One durable per-attempt audit row for an auto-reload charge — success and failure
 * alike — the single source behind the config's failure counters, a host's
 * status/history surface, terminal notifications, and ops alerts. The autoreload:
 * -prefixed {@see $reason} is what the money decision scans for its cooldown/period
 * guardrails; a blocked attempt (a shouldReload() guardrail stop) is recorded here
 * too so guardrail stops are auditable without polluting the failure count.
 *
 * @property string $party_id
 * @property string $unit
 * @property string $reason
 * @property string $outcome
 * @property string|null $stripe_error_code
 * @property float|null $amount_usd
 * @property string|null $provider_ref
 * @property int $consecutive_failures_after
 * @property bool $caused_disable
 */
class AutoReloadAttempt extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount_usd' => 'float',
        'consecutive_failures_after' => 'int',
        'caused_disable' => 'bool',
    ];

    public function getTable(): string
    {
        return config('commerce.table_names.auto_reload_attempts', 'commerce_auto_reload_attempts');
    }
}
