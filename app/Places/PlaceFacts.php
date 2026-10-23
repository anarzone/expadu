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
use Illuminate\Support\Facades\DB;

class PlaceFacts
{
    public const SNAPSHOT_RELATION = 'placeFactsSnapshot';

    public const ACTIVITY_REVIEW_FIELDS = ['name', 'category', 'lat', 'lng', 'source', 'source_id', 'tags', 'parent_spot_id', 'destination_spot_id', 'destination_reviewed_parent_id', 'is_active', 'is_recommendable'];

    private const RESTRICTED_ACCESS = ['private', 'no', 'customers', 'members', 'permit'];

    /**
     * Keep explicitly restricted places out of public recommendation pools.
     * Unknown access remains visible, but is represented as unknown to callers.
     *
     * Reads the stored spots.public_access_allowed flag, which
     * refreshPublicAccess() keeps exact on every observation, correction or
     * source change. Recomputing the rule here scanned every observation in
     * every place query.
     *
     * @param  Builder<Spot>|QueryBuilder  $query
     */
    public function publiclyRecommendable(Builder|QueryBuilder $query, string $table = 'spots'): Builder|QueryBuilder
    {
        if (! in_array($table, ['spots', 'destination'], true)) {
            throw new \InvalidArgumentException('Unsupported place access-policy table alias.');
        }

        return $query->where("{$table}.public_access_allowed", true);
    }

