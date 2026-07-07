<?php

namespace Rushing\Commerce\Data;

use InvalidArgumentException;
use Rushing\LaravelDataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * An immutable amount as minor units plus an ISO-4217 currency. Arithmetic that
 * crosses currencies throws — the engine never silently converts.
 */
class Money extends Data implements SchemaIdentity
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ) {}

    public static function of(int $minorUnits, string $currency = 'USD'): self
    {
        return new self($minorUnits, strtoupper($currency));
    }

    public static function zero(string $currency = 'USD'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * The single rounding point between sub-cent cost metering and a chargeable
     * amount (ADR-0002): a fractional-USD total rounds once, here, into minor
     * units. Assumes a two-decimal (cents) currency.
     */
    public static function fromUsd(float $usd, string $currency = 'USD'): self
    {
        return new self((int) round($usd * 100), strtoupper($currency));
    }

    public function plus(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->minorUnits * $quantity, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}."
            );
        }
    }

    public static function schemaName(): string
    {
        return 'commerce/money';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
