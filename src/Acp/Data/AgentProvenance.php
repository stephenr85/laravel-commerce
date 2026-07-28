<?php

namespace Rushing\Commerce\Acp\Data;

use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Data;

/**
 * Who drove a checkout — the agent-provenance trace the sell-side records so a
 * merchant knows *what is flowing through the system*: which shopping agent, on
 * which session, for what stated reason. Brand-blind and buyer-blind: it names the
 * calling agent, never the host's catalog vocabulary. Every field is optional — an
 * agent may present as little as a `User-Agent`.
 */
class AgentProvenance extends Data implements SchemaIdentity
{
    public function __construct(
        public ?string $agentId = null,
        public ?string $sessionId = null,
        public ?string $reason = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}

    /**
     * The host-owned customer key this provenance stands in for, so an Order can
     * always name a payer even for a guest/bearer agent checkout.
     */
    public function customerKey(): string
    {
        return $this->agentId ?? $this->sessionId ?? 'anonymous-agent';
    }

    /**
     * Overlay another trace's non-null fields onto this one. Lets a completing
     * request enrich (never erase) the provenance captured when the session was
     * created — an agent that resends only a `reason` keeps its create-time id.
     */
    public function merge(?self $override): self
    {
        if ($override === null) {
            return $this;
        }

        return new self(
            agentId: $override->agentId ?? $this->agentId,
            sessionId: $override->sessionId ?? $this->sessionId,
            reason: $override->reason ?? $this->reason,
            userAgent: $override->userAgent ?? $this->userAgent,
            requestId: $override->requestId ?? $this->requestId,
        );
    }

    public static function schemaName(): string
    {
        return 'commerce/acp/agent-provenance';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
