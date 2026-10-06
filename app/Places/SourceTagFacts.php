<?php

namespace App\Places;

final readonly class SourceTagFacts
{
    /** @param array<string, mixed> $tags */
    public function __construct(private array $tags) {}

    public function feeStatus(): string
    {
        foreach (['fee', 'charge', 'fee:conditional', 'charge:conditional'] as $key) {
            if (isset($this->tags[$key]) && ! is_string($this->tags[$key])) {
                return 'unknown';
            }
        }

        if ($this->tag('fee:conditional') !== null || $this->tag('charge:conditional') !== null) {
            return 'conditional';
        }

        $fee = $this->tag('fee');
        $charge = $this->tag('charge');
        $zeroCharges = ['0', '0 eur', '0 €'];

        if ($fee === 'no') {
            return $charge === null || in_array($charge, $zeroCharges, true) ? 'free' : 'unknown';
        }

        if ($fee === 'yes') {
            return in_array($charge, $zeroCharges, true) ? 'unknown' : 'paid';
        }

        return 'unknown';
    }

    public function accessStatus(): string
    {
        foreach (['access', 'access:conditional'] as $key) {
            if (isset($this->tags[$key]) && ! is_string($this->tags[$key])) {
                return 'unknown';
            }
        }

        if ($this->tag('access:conditional') !== null) {
            return 'conditional';
        }

        return match ($this->tag('access')) {
            'yes', 'public' => 'public',
            'permissive' => 'permissive',
            'no', 'private', 'members', 'customers', 'permit', 'destination', 'agricultural', 'forestry', 'delivery' => 'restricted',
            default => 'unknown',
        };
    }

    public function costTier(?string $priceRange): string
    {
        $fee = $this->feeStatus();
        if ($fee === 'conditional' || ($this->tag('fee') !== null && $fee === 'unknown')) {
            return 'unknown';
        }

        if ($fee === 'free') {
            return in_array($priceRange, ['€', '€€', '€€€'], true) ? 'unknown' : 'free';
        }

        return match ($priceRange) {
            '€' => 'low',
            '€€', '€€€' => 'normal',
            default => $fee === 'paid' ? 'normal' : 'unknown',
        };
    }

    public function requiresArrangement(): bool
    {
        return ! in_array($this->tag('reservation'), [null, 'no', 'yes', 'recommended', 'members_only'], true)
            || ($this->tag('membership') !== null && $this->tag('membership') !== 'no');
    }

    private function tag(string $key): ?string
    {
        $value = $this->tags[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : null;
    }
}
