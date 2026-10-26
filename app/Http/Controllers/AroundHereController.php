<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Enums\SpotFeedbackState;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Places\AroundKinds;
use App\Places\OpenNearby;
use App\Support\EventStartingPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Around here (AH1, prototype/dev/design/around-here/): places of one kind
 * near a point, as a list beside a map, read at a time's listed hours. Opened
 * from Travel ("Places nearby"), Today and Composer; not a navigation page.
 *
 * Where (`near`):
 *  - a plan or an appointment on the person's own day today, by its id
 *    (`plan:<the plan's id>`, or an appointment's own `appointment:…`),
 *    resolved here so its place never sits in a URL;
 *  - a public point another page names, `<lat>,<lng>` with `name` (a stop
 *    on a way);
 *  - a district, stop or venue by its name, or `home` / `work`
 *    (EventStartingPoint);
 *  - with no `near`, the place the link opens (`place`, a place's id: a
 *    Composer card), so the page shows what's near it behind its sheet.
 * 🔒 Bare coordinates only ever name a public point. The person's own
 * position never goes in a URL: their saved places go by their keys and
 * their plans by their ids, resolved here, and a live position would need
 * a private path (a POST, or `near=here` read from what the page already
 * sends privately), never `near=<lat>,<lng>`.
 * When (`time`, HH:MM): today's listed hours at that time, now by default.
 *
 * 🔒 The point and the time are inputs only: nothing here stores, caches or
 * logs them (they can say where someone will be).
 */
class AroundHereController extends Controller
{
    /** At most this many places within 2 km, for the count and Show more. */
    private const WITHIN_LIMIT = 400;

    /** Rows the page shows before Show more. */
    private const FIRST = 12;

    /** Close by; a thin area adds up to three further away. */
    private const CLOSE_KM = 2.0;

    public function __invoke(Request $request, EventStartingPoint $points, OpenNearby $nearby): Response
    {
        $user = $request->user();
        $now = CarbonImmutable::now('Europe/Berlin');
        $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $request->query('time')) === 1
            ? (string) $request->query('time')
            : $now->format('H:i');
        $at = CarbonImmutable::parse($now->toDateString().' '.$time, 'Europe/Berlin');
        // Kinds combine ("green,sports"); none is every kind.
        $kinds = collect(explode(',', (string) $request->query('kind')))
            ->map(fn (string $k): string => trim($k))
            ->filter(fn (string $k): bool => AroundKinds::has($k))
            ->unique()->values()->all();
        $open = $request->query('open') === '1';
        $point = $this->point((string) $request->query('near'), (string) $request->query('name'), $user, $points, $now)
            ?? ($request->query('near', '') === '' ? $this->placePoint((string) $request->query('place')) : null);

        $list = $point === null ? [] : $this->places($nearby, $point, $at, $kinds, $open, $user);

        return Inertia::render('around-here', [
            'point' => $point,
            'kind' => implode(',', $kinds),
            'open' => $open,
            'at' => ['date' => $at->toDateString(), 'time' => $time],
            'kinds' => AroundKinds::chips(),
            // The first twelve, or all of them once asked (`more=1`), with
            // the real counts.
            'places' => $request->query('more') === '1' ? $list : array_slice($list, 0, self::FIRST),
            'counts' => [
                'close' => count(array_filter($list, fn (array $row): bool => $row['km'] <= self::CLOSE_KM)),
                'all' => count($list),
            ],
        ]);
    }

    /**
     * The point the URL names, or null when it names none this person can
     * see: another person's plan, a typed address, nothing.
     *
     * @return array{name: string, lat: float, lng: float}|null
     */
    private function point(string $near, string $name, User $user, EventStartingPoint $points, CarbonImmutable $now): ?array
    {
        if (str_starts_with($near, 'plan:') || str_starts_with($near, 'appointment:')) {
            $id = str_starts_with($near, 'plan:') ? substr($near, 5) : $near;
            $item = collect(app(DayProjection::class)->for($user, $now->toDateString(), null, $now)['items'] ?? [])
                ->first(fn (array $item): bool => ($item['id'] ?? null) === $id && ($item['location']['routable'] ?? false));

            return $item === null ? null : [
                'name' => (string) ($item['location']['label'] ?? $item['title']),
                'lat' => (float) $item['location']['lat'],
                'lng' => (float) $item['location']['lng'],
            ];
        }

        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?),(-?\d{1,3}(?:\.\d+)?)$/', $near, $m) === 1) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];

            return abs($lat) <= 90 && abs($lng) <= 180
                ? ['name' => trim($name) !== '' ? mb_substr(trim($name), 0, 80) : 'this point', 'lat' => $lat, 'lng' => $lng]
                : null;
        }

        $named = $near !== '' ? $points->resolve($near, $user) : null;

        return $named === null ? null : ['name' => $named['name'], 'lat' => $named['lat'], 'lng' => $named['lng']];
    }

    /**
     * A place opened by its id, as the point to look around: a venue's own
     * name and position, public like any place's.
     *
     * @return array{name: string, lat: float, lng: float}|null
     */
    private function placePoint(string $place): ?array
    {
        if (preg_match('/^[1-9]\d{0,9}$/', $place) !== 1) {
            return null;
        }

        $spot = Spot::query()->whereKey((int) $place)->whereNotNull('lat')->whereNotNull('lng')->first(['id', 'name', 'lat', 'lng']);

        return $spot === null ? null : ['name' => (string) $spot->name, 'lat' => (float) $spot->lat, 'lng' => (float) $spot->lng];
    }

    /**
     * The places of the kinds (or of every kind) within 2 km, nearest first,
     * the ones listed as open at the time when asked; where that's fewer
     * than three, up to three of the next ones further away.
     *
     * @param  array{name: string, lat: float, lng: float}  $point
     * @param  list<string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function places(OpenNearby $nearby, array $point, CarbonImmutable $at, array $kinds, bool $open, User $user): array
    {
        $categories = AroundKinds::categories($kinds);
        $listed = fn (array $row): bool => ! $open || $row['hours']['state'] === 'open';
        // Everything within 2 km, nearest first; the page shows the first
        // twelve and the real count.
        $close = $nearby->listed($point['lat'], $point['lng'], $at, $categories, radiusKm: self::CLOSE_KM, limit: self::WITHIN_LIMIT, for: $user)
            ->filter($listed)
            ->filter(fn (array $row): bool => $row['km'] <= self::CLOSE_KM)
            ->values();
        // A thin area adds up to three further away.
        $shown = $close->count() >= 3
            ? $close
            : $close->concat($nearby->listed($point['lat'], $point['lng'], $at, $categories, limit: 12, for: $user)
                ->filter($listed)
                ->filter(fn (array $row): bool => $row['km'] > self::CLOSE_KM)
                ->take(3 - $close->count()));
        $saved = SpotFeedback::effectiveForUser($user->id)
            ->filter(fn (SpotFeedback $row): bool => $row->state === SpotFeedbackState::Saved)
            ->keys()->map(fn (mixed $id): int => (int) $id)->all();

        return $shown->map(fn (array $row): array => [
            ...$row,
            'icon' => AroundKinds::icon($row['kind']),
            'label' => AroundKinds::label($row['kind']),
            'saved' => in_array($row['id'], $saved, true),
        ])->values()->all();
    }
}
