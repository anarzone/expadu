<?php

namespace App\Places;

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Str;

class RecordPlaceObservation
{
    private const PAYLOAD_FIELDS = [
        'name',
        'aliases',
        'location',
        'access',
        'fee',
        'hours',
        'contact',
        'description',
        'negative_facts',
    ];

    /** @param array<string, mixed> $observation */
    public function record(Spot $spot, array $observation): void
    {
        $validated = $this->validate($observation);
        $payload = $this->canonicalize($validated['payload']);
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $canonical) use ($validated, $payload, $payloadHash): void {
            $existing = PlaceFactObservation::query()
                ->where('spot_id', $canonical->id)
                ->where('provider', $validated['provider'])
                ->where('provider_record_id', $validated['provider_record_id'])
                ->where('ingestion_key', $validated['ingestion_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new DomainException('An ingestion key cannot be replayed with different place facts.');
                }

                return;
            }

            $latest = PlaceFactObservation::query()
                ->where('spot_id', $canonical->id)
                ->where('provider', $validated['provider'])
                ->where('provider_record_id', $validated['provider_record_id'])
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latest !== null && hash_equals($latest->payload_hash, $payloadHash)
                && $validated['observed_at']->greaterThanOrEqualTo($latest->observed_at)) {
                return;
            }

            PlaceFactObservation::query()->create([
                'spot_id' => $canonical->id,
                'provider' => $validated['provider'],
                'provider_record_id' => $validated['provider_record_id'],
                'source_url' => $validated['source_url'],
                'observed_at' => $validated['observed_at'],
                'ingestion_key' => $validated['ingestion_key'],
                'payload_hash' => $payloadHash,
                'payload' => $payload,
            ]);

