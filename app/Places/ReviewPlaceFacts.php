<?php

namespace App\Places;

use App\Models\PlaceFactCorrection;
use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Services\OpeningHoursParser;
use DomainException;

class ReviewPlaceFacts
{
    private const ACCESS_VALUES = ['public', 'private', 'customers', 'members', 'permit', 'unknown'];

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function preview(int $spotId, array $changes): array
    {
        $normalized = $this->normalizeChanges($changes);
        $canonicalId = app(PlaceIdentity::class)->canonicalIds([$spotId])[$spotId];
        $spot = Spot::query()->findOrFail($canonicalId);
        $snapshot = $this->snapshot($spot, $normalized);

        return [
            'spot_id' => $spot->id,
            'changes' => $normalized,
            'preserved_source_values' => $snapshot['observations'],
            'fingerprint' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string, mixed> $changes */
    public function apply(int $spotId, array $changes, string $fingerprint, string $evidence, string $actor): void
    {
        $evidence = trim($evidence);
        $actor = trim($actor);
        if (mb_strlen($evidence) < 20) {
            throw new DomainException('Document the source evidence before applying place facts.');
        }
        if ($actor === '' || mb_strlen($actor) > 191) {
            throw new DomainException('A review actor is required and limited to 191 characters.');
        }

        $initial = $this->preview($spotId, $changes);
        app(PlaceIdentity::class)->withCanonicalLock($spotId, function (Spot $canonical) use ($changes, $fingerprint, $evidence, $actor, $initial): void {
            PlaceFactObservation::query()->where('spot_id', $canonical->id)->orderBy('id')->lockForUpdate()->get();
            PlaceFactCorrection::query()->where('spot_id', $canonical->id)->orderBy('id')->lockForUpdate()->get();

            $current = $this->preview($canonical->id, $changes);
            if ($initial['spot_id'] !== $current['spot_id'] || ! hash_equals($fingerprint, $current['fingerprint'])) {
                throw new DomainException('The place facts changed after preview; review a fresh preview.');
            }

            $changed = false;
            $now = now()->utc();
            foreach ($current['changes'] as $field => $value) {
                $active = PlaceFactCorrection::query()
                    ->where('spot_id', $canonical->id)
                    ->where('field', $field)
                    ->whereNull('revoked_at')
                    ->orderBy('id')
                    ->get();
                $latest = $active->last();
                if ($value === null && $active->isEmpty()) {
                    continue;
                }
                if ($value !== null && $latest !== null && $latest->value === $value && $active->count() === 1) {
                    continue;
                }

                if ($active->isNotEmpty()) {
                    PlaceFactCorrection::query()->whereKey($active->modelKeys())->update(['revoked_at' => $now, 'updated_at' => $now]);
                }
                PlaceFactCorrection::query()->create([
                    'spot_id' => $canonical->id,
                    'field' => $field,
                    'value' => $value,
                    'evidence' => $evidence,
                    'evidence_url' => $this->isHttpUrl($evidence) ? $evidence : null,
                    'actor' => $actor,
                    'reviewed_at' => $now,
                    'supersedes_id' => $latest?->id,
                    'revoked_at' => $value === null ? $now : null,
                ]);
                $changed = true;
            }

            if ($changed) {
                app(PlaceFactRevision::class)->bump();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, array<string, mixed>|null>
     */
    private function normalizeChanges(array $changes): array
    {
        if ($changes === [] || array_is_list($changes)) {
            throw new DomainException('Place fact changes must be a keyed, non-empty object.');
        }

        $normalized = [];
        foreach ($changes as $field => $value) {
            $normalized[$field] = match ($field) {
                'name' => $value === null ? null : $this->normalizeName($value),
                'access' => $value === null ? null : $this->normalizeAccess($value),
                'entrance_point' => $value === null ? null : $this->normalizeEntrancePoint($value),
                'fee' => $value === null ? null : $this->normalizeFee($value),
                'hours' => $value === null ? null : $this->normalizeHours($value),
                'contact' => $value === null ? null : $this->normalizeContact($value),
                'description' => $value === null ? null : $this->normalizeDescription($value),
                default => throw new DomainException("Unsupported place fact field: {$field}."),
            };
        }
        ksort($normalized);

        return $normalized;
    }

    /** @return array{value: string} */
    private function normalizeName(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 200) {
            throw new DomainException('Reviewed place names must contain 1 to 200 characters.');
        }

        return ['value' => trim($value)];
    }

    /** @return array{value: string} */
    private function normalizeAccess(mixed $value): array
    {
        if (! is_string($value) || ! in_array($value, self::ACCESS_VALUES, true)) {
            throw new DomainException('Reviewed access must use a supported normalized value.');
        }

        return ['value' => $value];
    }

    /** @return array{lat: float, lng: float, status: string} */
    private function normalizeEntrancePoint(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['lat', 'lng']) !== []) {
            throw new DomainException('A reviewed entrance must contain only latitude and longitude.');
        }
        if (! is_numeric($value['lat'] ?? null) || ! is_numeric($value['lng'] ?? null)) {
            throw new DomainException('A reviewed entrance requires numeric latitude and longitude.');
        }
        $lat = (float) $value['lat'];
        $lng = (float) $value['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new DomainException('Reviewed entrance coordinates are outside valid ranges.');
        }

        return ['lat' => $lat, 'lng' => $lng, 'status' => 'verified'];
    }

    /** @return array<string, mixed> */
    private function normalizeFee(mixed $value): array
    {
        $value = is_string($value) ? ['value' => $value] : $value;
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['value', 'amount', 'currency']) !== []) {
            throw new DomainException('Reviewed fee facts must contain value and optional amount/currency.');
        }
        $normalized = $value['value'] ?? null;
        if (! is_string($normalized) || ! in_array($normalized, ['free', 'paid', 'unknown'], true)) {
            throw new DomainException('Reviewed fee must be free, paid or unknown.');
        }
        $amount = $value['amount'] ?? null;
        $currency = isset($value['currency']) ? mb_strtoupper(trim((string) $value['currency'])) : null;
        if ($amount !== null && (! is_numeric($amount) || (float) $amount < 0 || $normalized !== 'paid')) {
            throw new DomainException('A fee amount must be non-negative and belong to a paid fact.');
        }
        if ($amount !== null && ($currency === null || ! preg_match('/^[A-Z]{3}$/D', $currency))) {
            throw new DomainException('A paid amount requires a three-letter currency code.');
        }

        return ['value' => $normalized, 'amount' => $amount !== null ? (float) $amount : null, 'currency' => $amount !== null ? $currency : null];
    }

    /** @return array{raw: string, parsed: ?array} */
    private function normalizeHours(mixed $value): array
    {
        $raw = is_array($value) && ! array_is_list($value) ? ($value['raw'] ?? null) : $value;
        if (is_array($value) && array_diff(array_keys($value), ['raw']) !== []) {
            throw new DomainException('Reviewed hours accept only the raw source expression.');
        }
        if (! is_string($raw) || trim($raw) === '' || mb_strlen(trim($raw)) > 255) {
            throw new DomainException('Reviewed hours must contain 1 to 255 characters.');
        }
        $raw = trim($raw);

        return ['raw' => $raw, 'parsed' => OpeningHoursParser::parse($raw)];
    }

    /** @return array<string, ?string> */
    private function normalizeContact(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value) || $value === [] || array_diff(array_keys($value), ['website', 'phone', 'address']) !== []) {
            throw new DomainException('Reviewed contact facts must contain website, phone or address.');
        }
        $normalized = [];
        foreach ($value as $field => $raw) {
            if ($raw !== null && ! is_string($raw)) {
                throw new DomainException('Reviewed contact values must be strings or null.');
            }
            $text = $raw === null ? null : trim($raw);
            if ($text !== null && mb_strlen($text) > 500) {
                throw new DomainException('Reviewed contact values are limited to 500 characters.');
            }
            if ($field === 'website' && $text !== null && ! $this->isHttpUrl($text)) {
                throw new DomainException('Reviewed websites must use http or https.');
            }
            $normalized[$field] = $text === '' ? null : $text;
        }

        return $normalized;
    }

    /** @return array{value: string} */
    private function normalizeDescription(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 1000) {
            throw new DomainException('Reviewed descriptions must contain 1 to 1000 characters.');
        }

        return ['value' => trim($value)];
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $changes
     * @return array<string, mixed>
     */
    private function snapshot(Spot $spot, array $changes): array
    {
        $attributes = $spot->attributesToArray();
        unset($attributes['location']);

        return [
            'spot' => $attributes,
            'observations' => PlaceFactObservation::query()
                ->where('spot_id', $spot->id)
                ->orderBy('id')
                ->get(['id', 'provider', 'provider_record_id', 'source_url', 'observed_at', 'ingestion_key', 'payload_hash', 'payload'])
                ->map->attributesToArray()
                ->all(),
            'active_corrections' => PlaceFactCorrection::query()
                ->where('spot_id', $spot->id)
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->get(['id', 'field', 'value', 'evidence', 'evidence_url', 'actor', 'reviewed_at'])
                ->map->attributesToArray()
                ->all(),
            'changes' => $changes,
        ];
    }

    private function isHttpUrl(string $value): bool
    {
        $scheme = mb_strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
