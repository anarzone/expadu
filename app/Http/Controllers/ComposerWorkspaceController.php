<?php

namespace App\Http\Controllers;

use App\Bureaucracy\RuleSourcePolicy;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\DayAround;
use App\Composer\PaperworkGlance;
use App\Composer\PlanSlot;
use App\Composer\WorkspaceStore;
use App\Day\DayProjection;
use App\Http\Requests\Composer\WorkspaceCommandRequest;
use App\Models\Event;
use App\Models\SpotFeedback;
use App\Models\Task;
use App\Places\AroundKinds;
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
     * What Composer's page carries beside the workspace, for the panel on any page:
     * Your day, what the Paperwork record has coming up, tonight's listings and
     * the person's saved starting points (the new conversation's starters, My
     * Plans' today and Starting point).
     */
    public function day(Request $request, DayProjection $day, PaperworkGlance $paperwork, DayAround $around): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'day' => $day->for($user, CarbonImmutable::now(DayProjection::TIMEZONE)->format('Y-m-d'), $request),
            'paperwork' => $paperwork->for($user),
            'around' => $around->for($user),
            'savedPlaces' => ComposerController::savedPlaces($user),
        ]);
    }

    /** Renames one of the person's own conversations (an empty title: the automatic one). */
    public function renameConversation(Request $request, WorkspaceStore $store, string $conversation): JsonResponse
    {
        $input = $request->validate(['title' => ['present', 'nullable', 'string', 'max:120']]);
        $workspace = $store->renameConversation($request->user(), $conversation, (string) ($input['title'] ?? ''));

        return $workspace === null ? response()->json(['message' => 'Conversation not found.'], 404) : response()->json($workspace);
    }

    /** Deletes one of the person's own conversations for good (the page asks first). */
    public function deleteConversation(Request $request, WorkspaceStore $store, string $conversation): JsonResponse
    {
        $workspace = $store->deleteConversation($request->user(), $conversation);

        return $workspace === null ? response()->json(['message' => 'Conversation not found.'], 404) : response()->json($workspace);
    }

    /**
     * A change that can't go in answers 422 with its reason and the workspace,
     * unchanged, so the reply can say why without a reload.
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

            $spot = $candidate->type === 'spot';

            return (new PlanSlot($candidate, $start, $start->addMinutes($candidate->typicalDurationMin), 0))->toArray() + [
                'summary' => $candidate->description,
                'saved' => in_array($candidate->id, $saved, true),
                'available' => ! $candidate->closedToday,
                // As Around here names and colours it: "Café", in its kind's group ("Cafés").
                'label' => $spot ? AroundKinds::label($candidate->category) : 'Event',
                'kind' => $spot ? AroundKinds::icon($candidate->category) : 'event',
                'group' => $spot ? AroundKinds::group($candidate->category) : 'Events',
                // The day's listed hours only: category defaults are "not listed", never shown as hours.
                'hours' => ! $spot ? null : match (true) {
                    $candidate->closedToday => 'Closed on this day',
                    $candidate->hoursAssumed || $candidate->opensAt === null || $candidate->closesAt === null => 'Hours not listed',
                    default => 'Open '.$candidate->opensAt->setTimezone('Europe/Berlin')->format('H:i').'–'.$candidate->closesAt->setTimezone('Europe/Berlin')->format('H:i'),
                },
            ];
        }, $pool))]);
    }
}
