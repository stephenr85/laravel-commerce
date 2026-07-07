<?php

namespace Rushing\Commerce\Billing;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * A calendar month (YYYY-MM) as a billing window value object. Domain-agnostic —
 * the composition loop and any metering read against its start/end dates.
 */
class BillingPeriod
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException("Invalid billing period: '{$value}'. Expected YYYY-MM.");
        }

        return new self($value);
    }

    public static function current(): self
    {
        return new self(now()->format('Y-m'));
    }

    public static function previous(): self
    {
        return new self(now()->subMonth()->format('Y-m'));
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->value.'-01')->startOfDay();
    }

    public function endDate(): Carbon
    {
        return Carbon::parse($this->value.'-01')->endOfMonth()->endOfDay();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
