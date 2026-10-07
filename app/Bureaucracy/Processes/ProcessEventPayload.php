<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Facts\CalendarDate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcessEventPayload
{
    public function validate(string $type, array $payload): array
    {
        $allowed = match ($type) {
            'step_completed', 'step_reopened' => ['step_id', 'occurred_on'],
            'submission_recorded' => ['occurred_on', 'channel', 'reference'],
            'action_required_reported', 'completion_reported', 'cancellation_reported' => ['occurred_on', 'reference'],
            'appointment_recorded' => ['appointment_id', 'starts_at', 'timezone', 'duration_minutes', 'location'],
            'appointment_cancelled' => ['appointment_id'],
            'preparation_started', 'waiting_reported', 'process_reopened' => [],
            default => null,
        };
        if ($allowed === null || array_diff(array_keys($payload), $allowed) !== []) {
            throw ValidationException::withMessages(['event' => 'Use a supported reported event without extra claims.']);
        }
        foreach (['step_id' => 150, 'reference' => 200] as $key => $length) {
            if (isset($payload[$key]) && (! is_string($payload[$key]) || trim($payload[$key]) === '' || mb_strlen($payload[$key]) > $length)) {
                throw ValidationException::withMessages(['payload.'.$key => 'Use a short non-empty reference.']);
            }
        }
        if (in_array($type, ['step_completed', 'step_reopened'], true) && ! isset($payload['step_id'])) {
            throw ValidationException::withMessages(['payload.step_id' => 'Choose the step being updated.']);
        }
        if ($type === 'submission_recorded' && ! isset($payload['occurred_on'])) {
            throw ValidationException::withMessages(['payload.occurred_on' => 'Enter the actual date you submitted it.']);
        }
        if (isset($payload['occurred_on']) && CalendarDate::historical($payload['occurred_on']) === null) {
            throw ValidationException::withMessages(['payload.occurred_on' => 'Use an exact real date on or before today.']);
        }
        if (isset($payload['channel']) && ! in_array($payload['channel'], ['online', 'post', 'email', 'in_person', 'unknown'], true)) {
            throw ValidationException::withMessages(['payload.channel' => 'Choose how the submission was made.']);
        }
        if (in_array($type, ['appointment_recorded', 'appointment_cancelled'], true)) {
            if (! is_string($payload['appointment_id'] ?? null) || ! Str::isUuid($payload['appointment_id'])) {
                throw ValidationException::withMessages(['payload.appointment_id' => 'Use an appointment identifier.']);
            }
            $payload['appointment_id'] = strtolower($payload['appointment_id']);
        }
        if ($type === 'appointment_recorded') {
            $start = $payload['starts_at'] ?? null;
            $zone = $payload['timezone'] ?? null;
            if (! is_string($start) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $start)
                || ! is_string($zone) || ! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::withMessages(['payload.starts_at' => 'Use an exact appointment time, UTC offset and time zone.']);
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $start);
            if ($date === false || $date->format('Y-m-d\TH:i:sP') !== $start || (new DateTimeZone($zone))->getOffset($date) !== $date->getOffset()) {
                throw ValidationException::withMessages(['payload.starts_at' => 'That appointment time or offset is not valid in this time zone.']);
            }
            if (! is_int($payload['duration_minutes'] ?? null) || $payload['duration_minutes'] < 1 || $payload['duration_minutes'] > 1440) {
                throw ValidationException::withMessages(['payload.duration_minutes' => 'Enter an appointment duration between 1 and 1440 minutes.']);
            }
            if (isset($payload['location'])) {
                $location = $payload['location'];
                if (! is_array($location) || array_diff(array_keys($location), ['label', 'lat', 'lng']) !== []
                    || ! $this->coordinate($location['lat'] ?? null, 90) || ! $this->coordinate($location['lng'] ?? null, 180)
                    || (array_key_exists('label', $location) && (! is_string($location['label']) || trim($location['label']) === '' || mb_strlen($location['label']) > 160))) {
                    throw ValidationException::withMessages(['payload.location' => 'Choose a meeting place with a valid latitude and longitude, or leave its location unknown.']);
                }
            }
        }

        return $payload;
    }

    private function coordinate(mixed $value, int $limit): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= $limit;
    }
}
