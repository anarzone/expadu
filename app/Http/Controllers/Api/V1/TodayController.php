<?php

namespace App\Http\Controllers\Api\V1;

use App\Day\DayProjection;
use App\Home\ComingUp;
use App\Home\FirstVisit;
use App\Home\TodayContinuation;
use App\Home\TodayDiscovery;
use App\Home\TodayNeeds;
use App\Home\TodayPaperwork;
use App\Home\TodaySaved;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TodayResource;
use App\Models\EventBookmark;
use App\Personalisation\TodayRankPause;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    /**
     * Today for the iPhone: the same day, notes, ideas, Paperwork, saved things and
     * what's coming up that the web's Today reads (HomeFeedController), in one answer
     * shaped for the app.
     */
    public function show(Request $request, DayProjection $day, TodayNeeds $needs, TodayDiscovery $discovery, ComingUp $comingUp, TodayPaperwork $paperwork, TodaySaved $saved, TodayContinuation $continuation, WeatherService $weather, FirstVisit $firstVisit, TodayRankPause $rankPause): TodayResource
    {
        // Where the phone is, so the Leave card can say when to go: the trip starts there.
        $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $user = $request->user();

        return new TodayResource([
            'day' => $day->for($user, CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d'), $request),
            'needs' => $needs->for($user),
            'discovery' => $discovery->for($user, shown: true),
            'coming_up' => $comingUp->for($user),
            'paperwork' => $paperwork->for($user),
            'saved' => $saved->for($user),
            'continuation' => $continuation->for($user),
            'weather' => $weather->getCurrentWeather(),
            'forecast' => $weather->getForecast(),
            // Today and the five days after, hour by hour, for the weather sheet (one cached fetch).
            'weather_days' => array_map(fn (int $ahead) => $weather->getDayForecast(CarbonImmutable::now(DayProjection::TIMEZONE)->addDays($ahead)->format('Y-m-d')), range(0, 5)),
            // A first visit leads with Paperwork's way in (the web's priority.ts).
            'welcome' => $firstVisit->for($user),
            // Personalised suggestions on, and whether their order waits for a yes to a changed notice.
            'personalised' => $user->personalises(),
            'rank_paused' => $rankPause->onToday($user),
            // Which listings they've saved, so each idea's bookmark shows it.
            'saved_events' => EventBookmark::query()->where('user_id', $user->id)->get(['event_id', 'occurrence_start'])
                ->map(fn (EventBookmark $bookmark) => $bookmark->event_id.':'.$bookmark->occurrence_start->getTimestamp())->all(),
        ]);
    }
}