            if ($latest === null || $validated['observed_at']->greaterThanOrEqualTo($latest->observed_at)) {
                app(PlaceFactRevision::class)->bump();
            }
        });
    }

    /** @return array<string, mixed> */
    public function previewRestore(int $observationId): array
    {
        $target = PlaceFactObservation::query()->findOrFail($observationId);
        $current = PlaceFactObservation::query()
            ->where('spot_id', $target->spot_id)
            ->where('provider', $target->provider)
            ->where('provider_record_id', $target->provider_record_id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->firstOrFail();

        return [
            'spot_id' => $target->spot_id,
            'provider' => $target->provider,
            'provider_record_id' => $target->provider_record_id,
            'target' => $this->restoreSnapshot($target),
            'current' => $this->restoreSnapshot($current),
            'already_restored' => $current->record_kind === 'restore'
                && $current->restores_observation_id === $target->id,
        ];
    }

    public function restore(
        int $observationId,
        string $expectedCurrentHash,
        string $actor,
        string $reason,
    ): bool {
        $expectedCurrentHash = mb_strtolower(trim($expectedCurrentHash));
        $actor = trim($actor);
        $reason = trim($reason);
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedCurrentHash) !== 1) {
            throw new DomainException('An exact expected-current payload hash is required.');
        }
        if ($actor === '' || mb_strlen($actor) > 191) {
            throw new DomainException('A restore actor is required and limited to 191 characters.');
        }
        if (mb_strlen($reason) < 20) {
            throw new DomainException('Document why the source observation is being restored.');
        }

        $target = PlaceFactObservation::query()->findOrFail($observationId);

        return app(PlaceIdentity::class)->withCanonicalLock($target->spot_id, function (Spot $canonical) use ($observationId, $expectedCurrentHash, $actor, $reason): bool {
            $target = PlaceFactObservation::query()
                ->whereKey($observationId)
                ->where('spot_id', $canonical->id)
                ->lockForUpdate()
                ->first();
            if ($target === null) {
                throw new DomainException('The source observation moved while preparing the restore; preview again.');
            }

            $current = PlaceFactObservation::query()
                ->where('spot_id', $canonical->id)
                ->where('provider', $target->provider)
                ->where('provider_record_id', $target->provider_record_id)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->record_kind === 'restore'
                && $current->restores_observation_id === $target->id
                && $current->actor === $actor) {
                return false;
            }
            if (! hash_equals($current->payload_hash, $expectedCurrentHash)) {
                throw new DomainException('The current source observation changed after preview; preview the restore again.');
            }

            PlaceFactObservation::query()->create([
                'spot_id' => $canonical->id,
                'provider' => $target->provider,
                'provider_record_id' => $target->provider_record_id,
                'source_url' => $target->source_url,
                'observed_at' => now()->utc(),
                'ingestion_key' => 'restore:'.Str::uuid(),
                'payload_hash' => $target->payload_hash,
                'payload' => $target->payload,
                'record_kind' => 'restore',
                'restores_observation_id' => $target->id,
                'actor' => $actor,
                'reason' => $reason,
            ]);
            app(PlaceFactRevision::class)->bump();

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function restoreSnapshot(PlaceFactObservation $observation): array
    {
        return [
            'observation_id' => $observation->id,
            'payload_hash' => $observation->payload_hash,
            'payload' => $observation->payload,
            'source_url' => $observation->source_url,
            'observed_at' => $observation->observed_at?->toIso8601String(),
            'record_kind' => $observation->record_kind,
            'restores_observation_id' => $observation->restores_observation_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $observation
     * @return array{provider: string, provider_record_id: string, source_url: ?string, observed_at: CarbonImmutable, ingestion_key: string, payload: array<string, mixed>}
     */
    private function validate(array $observation): array
    {
        $provider = trim((string) ($observation['provider'] ?? ''));
        $recordId = trim((string) ($observation['provider_record_id'] ?? ''));
        $ingestionKey = trim((string) ($observation['ingestion_key'] ?? ''));
        $sourceUrl = isset($observation['source_url']) ? trim((string) $observation['source_url']) : null;
        $payload = $observation['payload'] ?? null;

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/D', $provider)) {
            throw new DomainException('Observation provider must be a stable lowercase identifier.');
        }
        if ($recordId === '' || mb_strlen($recordId) > 191 || $ingestionKey === '' || mb_strlen($ingestionKey) > 191) {
            throw new DomainException('Observation record and ingestion identifiers are required and limited to 191 characters.');
        }
        if ($sourceUrl !== null && ($sourceUrl === '' || ! $this->isHttpUrl($sourceUrl))) {
            throw new DomainException('Observation source URL must use http or https.');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new DomainException('Observation payload must be a keyed fact object.');
        }
        $unsupported = array_diff(array_keys($payload), self::PAYLOAD_FIELDS);
        if ($unsupported !== []) {
            throw new DomainException('Observation contains unsupported fact fields: '.implode(', ', $unsupported).'.');
        }
        $this->validatePayload($payload);

        try {
            $observedAt = CarbonImmutable::parse((string) ($observation['observed_at'] ?? ''))->utc();
        } catch (\Throwable) {
            throw new DomainException('Observation time must be a valid timestamp.');
        }

        return [
            'provider' => $provider,
            'provider_record_id' => $recordId,
            'source_url' => $sourceUrl,
            'observed_at' => $observedAt,
            'ingestion_key' => $ingestionKey,
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function canonicalize(array $payload): array
    {
        $walk = function (mixed $value) use (&$walk): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($walk, $value);
        };

        return $walk($payload);
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload): void
    {
        if (array_key_exists('name', $payload)) {
            $this->validateNullableText($payload['name'], 255, 'Observation names');
        }

        if (array_key_exists('aliases', $payload)) {
            if (! is_array($payload['aliases']) || ! array_is_list($payload['aliases']) || count($payload['aliases']) > 50) {
                throw new DomainException('Observation aliases must be a list of at most 50 names.');
            }
            foreach ($payload['aliases'] as $alias) {
                $this->validateNullableText($alias, 255, 'Observation aliases', allowNull: false);
            }
        }

        if (array_key_exists('location', $payload)) {
            $location = $this->keyedObject($payload['location'], ['lat', 'lng', 'kind', 'boundary_reference', 'entrance_point'], 'Observation locations');
            if (! is_numeric($location['lat'] ?? null) || ! is_numeric($location['lng'] ?? null)) {
                throw new DomainException('Observation locations require numeric latitude and longitude.');
            }
            $lat = (float) $location['lat'];
            $lng = (float) $location['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                throw new DomainException('Observation coordinates are outside valid ranges.');
            }
            if (! in_array($location['kind'] ?? null, ['source_node', 'source_center', 'legacy_unknown'], true)) {
                throw new DomainException('Observation locations require a supported coordinate kind.');
            }
            if (array_key_exists('boundary_reference', $location)) {
                $this->validateNullableText($location['boundary_reference'], 191, 'Boundary references');
            }
            if (array_key_exists('entrance_point', $location) && $location['entrance_point'] !== null) {
                $entrance = $this->keyedObject($location['entrance_point'], ['lat', 'lng', 'status'], 'Observation entrance candidates');
                if (! is_numeric($entrance['lat'] ?? null) || ! is_numeric($entrance['lng'] ?? null)
                    || ($entrance['status'] ?? null) !== 'candidate') {
                    throw new DomainException('Observation entrance candidates require numeric coordinates and candidate status.');
                }
                $entranceLat = (float) $entrance['lat'];
                $entranceLng = (float) $entrance['lng'];
                if ($entranceLat < -90 || $entranceLat > 90 || $entranceLng < -180 || $entranceLng > 180) {
                    throw new DomainException('Observation entrance coordinates are outside valid ranges.');
                }
            }
        }

        if (array_key_exists('access', $payload)) {
            $access = $this->keyedObject($payload['access'], ['raw', 'conditional'], 'Observation access facts');
            foreach (['raw', 'conditional'] as $field) {
                if (array_key_exists($field, $access)) {
                    $this->validateNullableText($access[$field], 500, 'Observation access values');
                }
            }
        }

        if (array_key_exists('fee', $payload)) {
            $fee = $this->keyedObject($payload['fee'], ['raw', 'amount', 'currency'], 'Observation fee facts');
            if (array_key_exists('raw', $fee)) {
                $this->validateNullableText($fee['raw'], 255, 'Observation fee values');
            }
            if (isset($fee['amount']) && (! is_numeric($fee['amount']) || (float) $fee['amount'] < 0)) {
                throw new DomainException('Observation fee amounts must be non-negative numbers.');
            }
            if (isset($fee['currency']) && (! is_string($fee['currency']) || preg_match('/^[A-Za-z]{3}$/D', $fee['currency']) !== 1)) {
                throw new DomainException('Observation fee currencies must use three-letter codes.');
            }
        }

        if (array_key_exists('hours', $payload)) {
            $hours = $this->keyedObject($payload['hours'], ['raw'], 'Observation hours');
            if (array_key_exists('raw', $hours)) {
                $this->validateNullableText($hours['raw'], 500, 'Observation hours');
            }
        }

        if (array_key_exists('contact', $payload)) {
            $contact = $this->keyedObject($payload['contact'], ['website', 'phone', 'address'], 'Observation contact facts');
            foreach (['website', 'phone', 'address'] as $field) {
                if (array_key_exists($field, $contact)) {
                    $this->validateNullableText($contact[$field], 500, 'Observation contact values');
                }
            }
            $website = $contact['website'] ?? null;
            if (is_string($website) && trim($website) !== '' && ! $this->isHttpUrl(trim($website))) {
                throw new DomainException('Observation websites must use http or https.');
            }
        }

        if (array_key_exists('description', $payload)) {
            $this->validateNullableText($payload['description'], 1000, 'Observation descriptions');
        }

        if (array_key_exists('negative_facts', $payload)) {
            $facts = $this->keyedObject($payload['negative_facts'], null, 'Observation negative facts');
            foreach ($facts as $key => $value) {
                if (! is_string($key) || preg_match('/^[a-z0-9][a-z0-9:_-]{0,99}$/D', $key) !== 1) {
                    throw new DomainException('Observation negative-fact keys must be stable identifiers.');
                }
                $this->validateNullableText($value, 100, 'Observation negative-fact values', allowNull: false);
            }
        }
    }

    /**
     * @param  list<string>|null  $allowedKeys
     * @return array<string, mixed>
     */
    private function keyedObject(mixed $value, ?array $allowedKeys, string $label): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new DomainException("{$label} must be a keyed object.");
        }
        if ($allowedKeys !== null && array_diff(array_keys($value), $allowedKeys) !== []) {
            throw new DomainException("{$label} contain unsupported fields.");
        }

        return $value;
    }

    private function validateNullableText(mixed $value, int $max, string $label, bool $allowNull = true): void
    {
        if ($value === null && $allowNull) {
            return;
        }
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $max) {
            throw new DomainException("{$label} must contain 1 to {$max} characters.");
        }
    }
}
