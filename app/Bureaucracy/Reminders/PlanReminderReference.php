<?php

namespace App\Bureaucracy\Reminders;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

final class PlanReminderReference
{
    public const Schema = 'bureaucracy.attention.1';

    public function __construct(private AccountHolderPlan $plans, private PlanAttention $attention) {}

    /** No personal dates, titles or facts are put into the shared action bus. */
    public function for(User $recipient, array $row): array
    {
        return ['schema_version' => self::Schema, 'recipient_id' => $recipient->id,
            'sealed' => Crypt::encryptString(json_encode([
                'recipient_id' => $recipient->id, 'person_id' => $row['person_id'], 'jurisdiction' => $row['jurisdiction'],
                'event_id' => $row['id'], 'event_revision' => $row['event_revision'],
                'delivery_key' => ProcessingConsentStore::digest(['reminder.1', $recipient->id, $row['person_id'], $row['id'],
                    $row['kind'], $row['date'], $row['urgency'], $row['source_hash']]),
            ], JSON_THROW_ON_ERROR))];
    }

    public function resolve(?array $reference, ?int $recipientId = null): ?array
    {
        $data = $this->unseal($reference);
        if ($data === null || ($recipientId !== null && $recipientId !== $data['recipient_id'])) {
            return null;
        }
        $recipient = User::query()->find($data['recipient_id']);
        if ($recipient === null) {
            return null;
        }
        try {
            // Deliberately self-only: access to a family plan is not notification permission.
            $plan = $this->plans->for($recipient);
        } catch (AuthorizationException|DomainException $error) {
            return null;
        }
        if ($plan === null || $plan['person_id'] !== $data['person_id'] || $plan['jurisdiction'] !== $data['jurisdiction']) {
            return null;
        }
        $row = collect($this->attention->for($plan))->firstWhere('id', $data['event_id']);

        return $row !== null && hash_equals($row['event_revision'], $data['event_revision']) ? $row : null;
    }

    public function deliveryKey(array $reference): ?string
    {
        return $this->unseal($reference)['delivery_key'] ?? null;
    }

    public function muteKey(User $recipient, array $row): string
    {
        return ProcessingConsentStore::digest(['reminder-mute.1', $recipient->id, $row['person_id'], $row['id']]);
    }

    private function unseal(?array $reference): ?array
    {
        if (($reference['schema_version'] ?? null) !== self::Schema || ! is_int($reference['recipient_id'] ?? null) || ! is_string($reference['sealed'] ?? null)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($reference['sealed']), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $error) {
            return null;
        }
        if (! is_array($data) || ($data['recipient_id'] ?? null) !== $reference['recipient_id'] || ! is_int($data['person_id'] ?? null)) {
            return null;
        }
        foreach (['jurisdiction', 'event_id', 'event_revision', 'delivery_key'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                return null;
            }
        }

        return $data;
    }
}
