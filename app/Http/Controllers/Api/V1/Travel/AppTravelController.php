<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Day\DayProjection;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Http\Controllers\Controller;
use App\Models\FavouriteStop;
use App\Models\JourneyRecent;
use App\Models\UserPlace;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Places\SetHomeOrWork;
use App\Services\KvbApiService;
use App\Transit\NearbyStation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Travel on the iPhone: what the web's Travel page gets as its props (TimetableController), as JSON
 * for the app's token. The station board by name or by where they are, the places a trip can go to,
 * and Home or Work set from Plan a trip.
 */
class AppTravelController extends Controller
{
    /**
     * Nearby: a station by its KVB name (the stop picker, a favourite), else the closest to the point
     * the phone sent. An unknown name keeps the closest and says so. Null when neither is known.
     */
    public function station(Request $request, NearbyStation $nearby): JsonResponse
    {
        $data = $request->validate([
            'stop' => ['nullable', 'string', 'max:120'],
            'lat' => ['nullable', 'required_without:stop', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ]);
        $user = $request->user();
        $stop = trim((string) ($data['stop'] ?? ''));
        $stop = $stop === '' ? null : $stop;

        $station = $stop === null ? null : $nearby->named($stop);
        if ($station !== null) {
            // A station they picked, for personalisation with their opt-in; never the closest one.
            app(SignalRecorder::class)->transit($user, SignalItemType::Stop, $stop);
        } elseif (isset($data['lat'], $data['lng'])) {
            $closest = $nearby->closest((float) $data['lat'], (float) $data['lng']);
            $station = $closest === null ? null : [...$closest, 'unknown_stop' => $stop];
        }

        return response()->json([
            'station' => $station,
            'favourite' => $station !== null && FavouriteStop::query()
                ->where('user_id', $user->id)->where('name', $station['name'])->exists(),
            'step_free_need' => (bool) $user->needs_step_free,
            'deutschlandticket' => (bool) $user->has_deutschlandticket,
        ]);
    }

    /**
     * Where a trip can go: their ★ stops, saved places with their points (Home and Work first),
     * recent destinations, and today's plans that have a place to route to.
     */
    public function places(Request $request, KvbApiService $kvb, DayProjection $days): JsonResponse
    {
        $user = $request->user();
        $today = CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d');
        $day = $days->for($user, $today, $request);

        return response()->json([
            'favourite_stops' => FavouriteStopController::forUser($user->id, $kvb),
            'saved_places' => $user->places()
                ->orderByRaw("case category when 'home' then 0 when 'work' then 1 else 2 end")
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'name', 'category', 'emoji', 'address', 'lat', 'lng'])
                ->map(fn (UserPlace $place) => [
                    'id' => $place->id,
                    'name' => $place->name,
                    'category' => (string) $place->category,
                    'emoji' => $place->emoji,
                    'address' => $place->address,
                    'lat' => $place->lat === null ? null : (float) $place->lat,
                    'lng' => $place->lng === null ? null : (float) $place->lng,
                ])
                ->all(),
            'recent_destinations' => JourneyRecent::query()
                ->where('user_id', $user->id)
                ->where('role', 'destination')
                ->orderByDesc('last_used_at')
                ->limit(4)
                ->get()
                ->map(fn (JourneyRecent $recent) => [
                    'name' => $recent->name,
                    'area' => $recent->area,
                    'lat' => $recent->lat === null ? null : (float) $recent->lat,
                    'lng' => $recent->lng === null ? null : (float) $recent->lng,
                ])
                ->all(),
            'today' => collect($day['items'] ?? [])
                ->filter(fn (array $item) => ($item['location']['routable'] ?? false) && ! ($item['completed'] ?? false)
                    && in_array($item['state'] ?? '', ['upcoming', 'started', 'ongoing'], true))
                ->map(fn (array $item) => [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'start_time' => $item['start_time'],
                    'start_at' => $item['start_at'],
                    'place' => $item['location']['label'],
                    'lat' => (float) $item['location']['lat'],
                    'lng' => (float) $item['location']['lng'],
                ])
                ->values()
                ->all(),
        ]);
    }

    /** Plan a trip's "Add an address" for Home or Work: the place picked, with its point. */
    public function setPlace(Request $request, string $category, SetHomeOrWork $set): JsonResponse
    {
        abort_unless(in_array($category, ['home', 'work'], true), 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $place = $set->execute($request->user(), $category, $validated);

        return response()->json(['place' => [
            'id' => $place->id,
            'name' => $place->name,
            'category' => (string) $place->category,
            'emoji' => $place->emoji,
            'address' => $place->address,
            'lat' => (float) $place->lat,
            'lng' => (float) $place->lng,
        ]]);
    }
}
