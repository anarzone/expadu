<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Facts\CalendarDate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcessEventPayload
{
    /** A private free-text note; stored only inside the encrypted event payload and process state. */
    public const NoteLength = 500;

    public function validate(string $type, array $payload): array
    {
        $allowed = match ($type) {
            'step_completed', 'step_reopened' => ['step_id', 'occurred_on'],
            'submission_recorded' => ['occurred_on', 'channel', 'reference'],
            'submission_retracted' => ['event_id', 'note'],
            'action_required_reported', 'completion_reported', 'cancellation_reported' => ['occurred_on', 'reference', 'note'],
            'blocked_reported' => ['occurred_on', 'note'],
            'appointment_recorded' => ['appointment_id', 'starts_at', 'timezone', 'duration_minutes', 'location'],
            'appointment_cancelled' => ['appointment_id'],
            'preparation_started', 'waiting_reported', 'process_reopened' => ['note'],
            'process_untracked' => [],
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
        if (array_key_exists('note', $payload) && (! is_string($payload['note']) || trim($payload['note']) === '' || mb_strlen($payload['note']) > self::NoteLength)) {
            throw ValidationException::withMessages(['payload.note' => 'Keep the note to '.self::NoteLength.' characters, or leave it out.']);
        }
        if (isset($payload['note'])) {
            $payload['note'] = trim($payload['note']);
        }
        if ($type === 'submission_retracted' && (! is_int($payload['event_id'] ?? null) || $payload['event_id'] < 1)) {
            throw ValidationException::withMessages(['payload.event_id' => 'Choose the submission report to withdraw.']);
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
            // The key is required so an unknown length is an explicit null, never a silent default.
            $duration = $payload['duration_minutes'] ?? null;
            if (! array_key_exists('duration_minutes', $payload) || ($duration !== null && (! is_int($duration) || $duration < 1 || $duration > 1440))) {
                throw ValidationException::withMessages(['payload.duration_minutes' => 'Enter the appointment length in minutes (1 to 1440), or null if you do not know it.']);
            }
            if (isset($payload['location'])) {
                $location = $payload['location'];
                $valid = is_array($location) && array_diff(array_keys($location), ['label', 'lat', 'lng']) === []
                    && (! array_key_exists('label', $location) || (is_string($location['label']) && trim($location['label']) !== '' && mb_strlen($location['label']) <= 160));
                $mapped = $valid && (array_key_exists('lat', $location) || array_key_exists('lng', $location));
                if (! $valid || ($mapped && (! $this->coordinate($location['lat'] ?? null, 90) || ! $this->coordinate($location['lng'] ?? null, 180)))
                    || (! $mapped && ! array_key_exists('label', $location))) {
                    throw ValidationException::withMessages(['payload.location' => 'Choose a meeting place with a valid latitude and longitude, describe it with a short label, or leave its location unknown.']);
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
