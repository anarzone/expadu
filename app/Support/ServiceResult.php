<?php

namespace App\Support;

/**
 * Simple result wrapper for service calls.
 * Services return ServiceResult instead of raw arrays, so controllers
 * can detect errors and propagate them to the frontend.
 */
class ServiceResult
{
    private function __construct(
        public readonly array $data,
        public readonly ?string $error,
    ) {}

    public static function ok(array $data): self
    {
        return new self($data, null);
    }

    public static function fail(string $error): self
    {
        return new self([], $error);
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
