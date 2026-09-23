<?php

namespace App\Places;

use App\Enums\SpotCategory;
use App\Models\PlaceFactCorrection;
use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Services\OpeningHoursParser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class PlaceFacts
{
    public const SNAPSHOT_RELATION = 'placeFactsSnapshot';

    private const RESTRICTED_ACCESS = ['private', 'no', 'customers', 'members', 'permit'];

    /**
     * Keep explicitly restricted places out of public recommendation pools.
     * Unknown access remains visible, but is represented as unknown to callers.
     *
     * @param  Builder<Spot>|QueryBuilder  $query
     */
    public function publiclyRecommendable(Builder|QueryBuilder $query, string $table = 'spots'): Builder|QueryBuilder
    {
        if (! in_array($table, ['spots', 'destination'], true)) {
            throw new \InvalidArgumentException('Unsupported place access-policy table alias.');
        }

        $policy = <<<'SQL'
            (
                WITH current_observations AS MATERIALIZED (
                    SELECT DISTINCT ON (provider, provider_record_id)
                        provider, provider_record_id, observed_at, payload
                    FROM place_fact_observations
                    WHERE spot_id = spots.id
                    ORDER BY provider, provider_record_id, observed_at DESC, id DESC
                ), active_corrections AS MATERIALIZED (
                    SELECT value, reviewed_at
                    FROM place_fact_corrections
                    WHERE spot_id = spots.id AND field = 'access' AND revoked_at IS NULL
                )
                SELECT CASE
                    WHEN (SELECT count(*) FROM active_corrections) > 1 THEN false
                    WHEN EXISTS (SELECT 1 FROM active_corrections) THEN
                        NOT EXISTS (
                            SELECT 1 FROM active_corrections
                            WHERE lower(COALESCE(value->>'value', 'unknown')) IN ('private', 'no', 'customers', 'members', 'permit')
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM current_observations
                            WHERE jsonb_exists(payload, 'access')
                              AND observed_at > (SELECT max(reviewed_at) FROM active_corrections)
                              AND (
                                  lower(COALESCE(payload #>> '{access,raw}', payload->>'access', '')) IN ('private', 'no', 'customers', 'members', 'permit')
                                  OR nullif(trim(COALESCE(payload #>> '{access,conditional}', '')), '') IS NOT NULL
                              )
                        )
                    WHEN EXISTS (SELECT 1 FROM current_observations WHERE jsonb_exists(payload, 'access')) THEN
                        NOT EXISTS (
                            SELECT 1 FROM current_observations
                            WHERE jsonb_exists(payload, 'access')
                              AND (
                                  lower(COALESCE(payload #>> '{access,raw}', payload->>'access', '')) IN ('private', 'no', 'customers', 'members', 'permit')
                                  OR nullif(trim(COALESCE(payload #>> '{access,conditional}', '')), '') IS NOT NULL
                              )
                        )
                    WHEN EXISTS (
                        SELECT 1 FROM current_observations
                        WHERE provider = spots.source AND provider_record_id = spots.source_id
                    ) THEN true
                    ELSE
                        LOWER(COALESCE(spots.tags->>'access', '')) NOT IN ('private', 'no', 'customers', 'members', 'permit')
                        AND NOT COALESCE(jsonb_exists(spots.tags::jsonb, 'access:conditional'), false)
                END
            )
            SQL;

        return $query->whereRaw(str_replace('spots.', $table.'.', $policy));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(Spot $spot): array
    {
        if ($spot->relationLoaded(self::SNAPSHOT_RELATION)) {
            return $spot->getRelation(self::SNAPSHOT_RELATION);
        }

        $canonicalId = app(PlaceIdentity::class)->canonicalIds([$spot->id])[$spot->id];
        $spot = $spot->id === $canonicalId ? $spot : Spot::query()->findOrFail($canonicalId);
        $spot->loadMissing(['factObservations', 'factCorrections']);

        return $this->resolveLoaded($spot, $this->revision());
    }

    /**
     * Resolve a result page with two fact queries and one shared revision read.
     *
     * @param  Collection<int, Spot>  $spots
     * @return Collection<int, array<string, mixed>>
     */
    public function resolveMany(Collection $spots): Collection
    {
        if ($spots->isEmpty()) {
            return collect();
        }

        $spotIds = $spots->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $canonicalMap = app(PlaceIdentity::class)->canonicalIds($spotIds);
        $canonicalIds = array_values(array_unique(array_values($canonicalMap)));
        $canonicalSpots = Spot::query()->whereIn('id', $canonicalIds)->get()->keyBy('id');
        $canonicalSpots->loadMissing(['factObservations', 'factCorrections']);
        $revision = $this->revision();

        return $spots->mapWithKeys(function (Spot $spot) use ($canonicalMap, $canonicalSpots, $revision): array {
            $canonical = $canonicalSpots->get($canonicalMap[$spot->id]);
            if (! $canonical instanceof Spot) {
                throw new \LogicException('Canonical place disappeared while resolving place facts.');
            }

            return [$spot->id => $this->resolveLoaded($canonical, $revision)];
        });
    }

    /** @param Collection<int, Spot> $spots */
    public function attach(Collection $spots): void
    {
        $facts = $this->resolveMany($spots);
        foreach ($spots as $spot) {
            $spot->setRelation(self::SNAPSHOT_RELATION, $facts->get($spot->id));
        }
    }

    /**
     * Apply effective facts to in-memory models for legacy consumers. Source
     * columns remain untouched in the database and retain their audit value.
     *
     * @param  Collection<int, Spot>  $spots
     */
    public function project(Collection $spots): void
    {
        $this->attach($spots);

        foreach ($spots as $spot) {
            /** @var array<string, mixed> $facts */
            $facts = $spot->getRelation(self::SNAPSHOT_RELATION);
            $mapPoint = $facts['location']['map_point'];
            $spot->setAttribute('name', $facts['name']['value']);
            $spot->setAttribute('lat', $mapPoint['lat']);
            $spot->setAttribute('lng', $mapPoint['lng']);
            $spot->setAttribute('address', $facts['contact']['address']['value']);
            $spot->setAttribute('phone', $facts['contact']['phone']['value']);
            $spot->setAttribute('website', $facts['contact']['website']['value']);
            $spot->setAttribute('opening_hours', $facts['hours']['parsed']);
            $spot->setAttribute('description', $facts['description']['value']);
        }
    }

    /** @return array<string, mixed> */
    private function resolveLoaded(Spot $spot, int $revision): array
    {
        /** @var Collection<int, PlaceFactObservation> $observations */
        $observations = $spot->factObservations;
        $corrections = $spot->factCorrections
            ->filter(fn (PlaceFactCorrection $correction): bool => $correction->revoked_at === null)
            ->groupBy('field');
        $tags = is_array($spot->tags) ? $spot->tags : [];
        $sourceUrl = $this->sourceUrl($spot);
        $effectiveObservations = $this->effectiveObservations($observations);
        $useLegacyProjection = $this->mayUseLegacyProjection($spot, $effectiveObservations);
        $name = $this->resolveName($spot, $observations, $effectiveObservations, $corrections->get('name', collect()), $useLegacyProjection);
        $access = $this->resolveAccess($tags, $sourceUrl, $effectiveObservations, $corrections->get('access', collect()), $useLegacyProjection);
        $location = $this->resolveLocation($spot, $sourceUrl, $effectiveObservations, $corrections->get('entrance_point', collect()), $useLegacyProjection);
        $fee = $this->resolveFee($tags, $sourceUrl, $effectiveObservations, $corrections->get('fee', collect()), $useLegacyProjection);
        $hours = $this->resolveHours($spot, $tags, $sourceUrl, $effectiveObservations, $corrections->get('hours', collect()), $useLegacyProjection);
        $contact = $this->resolveContact($spot, $sourceUrl, $effectiveObservations, $corrections->get('contact', collect()), $useLegacyProjection);
        $description = $this->resolveDescription($spot, $sourceUrl, $effectiveObservations, $corrections->get('description', collect()), $useLegacyProjection);
        $negativeFacts = $this->resolveNegativeFacts($effectiveObservations);

        return [
            'name' => $name['fact'],
            'name_kind' => $name['kind'],
            'aliases' => $name['aliases'],
            'location' => $location,
            'access' => $access['fact'],
            'fee' => $fee,
            'hours' => $hours,
            'contact' => $contact,
            'description' => $description,
            'negative_facts' => $negativeFacts,
            'conflicts' => $access['conflicting'] ? ['access'] : [],
            'revision' => $revision,
        ];
    }

    public function revision(): int
    {
        return app(PlaceFactRevision::class)->current();
    }

    /** @return array{value: mixed, status: string, source_url: ?string, observed_at: null, reviewed_at: null} */
    private function fact(mixed $value, string $status, ?string $sourceUrl): array
    {
        return [
            'value' => $value,
            'status' => $status,
            'source_url' => $sourceUrl,
            'observed_at' => null,
            'reviewed_at' => null,
        ];
    }

    /**
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @param  Collection<int, PlaceFactCorrection>  $corrections
     * @return array{fact: array<string, mixed>, kind: string, aliases: list<string>}
     */
    private function resolveName(Spot $spot, Collection $observations, Collection $effectiveObservations, Collection $corrections, bool $useLegacyProjection): array
    {
        $names = $observations
            ->flatMap(function (PlaceFactObservation $row): array {
                $aliases = is_array($row->payload['aliases'] ?? null) ? $row->payload['aliases'] : [];

                return [$row->payload['name'] ?? null, ...$aliases];
            })
            ->map(fn (mixed $name): ?string => $this->stringValue($name))
            ->filter()
            ->unique()
            ->values();
        $latestSource = $this->latestWithField($effectiveObservations, 'name');
        $correction = $corrections->last();

        if ($correction !== null) {
            $value = $this->stringValue($correction->value['value'] ?? null);

            return [
                'fact' => [
                    ...$this->fact($value, $corrections->count() === 1 ? 'known' : 'conflicting', $correction->evidence_url),
                    'reviewed_at' => $correction->reviewed_at?->toIso8601String(),
                ],
                'kind' => 'reviewed',
                'aliases' => $names->all(),
            ];
        }

        $sourceValue = $latestSource !== null ? $this->stringValue($latestSource->payload['name'] ?? null) : null;
        $latestObservation = $effectiveObservations
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id])
            ->last();
        $descriptiveFallback = $this->descriptiveName($spot);
        $value = match (true) {
            $sourceValue !== null => $sourceValue,
            ! $useLegacyProjection && $latestObservation !== null => $descriptiveFallback,
            default => $spot->name,
        };
        $kind = match (true) {
            $sourceValue !== null => 'source',
            ! $useLegacyProjection && $latestObservation !== null => 'descriptive',
            $this->isDescriptiveName($value) => 'descriptive',
            default => 'source',
        };

        return [
            'fact' => [
                ...$this->fact($value, $value !== null ? 'known' : 'unknown', $latestSource?->source_url ?? $latestObservation?->source_url ?? $this->sourceUrl($spot)),
                'observed_at' => ($latestSource ?? $latestObservation)?->observed_at?->toIso8601String(),
            ],
            'kind' => $kind,
            'aliases' => $names->reject(fn (string $name) => $name === $value)->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $tags
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @param  Collection<int, PlaceFactCorrection>  $corrections
     * @return array{fact: array<string, mixed>, conflicting: bool}
     */
    private function resolveAccess(array $tags, ?string $legacySourceUrl, Collection $observations, Collection $corrections, bool $useLegacyProjection): array
    {
        $latestByRecord = $observations
            ->filter(fn (PlaceFactObservation $row) => array_key_exists('access', $row->payload))
            ->values();
        $sources = $latestByRecord->map(function (PlaceFactObservation $row): array {
            $access = $row->payload['access'];
            $raw = $this->stringValue(is_array($access) ? ($access['raw'] ?? null) : $access);
            $conditional = $this->stringValue(is_array($access) ? ($access['conditional'] ?? null) : null);

            return [
                'value' => $this->normalizeAccess($raw, ['access:conditional' => $conditional]),
                'raw' => $raw,
                'conditional' => $conditional,
                'source_url' => $row->source_url,
                'observed_at' => $row->observed_at,
            ];
        });
        if ($sources->isEmpty()) {
            $raw = $useLegacyProjection ? $this->stringValue($tags['access'] ?? null) : null;
            $conditional = $useLegacyProjection ? $this->stringValue($tags['access:conditional'] ?? null) : null;
            $sources = collect([[
                'value' => $this->normalizeAccess($raw, ['access:conditional' => $conditional]),
                'raw' => $raw,
                'conditional' => $conditional,
                'source_url' => $raw !== null || $conditional !== null ? $legacySourceUrl : null,
                'observed_at' => null,
            ]]);
        }

        $latest = $sources->sortBy(fn (array $source) => $source['observed_at']?->getTimestamp() ?? 0)->last();
        $knownValues = $sources->pluck('value')->reject(fn (string $value) => $value === 'unknown')->unique()->values();
        $correction = $corrections->last();
        $conflicting = $knownValues->count() > 1 || $corrections->count() > 1;
        $value = $latest['value'];

        if ($correction !== null) {
            $reviewedValue = (string) ($correction->value['value'] ?? 'unknown');
            $newerRestriction = $sources
                ->filter(fn (array $source) => in_array($source['value'], self::RESTRICTED_ACCESS, true) || $source['conditional'] !== null)
                ->filter(fn (array $source) => $source['observed_at'] instanceof CarbonImmutable && $source['observed_at']->greaterThan($correction->reviewed_at))
                ->sortBy(fn (array $source) => $source['observed_at']->getTimestamp())
                ->last();
            if ($reviewedValue === 'public' && $newerRestriction !== null) {
                $value = $newerRestriction['value'];
                $latest = $newerRestriction;
                $conflicting = true;
            } else {
                $value = $reviewedValue;
            }
        }

        return [
            'fact' => [
                ...$this->fact($value, $conflicting ? 'conflicting' : ($value === 'unknown' ? 'unknown' : 'known'), $correction?->evidence_url ?? $latest['source_url']),
                'raw' => $latest['raw'],
                'conditional' => $latest['conditional'],
                'observed_at' => $latest['observed_at']?->toIso8601String(),
                'reviewed_at' => $correction?->reviewed_at?->toIso8601String(),
                'reviewed_value' => $correction?->value['value'] ?? null,
            ],
            'conflicting' => $conflicting,
        ];
    }

    /**
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @return Collection<int, PlaceFactObservation>
     */
    private function effectiveObservations(Collection $observations): Collection
    {
        return $observations
            ->groupBy(fn (PlaceFactObservation $row) => $row->provider."\0".$row->provider_record_id)
            ->map->last()
            ->values();
    }

    /**
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @return array<string, mixed>
     */
    private function resolveLocation(Spot $spot, ?string $legacySourceUrl, Collection $observations, Collection $entranceCorrections, bool $useLegacyProjection): array
    {
        $row = $this->latestWithField($observations, 'location');
        $source = is_array($row?->payload['location'] ?? null) ? $row->payload['location'] : [];
        $lat = is_numeric($source['lat'] ?? null) ? (float) $source['lat'] : ($useLegacyProjection && $spot->lat !== null ? (float) $spot->lat : null);
        $lng = is_numeric($source['lng'] ?? null) ? (float) $source['lng'] : ($useLegacyProjection && $spot->lng !== null ? (float) $spot->lng : null);
        $kind = in_array($source['kind'] ?? null, ['source_node', 'source_center', 'legacy_unknown'], true)
            ? $source['kind']
            : match (true) {
                str_starts_with((string) $spot->source_id, 'node/') => 'source_node',
                str_starts_with((string) $spot->source_id, 'way/'), str_starts_with((string) $spot->source_id, 'relation/') => 'source_center',
                default => 'legacy_unknown',
            };

        $entrance = $entranceCorrections->last();
        $candidate = is_array($source['entrance_point'] ?? null) ? $source['entrance_point'] : [];
        $candidateLat = is_numeric($candidate['lat'] ?? null) ? (float) $candidate['lat'] : null;
        $candidateLng = is_numeric($candidate['lng'] ?? null) ? (float) $candidate['lng'] : null;

        return [
            'map_point' => [
                'lat' => $lat,
                'lng' => $lng,
                'kind' => $kind,
                'boundary_reference' => $this->stringValue($source['boundary_reference'] ?? null),
                'status' => $lat !== null && $lng !== null ? 'known' : 'unknown',
                'source_url' => $row?->source_url ?? ($useLegacyProjection ? $legacySourceUrl : null),
                'observed_at' => $row?->observed_at?->toIso8601String(),
                'reviewed_at' => null,
            ],
            'entrance_point' => [
                'lat' => $entrance !== null ? (float) $entrance->value['lat'] : $candidateLat,
                'lng' => $entrance !== null ? (float) $entrance->value['lng'] : $candidateLng,
                'status' => $entrance?->value['status'] ?? ($candidateLat !== null && $candidateLng !== null ? 'candidate' : 'unknown'),
                'source_url' => $entrance?->evidence_url ?? ($candidateLat !== null && $candidateLng !== null ? $row?->source_url : null),
                'observed_at' => $entrance === null && $candidateLat !== null && $candidateLng !== null ? $row?->observed_at?->toIso8601String() : null,
                'reviewed_at' => $entrance?->reviewed_at?->toIso8601String(),
            ],
        ];
    }

    /** @param array<string, mixed> $tags
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @return array<string, mixed>
     */
    private function resolveFee(array $tags, ?string $legacySourceUrl, Collection $observations, Collection $corrections, bool $useLegacyProjection): array
    {
        $row = $this->latestWithField($observations, 'fee');
        $source = is_array($row?->payload['fee'] ?? null) ? $row->payload['fee'] : [];
        $raw = $row !== null
            ? $this->stringValue(array_key_exists('raw', $source) ? $source['raw'] : null)
            : ($useLegacyProjection ? $this->stringValue($tags['fee'] ?? null) : null);
        $value = match (mb_strtolower((string) $raw)) {
            'no', 'free' => 'free',
            'yes', 'paid' => 'paid',
            default => 'unknown',
        };

        $correction = $corrections->last();
        if ($correction !== null) {
            $value = (string) ($correction->value['value'] ?? 'unknown');
        }

        return [
            ...$this->fact($value, $corrections->count() > 1 ? 'conflicting' : ($value === 'unknown' ? 'unknown' : 'known'), $correction?->evidence_url ?? ($row?->source_url ?? ($raw !== null ? $legacySourceUrl : null))),
            'raw' => $raw,
            'amount' => $correction?->value['amount'] ?? (is_numeric($source['amount'] ?? null) ? (float) $source['amount'] : null),
            'currency' => $correction?->value['currency'] ?? $this->stringValue($source['currency'] ?? null),
            'observed_at' => $row?->observed_at?->toIso8601String(),
            'reviewed_at' => $correction?->reviewed_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $tags
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @return array<string, mixed>
     */
    private function resolveHours(Spot $spot, array $tags, ?string $legacySourceUrl, Collection $observations, Collection $corrections, bool $useLegacyProjection): array
    {
        $row = $this->latestWithField($observations, 'hours');
        $source = is_array($row?->payload['hours'] ?? null) ? $row->payload['hours'] : [];
        $raw = $row !== null
            ? $this->stringValue(array_key_exists('raw', $source) ? $source['raw'] : null)
            : ($useLegacyProjection ? $this->stringValue($tags['opening_hours'] ?? null) : null);
        $parsed = $raw !== null
            ? OpeningHoursParser::parse($raw)
            : ($row === null && $useLegacyProjection && is_array($spot->opening_hours) ? $spot->opening_hours : null);

        $correction = $corrections->last();
        if ($correction !== null) {
            $raw = $this->stringValue($correction->value['raw'] ?? null);
            $parsed = is_array($correction->value['parsed'] ?? null) ? $correction->value['parsed'] : null;
        }

        return [
            ...$this->fact($parsed, $corrections->count() > 1 ? 'conflicting' : ($parsed !== null ? 'known' : 'unknown'), $correction?->evidence_url ?? ($row?->source_url ?? ($raw !== null || $parsed !== null ? $legacySourceUrl : null))),
            'raw' => $raw,
            'parsed' => $parsed,
            'observed_at' => $row?->observed_at?->toIso8601String(),
            'reviewed_at' => $correction?->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @return array<string, array<string, mixed>>
     */
    private function resolveContact(Spot $spot, ?string $legacySourceUrl, Collection $observations, Collection $corrections, bool $useLegacyProjection): array
    {
        $row = $this->latestWithField($observations, 'contact');
        $source = is_array($row?->payload['contact'] ?? null) ? $row->payload['contact'] : [];
        $values = collect(['website', 'phone', 'address'])->mapWithKeys(fn (string $field): array => [
            $field => $row !== null
                ? $this->stringValue(array_key_exists($field, $source) ? $source[$field] : null)
                : ($useLegacyProjection ? $this->stringValue($spot->{$field}) : null),
        ])->all();

        $correction = $corrections->last();

        return collect($values)->map(function (?string $value, string $field) use ($correction, $corrections, $row, $legacySourceUrl): array {
            if ($correction !== null && array_key_exists($field, $correction->value)) {
                $value = $this->stringValue($correction->value[$field]);
            }

            return [
                ...$this->fact($value, $corrections->count() > 1 ? 'conflicting' : ($value !== null ? 'known' : 'unknown'), $correction?->evidence_url ?? ($row?->source_url ?? ($value !== null ? $legacySourceUrl : null))),
                'observed_at' => $row?->observed_at?->toIso8601String(),
                'reviewed_at' => $correction?->reviewed_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, PlaceFactObservation>  $observations
     * @return array<string, mixed>
     */
    private function resolveDescription(Spot $spot, ?string $legacySourceUrl, Collection $observations, Collection $corrections, bool $useLegacyProjection): array
    {
        $row = $this->latestWithField($observations, 'description');
        $value = $row !== null
            ? $this->stringValue($row->payload['description'] ?? null)
            : ($useLegacyProjection ? $this->stringValue($spot->description) : null);

        $correction = $corrections->last();
        if ($correction !== null) {
            $value = $this->stringValue($correction->value['value'] ?? null);
        }

        return [
            ...$this->fact($value, $corrections->count() > 1 ? 'conflicting' : ($value !== null ? 'known' : 'unknown'), $correction?->evidence_url ?? ($row?->source_url ?? ($value !== null ? $legacySourceUrl : null))),
            'observed_at' => $row?->observed_at?->toIso8601String(),
            'reviewed_at' => $correction?->reviewed_at?->toIso8601String(),
        ];
    }

    /** @param Collection<int, PlaceFactObservation> $observations
     * @return array<string, mixed>
     */
    private function resolveNegativeFacts(Collection $observations): array
    {
        $facts = [];
        foreach ($observations->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id]) as $row) {
            $sourceFacts = $row->payload['negative_facts'] ?? null;
            if (is_array($sourceFacts) && ! array_is_list($sourceFacts)) {
                $facts = [...$facts, ...$sourceFacts];
            }
        }

        return $facts;
    }

    /** @param Collection<int, PlaceFactObservation> $observations */
    private function latestWithField(Collection $observations, string $field): ?PlaceFactObservation
    {
        return $observations
            ->filter(fn (PlaceFactObservation $row) => array_key_exists($field, $row->payload))
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id])
            ->last();
    }

    private function isDescriptiveName(string $name): bool
    {
        return (bool) preg_match('/^(Spielplatz|Bolzplatz|Basketballplatz|Tennisplatz|Tischtennisplatte|Boulebahn|Skatepark|Hundewiese|Grillplatz|Picknickplatz)(\s*·.*)?$/iu', $name);
    }

    private function descriptiveName(Spot $spot): string
    {
        if ($this->isDescriptiveName($spot->name)) {
            return $spot->name;
        }

        $category = $spot->category instanceof \BackedEnum ? $spot->category->value : (string) $spot->category;

        return SpotCategory::tryFrom($category)?->label() ?? 'Place';
    }

    /** @param Collection<int, PlaceFactObservation> $observations */
    private function mayUseLegacyProjection(Spot $spot, Collection $observations): bool
    {
        if (! is_string($spot->source) || $spot->source === '' || ! is_string($spot->source_id) || $spot->source_id === '') {
            return true;
        }

        return ! $observations->contains(fn (PlaceFactObservation $row): bool => $row->provider === $spot->source
            && $row->provider_record_id === $spot->source_id);
    }

    /** @param array<string, mixed> $tags */
    private function normalizeAccess(?string $raw, array $tags): string
    {
        if ($this->stringValue($tags['access:conditional'] ?? null) !== null) {
            return 'unknown';
        }

        return match (mb_strtolower((string) $raw)) {
            'yes', 'public', 'permissive' => 'public',
            'private', 'no' => 'private',
            'customers' => 'customers',
            'members' => 'members',
            'permit' => 'permit',
            default => 'unknown',
        };
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function sourceUrl(Spot $spot): ?string
    {
        if ($spot->source !== 'osm' || ! is_string($spot->source_id) || ! preg_match('#^(node|way|relation)/[1-9][0-9]*$#D', $spot->source_id)) {
            return null;
        }

        return 'https://www.openstreetmap.org/'.$spot->source_id;
    }
}
