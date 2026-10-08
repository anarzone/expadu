<?php

namespace App\Bureaucracy\Timeline;

final readonly class TemporalEvent
{
    public function __construct(private array $attributes) {}

    public function toArray(): array
    {
        return $this->attributes;
    }
}
