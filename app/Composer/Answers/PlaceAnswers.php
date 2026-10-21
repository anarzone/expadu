<?php

namespace App\Composer\Answers;

use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Enums\LocationSource;
use App\Enums\SpotCategory;
use App\Models\User;
use App\Services\UserLocationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * "Somewhere nearby to play football" (owner's staging test, 21 Oct 2026): the
 * places listed for that activity, nearest first, as the approved design's nearby
 * answer draws them. A place qualifies as the catalogue decides for a plan: the
 * activity in its facts, known public access, no booking needed. Distances are
 * straight lines, and the starting point is said.
 */
class PlaceAnswers
{
    private const SHOWN = 5;

    /** How far the answer looks: past "nearby" when only one or two are within it. */
    private const FURTHEST_KM = 12.0;

    /** A few: with fewer than this within the distance asked, the answer looks further. */
    private const ENOUGH = 3;

    /** How the reply says each activity (PlaceCapabilities::ACTIVITIES). */
    private const DOING = [
        'soccer' => 'play football', 'basketball' => 'play basketball', 'table_tennis' => 'play table tennis',
        'tennis' => 'play tennis', 'boules' => 'play boules', 'skateboard' => 'skate', 'swimming' => 'swim',
        'volleyball' => 'play volleyball', 'badminton' => 'play badminton',
    ];

    private const NUMBERS = ['One', 'Two', 'Three', 'Four', 'Five'];

    public function __construct(private CandidateRepository $catalogue, private UserLocationService $locations) {}

    /**
     * @param  array<string, mixed>  $request  the router's places request (date, activities, radius)
     * @return array{text: string, extra: array<string, mixed>}
     */
    public function near(User $user, array $request, CarbonImmutable $now): array
    {
        $location = $this->locations->context($user, request());
        [$lat, $lng, $from] = $location->hasOrigin()
            ? [$location->lat, $location->lng, $this->from($location->source, $location->label)]
            : [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG, 'the city centre'];
        $day = CarbonImmutable::parse((string) $request['date'], 'Europe/Berlin');
        $activities = array_values((array) $request['activities']);
        $doing = self::DOING[$activities[0]] ?? 'do that';

        // The nearest few, looking past "nearby" (or the distance named) until there are a
        // few: the catalogue keeps the nearest dozen of each kind, so one search out to the
        // furthest distance finds the same nearest places as widening step by step.
        // The whole day: the question is where, not whether it's open this minute.
        $within = isset($request['radius']) ? (float) $request['radius'] : null;
        $found = collect($this->catalogue->candidatesFor(new Constraints(
            windowStart: $day->startOfDay(),
            windowEnd: $day->endOfDay(),
            activities: $activities,
            radiusKm: max($within ?? 0, self::FURTHEST_KM),
        ), $lat, $lng))
            ->filter(fn (Candidate $candidate): bool => $candidate->type === 'spot')
            ->sortBy(fn (Candidate $candidate): float => $candidate->distanceKmFromOrigin ?? INF)
            ->values();
        $inside = $within === null ? $found : $found->filter(fn (Candidate $candidate): bool => $candidate->distanceKmFromOrigin <= $within);
        $found = ($inside->count() >= self::ENOUGH ? $inside : $found)->take(self::SHOWN)->values();
        $unknown = $location->hasOrigin() ? '' : ' I don’t know where you are, so I measured from the city centre.';

        if ($found->isEmpty()) {
            return ['text' => "I don’t find a public place to {$doing} listed within ".self::km(self::FURTHEST_KM)." of {$from}.{$unknown}", 'extra' => [
                'kind' => 'places', 'title' => 'None listed nearby.',
                'suggestions' => [['label' => 'Explore places', 'href' => route('explore', [], false)]],
            ]];
        }

        $furthest = (float) $found->last()->distanceKmFromOrigin;
        $reach = $within !== null && $furthest > $within
            ? ($inside->isEmpty() ? 'None is within '.self::km($within) : ($inside->count() === 1 ? 'Only one is' : 'Only '.mb_strtolower(self::NUMBERS[$inside->count() - 1]).' are').' within '.self::km($within))
                .', so I looked further: nearest first, up to '.self::km($furthest)
            : 'Nearest first, up to '.self::km($furthest);

        return [
            'text' => "{$reach} in a straight line from {$from}. Each is listed as public, with no booking needed.{$unknown}",
            'extra' => [
                'kind' => 'places',
                // The area of an address ("Causemannstraße 109, Merkenich" → "Merkenich").
                'title' => ($found->count() === 1 ? 'One place' : self::NUMBERS[$found->count() - 1].' places')." to {$doing} near ".Str::afterLast($from, ', ').'.',
                'places' => $found->map(fn (Candidate $candidate): array => [
                    'id' => (int) substr($candidate->id, 5),
                    'name' => $candidate->name,
                    'kind' => SpotCategory::tryFrom($candidate->category)?->label() ?? ucfirst(str_replace('_', ' ', $candidate->category)),
                    'distance' => $candidate->distanceKmFromOrigin !== null ? number_format($candidate->distanceKmFromOrigin, 1).' km' : null,
                    'area' => $candidate->veedel,
                    'href' => route('explore', ['place' => (int) substr($candidate->id, 5)], false),
                ])->all(),
            ],
        ];
    }

    private static function km(float $km): string
    {
        return rtrim(rtrim(number_format($km, 1), '0'), '.').' km';
    }

    /** The starting point as the reply names it. */
    private function from(LocationSource $source, ?string $label): string
    {
        return match ($source) {
            LocationSource::Live => 'where you are',
            LocationSource::Ping => 'where you were last',
            default => $label !== null && $label !== '' && $label !== 'Your location' ? $label : 'where you are',
        };
    }
}
