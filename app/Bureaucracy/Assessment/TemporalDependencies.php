<?php

namespace App\Bureaucracy\Assessment;

final class TemporalDependencies
{
    public function anchorKey(array $variant, array $facts): ?string
    {
        $deadline = $variant['deadline'];
        if (($deadline['fact_key'] ?? null) === 'residence_title_expires_at') {
            if (! $this->confirmed($facts, 'current_residence_title')) {
                return 'current_residence_title';
            }
            if (in_array($facts['values']['current_residence_title'], ['settlement_permit_9', 'settlement_permit_18c', 'settlement_permit_unknown'], true)) {
                return null;
            }
        }
        if ($deadline['type'] === 'permit_window' && ! $this->confirmed($facts, 'entry_mode')) {
            return 'entry_mode';
        }

        return match ($deadline['type']) {
            'fact_date' => $deadline['fact_key'],
            'days_since_arrival' => 'arrival_date',
            'days_since_move_in' => 'moved_in_at',
            'days_since_event' => isset($variant['trigger_event']) ? $variant['trigger_event'].'_at' : null,
            'permit_window' => match ($facts['values']['entry_mode'] ?? null) {
                'd_visa' => 'visa_expires_at', 'visa_free' => 'arrival_date', default => 'entry_mode'
            },
            default => null,
        };
    }

    private function confirmed(array $facts, string $key): bool
    {
        return isset($facts['values'][$key]) && ($facts['states'][$key] ?? 'value') === 'value';
    }
}
