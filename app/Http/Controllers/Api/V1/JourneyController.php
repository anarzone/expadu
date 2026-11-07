<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\TakeMeThereController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\JourneyPlanRequest;
use App\Http\Requests\Api\V1\JourneyStartRequest;
use App\Http\Requests\StartTripRequest;
use App\Http\Resources\Api\V1\JourneyPlanResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Getting there from the iPhone (an event's details): the web's planner (TakeMeThereController),
 * planned to be there five minutes before it starts, and I'm on my way on one of its ways. The
 * ways are kept here, so a trip starts on the server's own journey, never one the phone sends.
 */
class JourneyController extends Controller
{
    /** How long a plan's ways can be started from. */
    private const KEEP_SECONDS = 900;

    public function plan(JourneyPlanRequest $request): JourneyPlanResource
    {
        $input = $request->validated();
        $user = $request->user();
        $starts = isset($input['starts_at']) ? CarbonImmutable::parse($input['starts_at']) : null;
        // Be there five minutes early when it's still ahead; once it has begun, leave now.
        $arriveBy = $starts !== null && $starts->subMinutes(5)->isAfter(now()->addMinutes(10));

        $inner = Request::create('/api/journey/plan', 'POST', array_filter([
            'to_lat' => $input['to_lat'], 'to_lng' => $input['to_lng'], 'to_name' => $input['to_name'],
            'from_lat' => $input['from_lat'] ?? null, 'from_lng' => $input['from_lng'] ?? null,
            'from_name' => isset($input['from_lat']) ? 'Your location' : null,
            'depart_at' => $arriveBy ? $starts->subMinutes(5)->toIso8601String() : null,
            'arrive_by' => $arriveBy ? '1' : null,
            // The ways at once, as Plan a trip asks: the boarding stops' live boards (a heavy read
            // of the whole realtime feed) aren't shown here, so they aren't waited for.
            'checks_later' => '1',
        ], fn ($value) => $value !== null));
        $inner->setUserResolver(fn () => $user);
        $response = app()->call([app(TakeMeThereController::class), '__invoke'], ['request' => $inner]);
        // Where they are isn't known yet (422), or the planner is busy: its own answer, as on the web.
        if ($response->getStatusCode() !== 200) {
            throw new HttpResponseException($response);
        }
        $answer = $response->getData(true);
        $answer['journeys'] = self::pick(array_values(array_filter($answer['journeys'] ?? [], fn ($journey) => is_array($journey) && isset($journey['arrive_at']))));

        $plan = (string) Str::uuid();
        Cache::put($this->key($user->id, $plan), [
            'journeys' => $answer['journeys'] ?? [],
            'from' => $answer['from'] ?? null,
            'to' => $answer['to'] ?? null,
        ], self::KEEP_SECONDS);

        return new JourneyPlanResource(['plan' => $plan, 'answer' => $answer, 'starts_at' => $starts?->toIso8601String()]);
    }

    /** I'm on my way: the trip starts on that way. A trip already running asks first (409). */
    public function start(JourneyStartRequest $request): JsonResponse
    {
        $input = $request->validated();
        $kept = Cache::get($this->key($request->user()->id, $input['plan']));
        $journey = is_array($kept) ? ($kept['journeys'][$input['way']] ?? null) : null;
        if (! is_array($journey)) {
            throw ValidationException::withMessages(['way' => 'That way isn’t there any more. Plan again.']);
        }

        $start = StartTripRequest::createFrom($request);
        $start->replace([
            'journey' => $journey,
            'destination' => ['name' => mb_substr((string) ($kept['to']['name'] ?? ''), 0, 200), 'lat' => $kept['to']['lat'], 'lng' => $kept['to']['lng']],
            'origin' => isset($kept['from']['lat'], $kept['from']['lng'])
                ? ['name' => $kept['from']['name'] ?? null, 'lat' => $kept['from']['lat'], 'lng' => $kept['from']['lng']]
                : null,
            'replace' => (bool) ($input['replace'] ?? false),
        ]);
        $start->setContainer(app())->setRedirector(app(Redirector::class));
        $start->validateResolved();

        return app(TripController::class)->start($start);
    }

    /**
     * Six ways in the planner's order, keeping the walk and the bike among them: a walk or a ride
     * by bike is always worth seeing next to the trams, which otherwise fill every place.
     * The kept list and the app's keep the same order.
     *
     * @param  list<array<string, mixed>>  $journeys
     * @return list<array<string, mixed>>
     */
    private static function pick(array $journeys, int $count = 6): array
    {
        $own = array_values(array_filter($journeys, fn (array $journey) => in_array($journey['mode'] ?? null, ['walk', 'bike'], true)));
        $rides = array_values(array_filter($journeys, fn (array $journey) => ! in_array($journey['mode'] ?? null, ['walk', 'bike'], true)));
        $keep = [...array_slice($rides, 0, max(0, $count - count($own))), ...$own];

        return array_values(array_filter($journeys, fn (array $journey) => in_array($journey, $keep, true)));
    }

    private function key(int $user, string $plan): string
    {
        return "app-journey-plan:{$user}:{$plan}";
    }
}
