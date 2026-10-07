<?php

namespace App\Bureaucracy\ReadModel;

use App\Privacy\ProcessingConsentStore;
use Carbon\CarbonImmutable;

/** Product attention windows, not extra legal rules or deadline calculations. */
final class PlanAttention
{
    public function for(?array $plan): array
    {
        if ($plan === null) {
            return [];
        }
        $at = CarbonImmutable::parse($plan['evaluated_at']);
        $guidance = array_column($plan['guidance'], null, 'id');
        $processes = array_column([...$plan['processes'], ...$plan['history']], null, 'occurrence_key');
        $result = [];
        foreach ($plan['timeline'] as $event) {
            $kind = $event['kind'];
            if (! in_array($kind, ['legal_due', 'preparation_target', 'authority_follow_up', 'appointment', 'document_expiry'], true)) {
                continue;
            }
            $process = $processes[$event['occurrence_key'] ?? ''] ?? null;
            $rule = $guidance[$event['source_rule_id'] ?? ''] ?? null;
            $appointment = $kind === 'appointment';
            if (! $appointment && $kind !== 'document_expiry') {
                if ($rule === null || $process === null || in_array($process['state']['workflow'], ['completed', 'cancelled'], true)
                    || ($process['state']['steps'][$rule['step_id']] ?? null) === 'completed') {
                    continue;
                }
            }
            $date = $appointment ? ($event['starts_at'] ?? null) : ($event['date'] ?? null);
            if ($date === null || ! in_array($event['state'], ['dated', 'recorded'], true)) {
                continue;
            }
            $when = CarbonImmutable::parse($date, $event['timezone']);
            if ($appointment && $when->lessThanOrEqualTo($at)) {
                continue;
            }
            $days = (int) $at->setTimezone($event['timezone'])->startOfDay()->diffInDays($when->setTimezone($event['timezone'])->startOfDay(), false);
            if ($days > ($appointment ? 7 : 14)) {
                continue;
            }
            $urgency = $appointment ? match (true) {
                $days === 0 => 'appointment_today', $days === 1 => 'appointment_tomorrow', default => 'appointment_soon',
            } : match (true) {
                $days < 0 => 'overdue', $days <= 3 => 'critical', $days <= 7 => 'urgent', default => 'upcoming',
            };
            $label = match ($kind) {
                'legal_due' => 'Deadline: ', 'preparation_target' => 'Preparation target: ',
                'authority_follow_up' => 'Follow-up: ', 'document_expiry' => 'Document expiry: ', default => 'Appointment: ',
            }.$when->setTimezone($event['timezone'])->format($appointment ? 'j M Y, H:i' : 'j M Y');
            $row = ['id' => $event['id'], 'person_id' => $plan['person_id'], 'jurisdiction' => $plan['jurisdiction'],
                'process_id' => $event['process_id'] ?? null, 'occurrence_key' => $event['occurrence_key'] ?? null,
                'kind' => $kind, 'date' => $date, 'timezone' => $event['timezone'], 'days_remaining' => $days, 'urgency' => $urgency,
                'title' => $rule['title'] ?? ($appointment ? 'Your recorded appointment' : 'Document expiry'), 'label' => $label,
                'source_rule_id' => $rule['id'] ?? null, 'source_hash' => $rule['source_hash'] ?? null,
                'action' => ['type' => $appointment ? 'view_appointment' : ($kind === 'document_expiry' ? 'review_details' : 'open_process'), 'person_id' => $plan['person_id'],
                    'process_id' => $event['process_id'] ?? null, 'occurrence_key' => $event['occurrence_key'] ?? null, 'event_id' => $event['id']]];
            $result[] = [...$row, 'event_revision' => ProcessingConsentStore::digest($row), 'assessment_revision' => $plan['assessment_revision']];
        }
        usort($result, fn ($a, $b) => $a['days_remaining'] <=> $b['days_remaining'] ?: strcmp($a['id'], $b['id']));

        return $result;
    }

    public function count(?array $plan): int
    {
        return count(array_unique(array_map(fn ($row) => $row['occurrence_key'] ?? $row['id'], $this->for($plan))));
    }
}
