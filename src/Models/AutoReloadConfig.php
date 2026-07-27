<?php

namespace Rushing\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Rushing\Commerce\AutoReload;
use Rushing\Commerce\Contracts\PaymentMethodResolver;

/**
 * A party's auto-reload configuration for one Wallet unit — the party-neutral
 * primitive behind {@see AutoReload}. Records tenant intent
 * (threshold, amount, guardrails) and the failure-lifecycle summary, but never the
 * payment-method reference itself: the config stores only which *source* a card
 * comes from ({@see $payment_method_source}); the pm_/cus_ is resolved on demand by
 * a host-bound {@see PaymentMethodResolver} (ADR-0131).
 *
 * @property string $party_id
 * @property string $unit
 * @property bool $enabled
 * @property float $threshold_usd
 * @property string $amount_mode
 * @property float|null $reload_amount_usd
 * @property float|null $target_usd
 * @property int|null $cooldown_seconds
 * @property int|null $max_reloads_per_period
 * @property float|null $max_spend_per_period_usd
 * @property float|null $max_per_reload_usd
 * @property int $period_days
 * @property int $consecutive_failures
 * @property string|null $disabled_reason
 * @property string|null $payment_method_source
 */
class AutoReloadConfig extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'bool',
        'threshold_usd' => 'float',
        'reload_amount_usd' => 'float',
        'target_usd' => 'float',
        'cooldown_seconds' => 'int',
        'max_reloads_per_period' => 'int',
        'max_spend_per_period_usd' => 'float',
        'max_per_reload_usd' => 'float',
        'period_days' => 'int',
        'consecutive_failures' => 'int',
    ];

    public function getTable(): string
    {
        return config('commerce.table_names.auto_reload_configs', 'commerce_auto_reload_configs');
    }
}
