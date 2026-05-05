<?php

namespace App\ContextEngine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ScoredAction implements \JsonSerializable
{
    public const CHANNEL_DASHBOARD = 'dashboard';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_ALERT_PAGE = 'alert_page';

    /**
     * @param  list<self::CHANNEL_*>  $deliverChannels
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public string $actionKey,
        public float $score,
        public string $severity,
        public ?CarbonInterface $validUntil,
        public array $deliverChannels,
        public array $payload,
        public CarbonInterface $createdAt,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) $data['type'],
            actionKey: (string) $data['action_key'],
            score: (float) $data['score'],
            severity: (string) $data['severity'],
            validUntil: isset($data['valid_until']) ? CarbonImmutable::parse((string) $data['valid_until']) : null,
            deliverChannels: array_values(array_map('strval', (array) ($data['deliver_channels'] ?? []))),
            payload: (array) ($data['payload'] ?? []),
            createdAt: CarbonImmutable::parse((string) ($data['created_at'] ?? now())),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'action_key' => $this->actionKey,
            'score' => $this->score,
            'severity' => $this->severity,
            'valid_until' => $this->validUntil?->toIso8601String(),
            'deliver_channels' => $this->deliverChannels,
            'payload' => $this->payload,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }

    public function isExpired(): bool
    {
        return $this->validUntil !== null && $this->validUntil->isPast();
    }

    public function withoutChannel(string $channel): self
    {
        return new self(
            $this->type,
            $this->actionKey,
            $this->score,
            $this->severity,
            $this->validUntil,
            array_values(array_filter($this->deliverChannels, fn (string $c) => $c !== $channel)),
            $this->payload,
            $this->createdAt,
        );
    }
}