    /**
     * Recompute the stored access flag for these places with the per-place
     * rule. Runs in the caller's transaction, so the flag changes with the
     * facts that changed it.
     *
     * @param  iterable<int|string|null>  $spotIds
     */
    public function refreshPublicAccess(iterable $spotIds): void
    {
        $ids = [];
        foreach ($spotIds as $id) {
            if ($id !== null && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return;
        }

        DB::update(
            'UPDATE spots AS destination SET public_access_allowed = '.$this->destinationAccessPolicy()
            .' WHERE destination.id = ANY(?::bigint[])',
            ['{'.implode(',', array_keys($ids)).'}'],
        );
    }

    /**
     * Recompute every place's access flag with the set-based rule, writing
     * only the rows that change. For bulk loads that bypass model events, and
     * as a safety net after catalogue refreshes.
     *
     * @return int places whose flag changed
     */
    public function refreshAllPublicAccess(): int
    {
        return DB::update('WITH allowed AS MATERIALIZED ('.$this->catalogueAccessPolicy().')
            UPDATE spots SET public_access_allowed = flags.allowed
            FROM (SELECT candidate.id, candidate.id IN (SELECT id FROM allowed) AS allowed FROM spots AS candidate) AS flags
            WHERE spots.id = flags.id AND spots.public_access_allowed IS DISTINCT FROM flags.allowed');
    }

    /**
     * The access rule over the whole catalogue: the ids of every place a
     * public recommendation may show. Equivalent to destinationAccessPolicy()
     * per place; resolves access once instead of repeating the history scan
     * per place.
     */
    private function catalogueAccessPolicy(): string
    {
        return str_replace('__CURRENT_OBSERVATIONS__', PlaceObservationHistory::currentSql(), <<<'SQL'
            WITH __CURRENT_OBSERVATIONS__, observation_access AS MATERIALIZED (
                SELECT observations.spot_id,
                    bool_or(jsonb_exists(payload, 'access')) AS has_access,
                    max(observed_at) FILTER (
                        WHERE jsonb_exists(payload, 'access') AND (
                            lower(COALESCE(payload #>> '{access,raw}', payload->>'access', '')) IN ('private', 'no', 'customers', 'members', 'permit')
                            OR nullif(trim(COALESCE(payload #>> '{access,conditional}', '')), '') IS NOT NULL
                        )
                    ) AS restricted_observed_at,
                    bool_or(provider = source_spot.source AND provider_record_id = source_spot.source_id) AS has_source_record
                FROM current_observations AS observations
                JOIN spots AS source_spot ON source_spot.id = observations.spot_id
                GROUP BY observations.spot_id
            ), active_corrections AS MATERIALIZED (
                SELECT spot_id, count(*) AS review_count, max(reviewed_at) AS reviewed_at,
                    bool_or(lower(COALESCE(value->>'value', 'unknown')) IN ('private', 'no', 'customers', 'members', 'permit')) AS is_restricted
                FROM place_fact_corrections
                WHERE field = 'access' AND revoked_at IS NULL
                GROUP BY spot_id
            )
            SELECT access_spot.id
            FROM spots AS access_spot
            LEFT JOIN observation_access ON observation_access.spot_id = access_spot.id
            LEFT JOIN active_corrections ON active_corrections.spot_id = access_spot.id
            WHERE CASE
                WHEN active_corrections.review_count > 1 THEN false
                WHEN active_corrections.review_count = 1 THEN
                    NOT active_corrections.is_restricted
                    AND (observation_access.restricted_observed_at IS NULL
                        OR observation_access.restricted_observed_at <= active_corrections.reviewed_at)
                WHEN COALESCE(observation_access.has_access, false) THEN observation_access.restricted_observed_at IS NULL
                WHEN COALESCE(observation_access.has_source_record, false) THEN true
                ELSE LOWER(COALESCE(access_spot.tags->>'access', '')) NOT IN ('private', 'no', 'customers', 'members', 'permit')
                    AND NOT COALESCE(jsonb_exists(access_spot.tags::jsonb, 'access:conditional'), false)
            END
            SQL);
    }

    /** An explicit, current review can qualify an otherwise hidden facility for activity search. */
    public function activityQualified(Builder $query): Builder
    {
        // Evidence decides (owner, 21 Oct 2026; no manual review): OSM names a
        // sport, public access is allowed, and the tags ask for no private
        // access, membership or booking. A reviewed approval still counts.
        $query->where(fn (Builder $either) => $either->where(fn (Builder $evidence) => $evidence
            ->where('spots.public_access_allowed', true)
            ->whereRaw("coalesce(spots.tags::jsonb->>'sport', '') <> ''")
            ->whereRaw("coalesce(lower(spots.tags::jsonb->>'access'), 'yes') not in ('private', 'no', 'customers', 'members', 'permit')")
            ->whereRaw("coalesce(lower(spots.tags::jsonb->>'membership'), 'no') not in ('required', 'yes')")
            ->whereRaw("coalesce(lower(spots.tags::jsonb->>'reservation'), 'no') not in ('required', 'only')")
            // A reviewer's current access finding outranks the OSM tag.
            ->whereNotExists(fn ($review) => $review->selectRaw('1')->from('place_fact_corrections as access_review')
                ->whereColumn('access_review.spot_id', 'spots.id')->where('access_review.field', 'access')
                ->whereNull('access_review.revoked_at')
                ->whereRaw("coalesce(access_review.value->>'value', '') not in ('public', 'permissive')")))
            ->orWhere(fn (Builder $reviewed) => $this->reviewedActivity($reviewed)));

        return $query;
    }

    /** An approved activity_discovery review that still matches the place's current facts. */
    private function reviewedActivity(Builder $query): Builder
    {
        $basis = 'jsonb_build_object('.implode(', ', array_map(fn (string $field): string => "'{$field}', spots.{$field}", self::ACTIVITY_REVIEW_FIELDS)).')';

        return $query->whereRaw(str_replace('__BASIS__', $basis, <<<'SQL'
            (SELECT count(*) = 1 AND bool_and(value->>'value' = 'true'
                AND value->'basis' = __BASIS__
                AND (value->>'observation_id')::bigint = COALESCE((SELECT max(o.id) FROM place_fact_observations o
                    WHERE o.spot_id = spots.id), 0)
                AND (value->>'access_correction_id')::bigint = COALESCE((SELECT max(a.id)
                    FROM place_fact_corrections a WHERE a.spot_id = spots.id AND a.field = 'access'), 0))
             FROM place_fact_corrections c
             WHERE c.spot_id = spots.id AND c.field = 'activity_discovery' AND c.revoked_at IS NULL)
            SQL));
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
        $observations = PlaceObservationHistory::active($spot->factObservations);
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
        $fee = $this->resolveFee($tags, $sourceUrl, $effectiveObservations, $corrections->get('fee', collect()), $useLegacyProjection, ! $observations->contains(fn (PlaceFactObservation $row): bool => $row->provider === $spot->source && $row->provider_record_id === $spot->source_id && array_key_exists('conditional', $row->payload['fee'] ?? [])));
        $hours = $this->resolveHours($spot, $tags, $sourceUrl, $effectiveObservations, $corrections->get('hours', collect()), $useLegacyProjection);
        $contact = $this->resolveContact($spot, $sourceUrl, $effectiveObservations, $corrections->get('contact', collect()), $useLegacyProjection);
        $description = $this->resolveDescription($spot, $sourceUrl, $effectiveObservations, $corrections->get('description', collect()), $useLegacyProjection);
        $negativeFacts = $this->resolveNegativeFacts($effectiveObservations);
        $practical = app(PlaceCapabilities::class)->resolve($spot, $effectiveObservations, $sourceUrl);

        return [
            'identity' => ['canonical_id' => $spot->id, 'provider' => $spot->source, 'provider_record_id' => $spot->source_id],
            'name' => $name['fact'],
            'name_kind' => $name['kind'],
            // What people see: generic facility labels gain their park, street or Veedel.
            'display_name' => PlaceDisplayName::for(
                $name['fact']['value'] ?? $spot->name, $spot->park_name, $contact['address']['value'] ?? $spot->address, $spot->veedel,
                $spot->category instanceof SpotCategory ? $spot->category->value : (string) $spot->category,
                $practical['sport']['value'] ?? null, $spot->lat !== null ? (float) $spot->lat : null, $spot->lng !== null ? (float) $spot->lng : null,
            ),
            'generic_name' => PlaceDisplayName::isGeneric($name['fact']['value'] ?? $spot->name),
            'aliases' => $name['aliases'],
            'location' => $location,
            'access' => $access['fact'],
            'fee' => $fee,
            'hours' => $hours,
            'contact' => $contact,
            'description' => $description,
            'negative_facts' => $negativeFacts,
            'practical' => $practical,
            // A playground with goals or a ball wall is a children's play area, not where adults
            // go to play football: its equipment stays in practical, but it answers no activity ask.
            'activities' => ($spot->category instanceof SpotCategory ? $spot->category->value : (string) $spot->category) === 'playground'
                ? []
                : PlaceCapabilities::activities($practical['sport']['value']),
            'conflicts' => array_values(array_filter([
                $access['conflicting'] ? 'access' : null,
                $fee['status'] === 'conflicting' ? 'fee' : null,
                ...collect($practical)->filter(fn (array $fact): bool => $fact['status'] === 'conflicting')->keys()->map(fn (string $key): string => 'practical.'.$key)->all(),
            ])),
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
        // The place's own source names it; another provider (the city's register) only names
        // what its own source calls by a generic label ("Spielplatz"), so refreshes don't flip it.
        $own = $effectiveObservations->filter(fn (PlaceFactObservation $row): bool => array_key_exists('name', $row->payload)
            && $row->provider === $spot->source && $row->provider_record_id === $spot->source_id)
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id])->last();
        if ($own !== null) {
            $ownName = $this->stringValue($own->payload['name'] ?? null);
            $named = $effectiveObservations->filter(fn (PlaceFactObservation $row): bool => $row !== $own
                && ($name = $this->stringValue($row->payload['name'] ?? null)) !== null && ! $this->isDescriptiveName($name))
                ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id])->last();
            [$latestSource, $sourceValue] = $ownName !== null && ! $this->isDescriptiveName($ownName)
                ? [$own, $ownName]
                : ($named !== null ? [$named, $this->stringValue($named->payload['name'])] : [$own, $ownName]);
        }
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
    private function resolveFee(array $tags, ?string $legacySourceUrl, Collection $observations, Collection $corrections, bool $useLegacyProjection, bool $legacyConditions): array
    {
        $rows = $observations->filter(fn (PlaceFactObservation $row): bool => array_key_exists('fee', $row->payload))
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at->getTimestamp(), $row->id]);
        $sources = $rows->map(function (PlaceFactObservation $row) use ($tags, $legacySourceUrl, $legacyConditions): array {
            $source = $row->payload['fee'];
            if ($legacyConditions && $legacySourceUrl !== null && $row->source_url === $legacySourceUrl) {
                $source += ['conditional' => $tags['fee:conditional'] ?? null, 'charge' => $tags['charge'] ?? null, 'charge_conditional' => $tags['charge:conditional'] ?? null];
            }

            return [...$source, 'source_url' => $row->source_url, 'observed_at' => $row->observed_at];
        });
        if ($sources->isEmpty()) {
            $sources = collect([[
                'raw' => $useLegacyProjection ? ($tags['fee'] ?? null) : null,
                'conditional' => $useLegacyProjection ? ($tags['fee:conditional'] ?? null) : null,
                'charge' => $useLegacyProjection ? ($tags['charge'] ?? null) : null,
                'charge_conditional' => $useLegacyProjection ? ($tags['charge:conditional'] ?? null) : null,
                'source_url' => $useLegacyProjection ? $legacySourceUrl : null, 'observed_at' => null,
            ]]);
        }
        $normalize = static fn (array $source): string => match (mb_strtolower(trim((string) ($source['raw'] ?? '')))) {
            'no', 'free' => 'free', 'yes', 'paid' => 'paid', default => 'unknown',
        };
        $correction = $corrections->last();
        $effective = $correction === null ? $sources : $sources->filter(fn (array $source): bool => $source['observed_at']?->greaterThan($correction->reviewed_at) ?? false);
        $known = $effective->map($normalize)->reject(fn (string $value): bool => $value === 'unknown');
        if ($correction !== null && ($correction->value['value'] ?? 'unknown') !== 'unknown') {
            $known->push($correction->value['value']);
        }
        $conditional = $effective->pluck('conditional')->map($this->stringValue(...))->filter()->first();
        $chargeConditional = $effective->pluck('charge_conditional')->map($this->stringValue(...))->filter()->first();
        $charge = $effective->pluck('charge')->map($this->stringValue(...))->filter()->first();
        $hasCharge = $effective->contains(fn (array $source): bool => (is_numeric($source['amount'] ?? null) && (float) $source['amount'] > 0)
            || ($this->stringValue($source['charge'] ?? null) !== null && preg_match('/^0(?:[.,]0+)?(?:\s+[A-Z]{3})?$/iD', trim($source['charge'])) !== 1));
        $conflicting = $known->unique()->count() > 1 || $corrections->count() > 1 || ($known->contains('free') && $hasCharge);
        $source = $sources->last();
        $value = $correction?->value['value'] ?? $normalize($source);
        $status = $conflicting ? 'conflicting' : (($conditional !== null || $chargeConditional !== null) ? 'conditional' : ($value === 'unknown' ? 'unknown' : 'known'));

        return [
            ...$this->fact($status === 'known' ? $value : 'unknown', $status, $correction?->evidence_url ?? $source['source_url']),
            'raw' => $this->stringValue($source['raw'] ?? null),
            'conditional' => $conditional, 'charge' => $charge, 'charge_conditional' => $chargeConditional,
            'amount' => $correction?->value['amount'] ?? (is_numeric($source['amount'] ?? null) ? (float) $source['amount'] : null),
            'currency' => $correction?->value['currency'] ?? $this->stringValue($source['currency'] ?? null),
            'observed_at' => $source['observed_at']?->toIso8601String(),
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
        // Field by field: the place's own source (OSM) decides when it lists a value;
        // another provider (Overture, a website) only fills a gap. A real disagreement
        // (another website domain, another number) stays conflicting, never swapped.
        $withContact = $observations->filter(fn (PlaceFactObservation $row): bool => is_array($row->payload['contact'] ?? null))
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id]);
        $isOwn = fn (PlaceFactObservation $row): bool => $spot->source === null || ($row->provider === $spot->source && $row->provider_record_id === $spot->source_id);
        $latestByProvider = $withContact->reject($isOwn)
            ->groupBy(fn (PlaceFactObservation $row): string => $row->provider.'|'.$row->provider_record_id)
            ->map(fn (Collection $rows): PlaceFactObservation => $rows->last());
        $correction = $corrections->last();

        return collect(['website', 'phone', 'address'])->mapWithKeys(function (string $field) use ($spot, $isOwn, $withContact, $latestByProvider, $useLegacyProjection, $legacySourceUrl, $correction, $corrections): array {
            // The latest own record that states this field (null = removed); one silent on it doesn't erase it.
            $primary = $withContact->filter(fn (PlaceFactObservation $row): bool => $isOwn($row) && array_key_exists($field, $row->payload['contact']))->last();
            $own = $primary !== null ? $this->stringValue($primary->payload['contact'][$field] ?? null) : null;
            if ($primary === null && $useLegacyProjection) {
                $own = $this->stringValue($spot->{$field});
            }
            $others = $latestByProvider->map(fn (PlaceFactObservation $row): array => [$row, $this->stringValue($row->payload['contact'][$field] ?? null)])
                ->filter(fn (array $pair): bool => $pair[1] !== null)->values();
            [$row, $value] = $own !== null ? [$primary, $own] : ($others->last() ?? [$primary, null]);
            $differs = fn (string $a, string $b): bool => $this->contactKey($field, $a) !== $this->contactKey($field, $b);
            // OSM's own value stays shown; another provider saying otherwise is noted, not
            // allowed to hide it (Overture is often the stale side). Without an own value,
            // two other providers disagreeing leave it conflicting.
            $disputedBy = $value !== null ? $others->filter(fn (array $pair): bool => $differs($pair[1], $value))->map(fn (array $pair): string => $pair[0]->provider)->unique()->values()->all() : [];
            $conflicting = $own === null && $disputedBy !== [];

            if ($correction !== null && array_key_exists($field, $correction->value)) {
                [$value, $conflicting] = [$this->stringValue($correction->value[$field]), false];
            }
            $status = $corrections->count() > 1 || $conflicting ? 'conflicting' : ($value !== null ? 'known' : 'unknown');

            return [$field => [
                ...$this->fact($value, $status, $correction?->evidence_url ?? ($row?->source_url ?? ($value !== null ? $legacySourceUrl : null))),
                'observed_at' => $row?->observed_at?->toIso8601String(),
                'reviewed_at' => $correction?->reviewed_at?->toIso8601String(),
                ...($disputedBy !== [] && $correction === null ? ['disputed_by' => $disputedBy] : []),
            ]];
        })->all();
    }

    /** What makes two contact values the same: a website's host, a number's digits, an address's letters. */
    private function contactKey(string $field, string $value): string
    {
        return match ($field) {
            // The registrable domain: shop.rewe.de and www.rewe.de are the same business.
            'website' => implode('.', array_slice(explode('.', mb_strtolower((string) (parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST) ?? $value))), -2)),
            'phone' => substr((string) preg_replace('/^(?:00)?49|^0/', '', (string) preg_replace('/\D+/', '', $value)), -9),
            default => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($value)),
        };
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

    /**
     * The access rule for one place (aliased `destination`): the per-place
     * twin of catalogueAccessPolicy(), used to refresh a place's stored flag.
     */
    private function destinationAccessPolicy(): string
    {
        return str_replace(['spots.', '__CURRENT_OBSERVATIONS__'], ['destination.', PlaceObservationHistory::currentSql('destination.id')], <<<'SQL'
            (
                WITH __CURRENT_OBSERVATIONS__, active_corrections AS MATERIALIZED (
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
            SQL);
    }
}
