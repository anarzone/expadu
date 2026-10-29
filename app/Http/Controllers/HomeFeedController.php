<?php

namespace App\Http\Controllers;

use App\Day\DayProjection;
use App\Enums\LocationSource;
use App\Home\ComingUp;
use App\Home\FirstVisit;
use App\Home\TodayContinuation;
use App\Home\TodayDiscovery;
use App\Home\TodayNeeds;
use App\Home\TodayPaperwork;
use App\Home\TodaySaved;
use App\Home\WhatsOn;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Jobs\WarmStation;
use App\Models\FavouriteStop;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\TodayTaste;
use App\Services\KvbApiService;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use App\Transit\NearbyStation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, UserLocationService $locations, NearbyStation $nearby, KvbApiService $kvb): Response
    {
        $userId = $request->user()->id;
        // Check departures' stop, when one was picked.
        $stop = trim((string) $request->query('stop', ''));
        $stop = $stop === '' ? null : mb_substr($stop, 0, 120);

        // Today loads whole, in the first response (owner, 26 Oct 2026: pages
        // load whole, never in steps). Every prop is a plain closure: a partial
        // reload (the station's 30 s poll, the day's refresh) reads only its own.
        // Check departures, read once for both props below.
        $read = null;
        // The day, read once: its prop and the board's plan stop (planStop) both use it.
        $dayRead = null;
        $day = function () use (&$dayRead, $request): array {
            return $dayRead ??= app(DayProjection::class)->for($request->user(), CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d'), $request);
        };
        $board = function () use (&$read, $request, $locations, $nearby, $kvb, $stop, $day): array {
            return $read ??= $this->board($request, $locations, $nearby, $kvb, $stop, $day);
        };

        return Inertia::render('today', [
            // The person's day: plans, the trips between them and when to
            // leave for the next (DayProjection).
            'day' => fn () => $day(),
            // Check departures: Travel's station, the picked one, else at the
            // anchor (stationAnchor); null with neither, or while the anchor's
            // station is being read for the first time (stationWarming).
            'station' => fn () => $board()['station'],
            // The stop whose board is being read after this response: the
            // board shows its name and the next poll fills it.
            'stationWarming' => fn () => $board()['warming'],
            // Why it's this stop, in a few words ("For Tandem Language Café", "Near Home"); null when picked.
            'stationReason' => fn () => $board()['reason'],
            'stop' => $stop,
            // The deadline strip and the Paperwork panel: the reviewed v2
            // plan's urgent date and next step (TodayPaperwork).
            'paperwork' => fn () => app(TodayPaperwork::class)->for($request->user()),
            // Needs you: what threatens the day and what can't wait, as push and the brief say it (TodayNeeds).
            // 'needsYou', not 'needs': that name is the shared step-free need the account menu reads.
            'needsYou' => fn () => app(TodayNeeds::class)->for($request->user()),
            // Discovery: tonight's events that fit around the plans, and the
            // everyday places open near the next plan (TodayDiscovery).
            // Its lists are logged as shown, for people who opted in (ListImpressions).
            'discovery' => fn () => app(TodayDiscovery::class)->for($request->user(), shown: true),
            // Coming up: the next plans after today (ComingUp), with the day.
            'comingUp' => fn () => app(ComingUp::class)->for($request->user()),
            // Saved for later (the one Saved collection) and the Composer
            // conversation to pick up, both the person's own.
            'saved' => fn () => app(TodaySaved::class)->for($request->user()),
            'continuation' => fn () => app(TodayContinuation::class)->for($request->user()),
            // Someone new: nothing planned, saved, asked or recorded yet.
            'welcome' => fn () => app(FirstVisit::class)->for($request->user()),
            'favouriteStops' => fn () => FavouriteStopController::forUser($userId, $kvb),
            // The stops they pick most (TodayTaste, opt-in only; [] otherwise): quick picks after favourites.
            'learnedStops' => fn () => app(TodayTaste::class)->stops($request->user(), 5),
            // What's on: the day's listings in the panel over Today, read only while it's open.
            'whatsOn' => fn () => $request->boolean('whats_on') ? app(WhatsOn::class)->for($request) : null,
        ]);
    }

    /**
     * Where the departures read from without a picked stop, and why (owner, 28 Oct 2026: the
     * board stays put; what it shows follows the day): the stop the next plan's trip boards at,
     * when it leaves within two hours; else where the person is (a fresh or last fix); else their
     * first favourite stop; else the stop they pick most (TodayTaste, opt-in); else near Home;
     * else near their Veedel's centre. Only the person's own fix gets a walk time
     * (NearbyStation::closestTo). Null when none is known.
     *
     * @param  Closure(): array<string, mixed>  $day
     * @return array{0: string|LocationContext, 1: string}|null
     */
    private function stationAnchor(Request $request, UserLocationService $locations, Closure $day): ?array
    {
        $user = $request->user();
        $plan = $this->planStop($day());
        if ($plan !== null) {
            return $plan;
        }
        $here = $locations->context($user, $request);
        if ($here->hasOrigin()) {
            return [$here, 'Near you'];
        }
        $favourite = FavouriteStop::query()->where('user_id', $user->id)->orderBy('id')->value('name');
        if ($favourite !== null) {
            return [(string) $favourite, 'Your favourite stop'];
        }
        $often = app(TodayTaste::class)->stops($user, 1)[0] ?? null;
        if (is_string($often) && $often !== '') {
            return [$often, 'You pick it often'];
        }
        $home = $user->places()->located()->where('category', 'home')->orderBy('sort_order')->first(['lat', 'lng']);
        if ($home !== null) {
            return [new LocationContext((float) $home->lat, (float) $home->lng, LocationSource::Place, 'Home'), 'Near Home'];
        }
        $area = $locations->context($user, null, $user->veedel);

        return $area->hasOrigin() ? [$area, 'Near '.$user->veedel] : null;
    }

    /**
     * The stop the next plan's trip boards at (its first ride), when it leaves within two hours,
     * by the timetable's own name ("Köln Neumarkt" → "Neumarkt").
     *
     * @param  array<string, mixed>  $day
     * @return array{0: string, 1: string}|null
     */
    private function planStop(array $day): ?array
    {
        $trip = $day['next_trip'] ?? null;
        $leaveIn = $trip['leave_in_min'] ?? null;
        if (! is_array($trip) || ! is_int($leaveIn) || $leaveIn > 120) {
            return null;
        }
        $ride = collect($trip['journey']['legs'] ?? [])->first(fn ($leg) => is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true) && filled($leg['from']['name'] ?? null));
        if ($ride === null) {
            return null;
        }
        $city = (string) ($trip['city'] ?? '');
        $name = trim($city !== '' ? (string) preg_replace('/^'.preg_quote($city, '/').'[\s,]+/u', '', $ride['from']['name']) : $ride['from']['name']);
        $title = (string) ($trip['target']['title'] ?? '');

        return $name === '' ? null : [$name, $title !== '' ? 'For '.$title : 'For your next plan'];
    }

    /** @return array<string, mixed>|null */
    /**
     * Check departures without waiting on a cold board: a picked stop reads
     * at once (named(), a cache read once kept); the anchor's station is
     * read only when Travel already keeps it, and otherwise it's read off the
     * request (WarmStation, queued) and the page looks again for it.
     *
     * @param  Closure(): array<string, mixed>  $day
     * @return array{station: array<string, mixed>|null, warming: string|null, reason: string|null}
     */
    private function board(Request $request, UserLocationService $locations, NearbyStation $nearby, KvbApiService $kvb, ?string $stop, Closure $day): array
    {
        $picked = $stop === null ? null : $nearby->named($stop);
        if ($picked !== null) {
            // A station they picked, for personalisation with their opt-in; never the anchor's.
            app(SignalRecorder::class)->transit($request->user(), SignalItemType::Stop, $stop);

            return ['station' => $picked, 'warming' => null, 'reason' => null];
        }

        [$anchor, $reason] = $this->stationAnchor($request, $locations, $day) ?? [null, null];
        $name = match (true) {
            $anchor === null => null,
            is_string($anchor) => $anchor,
            default => $anchor->hasOrigin() ? ($kvb->nearestStop((float) $anchor->lat, (float) $anchor->lng)['name'] ?? null) : null,
        };
        if ($name === null) {
            return ['station' => null, 'warming' => null, 'reason' => null];
        }
        if ($nearby->kept((string) $name) === null) {
            $warm = (string) $name;
            WarmStation::dispatch($warm);

            return ['station' => null, 'warming' => $warm, 'reason' => $reason];
        }

        return ['station' => $this->station($anchor, $nearby), 'warming' => null, 'reason' => $reason];
    }

    private function station(string|LocationContext|null $anchor, NearbyStation $nearby): ?array
    {
        return match (true) {
            $anchor === null => null,
            is_string($anchor) => $nearby->named($anchor),
            default => $nearby->closestTo($anchor),
        };
    }
}
