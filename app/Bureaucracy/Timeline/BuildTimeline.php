<?php

namespace App\Bureaucracy\Timeline;

use App\Bureaucracy\Assessment\TemporalDependencies;
use Carbon\CarbonImmutable;
use DateTimeZone;
use DomainException;

final class BuildTimeline
{
    /** Current authorised variants plus active (not superseded) user-reported events. No implicit clock. */
    public function for(array $variants, array $facts, array $events, CarbonImmutable $at, string $timezone): array
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new DomainException('An explicit supported time zone is required.');
        }
        $today = $at->setTimezone($timezone)->toDateString();
        $rows = [];
        foreach ($variants as $variant) {
            if (! ($variant['actionable'] ?? false) || ($variant['deadline']['type'] ?? 'none') === 'none') {
                continue;
            }
            $deadline = $variant['deadline'];
            $kind = $variant['temporal_policy']['kind'] ?? 'preparation_target';
            if (! in_array($kind, ['legal_due', 'preparation_target', 'authority_follow_up'], true)) {
                throw new DomainException('Unsupported reviewed temporal policy kind.');
            }
            $anchor = (new TemporalDependencies)->anchorKey($variant, $facts);
            if ($anchor === null && $deadline['type'] === 'fact_date' && ($deadline['fact_key'] ?? null) === 'residence_title_expires_at') {
                continue; // A confirmed unlimited legal title has no legal-title expiry.
            }
            $row = ['id' => $variant['id'].'.due', 'kind' => $kind, 'source_rule_id' => $variant['id'],
                'policy_version' => $variant['temporal_policy']['version'] ?? $variant['review']['content_version'] ?? $variant['source_hash'],
                'date' => null, 'precision' => 'calendar_date', 'timezone' => $timezone,
                'state' => 'date_unknown', 'needed_fact' => $anchor, 'overdue' => false,
                'conditional' => ($variant['coverage'] ?? 'partial') !== 'complete'];
            if (($variant['review']['review_due_at'] ?? '') < $today) {
                $row['state'] = 'review_required';
                $row['needed_fact'] = null;
            } elseif ($anchor === null) {
                $row['state'] = 'policy_review_required';
            } elseif ($this->known($facts, $anchor)) {
                $isExact = $deadline['type'] === 'fact_date'
                    || ($deadline['type'] === 'permit_window' && $anchor === 'visa_expires_at');
                $date = $this->date($facts['values'][$anchor], $timezone);
                if ($date !== null && ($isExact || $date->toDateString() <= $today)) {
                    if ($isExact) {
                        $row['date'] = $date->toDateString();
                    } elseif (is_int($deadline['days'] ?? null)) {
                        $row['date'] = $date->addDays($deadline['days'])->toDateString();
                    } else {
                        $row['state'] = 'policy_review_required';
                        $row['needed_fact'] = null;
                    }
                }
                if ($row['date'] !== null) {
                    $row['state'] = 'dated';
                    $row['needed_fact'] = null;
                    $row['overdue'] = $row['date'] < $today;
                }
            }
            $rows[] = (new TemporalEvent($row))->toArray();
        }
        if ($this->known($facts, 'residence_card_expires_at')) {
            $date = $this->date($facts['values']['residence_card_expires_at'], $timezone);
            if ($date !== null) {
                $rows[] = ['id' => 'residence-card.expiry', 'kind' => 'document_expiry', 'date' => $date->toDateString(),
                    'precision' => 'calendar_date', 'timezone' => $timezone, 'state' => 'dated', 'overdue' => $date->toDateString() < $today,
                    'provenance' => 'confirmed_fact', 'legal_effect' => 'not_assessed'];
            }
        }
        $appointments = [];
        $withdrawn = [];
        usort($events, fn ($one, $two) => $one['id'] <=> $two['id']);
        foreach ($events as $event) {
            $payload = $event['payload'];
            if (in_array($event['type'], ['appointment_recorded', 'appointment_cancelled'], true)) {
                $id = $payload['appointment_id'];
                $appointments[$id] = $event;
            }
            if ($event['type'] === 'submission_retracted') {
                $withdrawn[$payload['event_id']] = true;
            }
        }
        foreach ($events as $event) {
            $payload = $event['payload'];
            if ($event['type'] === 'appointment_recorded' && ($appointments[$payload['appointment_id']]['id'] ?? null) === $event['id']) {
                $location = $payload['location'] ?? null;
                // Unknown duration and a text-only place stay unknown: null duration, routable false.
                $rows[] = ['id' => 'appointment:'.$payload['appointment_id'], 'kind' => 'appointment', 'state' => 'recorded',
                    ...$this->identity($event), 'appointment_id' => $payload['appointment_id'],
                    'starts_at' => $payload['starts_at'], 'timezone' => $payload['timezone'], 'duration_minutes' => $payload['duration_minutes'] ?? null,
                    'location' => $location, 'routable' => isset($location['lat'], $location['lng']),
                    'precision' => 'instant', 'provenance' => 'user_report', 'legal_effect' => 'not_assessed'];
            }
            $submissionDate = $event['type'] === 'submission_recorded' && ! isset($withdrawn[$event['id']]) ? $this->date($payload['occurred_on'] ?? null, $timezone) : null;
            if ($submissionDate !== null && $submissionDate->toDateString() <= $today) {
                $rows[] = ['id' => 'submission:'.$event['id'], 'kind' => 'submission_recorded', ...$this->identity($event), 'date' => $payload['occurred_on'],
                    'precision' => 'calendar_date', 'timezone' => $timezone, 'state' => 'recorded',
                    'provenance' => 'user_report', 'legal_effect' => 'not_assessed'];
            }
        }

        return $rows;
    }

    /** `event_id` is the report's stable identity; corrections target its latest `revision_event_id`. */
    private function identity(array $event): array
    {
        return ['event_id' => $event['id'], 'revision_event_id' => $event['revision_event_id'] ?? $event['id']];
    }

    private function known(array $facts, string $key): bool
    {
        return isset($facts['values'][$key]) && ($facts['states'][$key] ?? 'value') === 'value';
    }

    private function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone) : null;
    }
}
