<?php

namespace App\Http\Controllers;

use App\Bureaucracy\RuleSourcePolicy;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\PlanSlot;
use App\Composer\WorkspaceStore;
use App\Http\Requests\Composer\WorkspaceCommandRequest;
use App\Models\Event;
use App\Models\SpotFeedback;
use App\Models\Task;
use App\Services\UserLocationService;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComposerWorkspaceController extends Controller
{
    public function show(Request $request, WorkspaceStore $store): JsonResponse
    {
        return response()->json($store->read($request->user()));
    }

    /**
     * A change that can't go in answers 422 with its reason, and carries the
     * workspace as it now is (the reply saying why is in the conversation).
     */
    public function update(WorkspaceCommandRequest $request, WorkspaceStore $store): JsonResponse
    {
        $workspace = $store->command($request->user(), $request->validated());
        $refusal = $workspace['refusal'];
        unset($workspace['refusal']);

        return $refusal === null
            ? response()->json($workspace)
            : response()->json(['message' => $refusal['message'], 'errors' => ['action' => [$refusal['message']]], 'refusal' => $refusal, 'workspace' => $workspace], 422);
    }

    public function event(Request $request, EventOccurrencePresenter $presenter): JsonResponse
    {
        $input = $request->validate(['id' => ['required', 'integer'], 'start' => ['required', 'date']]);
        $start = CarbonImmutable::parse($input['start'])->setTimezone('Europe/Berlin');
        $occurrence = Event::occurringBetween($start->startOfDay(), $start->endOfDay())
            ->first(fn ($item) => $item['event']->id === (int) $input['id'] && $item['starts_at']->equalTo($start));
        abort_unless($occurrence, 404, 'This event is no longer available.');

        return response()->json($presenter->present($occurrence['event'], $occurrence['starts_at'], $occurrence['ends_at']) + ['distance_km' => null, 'travel_min' => null]);
    }

    public function guidance(Request $request, RuleSourcePolicy $policy): JsonResponse
    {
        $input = $request->validate(['query' => ['nullable', 'string', 'max:500']]);
        $words = array_filter(preg_split('/\W+/u', mb_strtolower($input['query'] ?? '')), fn ($word) => mb_strlen($word) > 3);
        $guides = Task::query()->authoritative()->orderBy('key')->get()
            ->filter(fn ($task) => $policy->persistedErrors($task) === [])
            ->filter(fn ($task) => $words === [] || collect($words)->contains(fn ($word) => str_contains(mb_strtolower($task->title.' '.$task->key), $word)))
            ->unique('title')->take(3)->map(fn ($task) => [
                'key' => $task->key, 'title' => $task->title, 'description' => $task->description,
                'verified_at' => $task->verified_at->toDateString(), 'jurisdiction' => $task->jurisdiction,
                'sources' => $task->legal_sources,
            ])->values();

        return response()->json(['guides' => $guides]);
    }

    public function catalogue(Request $request, CandidateRepository $repository, UserLocationService $locations): JsonResponse
    {
        $input = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'saved' => ['sometimes', 'boolean']]);
        $day = CarbonImmutable::parse($input['date'], 'Europe/Berlin');
        $origin = $locations->context($request->user(), $request);
        $saved = SpotFeedback::query()->where('user_id', $request->user()->id)->where('state', 'saved')->pluck('spot_id')->map(fn ($id) => "spot:{$id}")->all();
        $pool = $input['saved'] ?? false
            ? $repository->byIds($saved, $day)
            : $repository->candidatesFor(new Constraints($day->startOfDay(), $day->endOfDay()), $origin->lat ?? 50.9375, $origin->lng ?? 6.9603);

        return response()->json(['items' => array_values(array_map(function (Candidate $candidate) use ($day, $saved): array {
            $start = $candidate->fixedStart ?? $day->setTime(10, 0);

            return (new PlanSlot($candidate, $start, $start->addMinutes($candidate->typicalDurationMin), 0))->toArray() + [
                'summary' => $candidate->description,
                'saved' => in_array($candidate->id, $saved, true),
                'available' => ! $candidate->closedToday,
            ];
        }, $pool))]);
    }
}
