<?php

namespace App\Bureaucracy\Ai;

use InvalidArgumentException;

final readonly class CaseFactExtractionResult
{
    private function __construct(
        public string $outcome,
        public mixed $value,
        public bool $hasValue,
    ) {}

    public static function candidate(mixed $value): self
    {
        if ($value === null) {
            throw new InvalidArgumentException('A candidate result must carry a value.');
        }

        return new self('candidate', $value, true);
    }

    public static function unknown(): self
    {
        return new self('unknown', null, false);
    }

    public static function offTopic(): self
    {
        return new self('off_topic', null, false);
    }

    public static function unavailable(): self
    {
        return new self('unavailable', null, false);
    }

    public static function invalid(): self
    {
        return new self('invalid', null, false);
    }
}
