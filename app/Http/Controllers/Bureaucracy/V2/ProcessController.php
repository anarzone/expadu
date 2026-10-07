<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Processes\CorrectProcessEvent;
use App\Bureaucracy\Processes\ReadProcess;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Processes\ReviewProcessChanges;
use App\Bureaucracy\Processes\StartProcess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\CorrectProcessEventRequest;
use App\Http\Requests\Bureaucracy\V2\RecordProcessEventRequest;
use App\Http\Requests\Bureaucracy\V2\ReviewProcessChangesRequest;
use App\Http\Requests\Bureaucracy\V2\StartProcessRequest;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessController extends Controller
{
    public function start(StartProcessRequest $request, BureaucracyPerson $person, StartProcess $start): JsonResponse
    {
        $data = $request->validated();

        return response()->json($start->execute($request->user(), $person, $data['jurisdiction'],
            $data['occurrence_key'], $data['review_token'], $data['request_id']))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, BureaucracyProcess $process, ReadProcess $read): JsonResponse
    {
        return response()->json($read->for($request->user(), $process))->header('Cache-Control', 'private, no-store');
    }

    public function store(RecordProcessEventRequest $request, BureaucracyProcess $process, RecordProcessEvent $events): JsonResponse
    {
        $data = $request->validated();

        return $this->receipt($events->execute($request->user(), $process, $data['event'], $data['payload'],
            (int) $data['expected_version'], $data['request_id'], $data['review_token'] ?? null));
    }

    public function review(ReviewProcessChangesRequest $request, BureaucracyProcess $process, ReviewProcessChanges $review): JsonResponse
    {
        $data = $request->validated();

        return $this->receipt($review->execute($request->user(), $process, (int) $data['expected_version'],
            $data['request_id'], $data['review_token'], $data['bind_occurrence'] ?? null));
    }

    public function correct(CorrectProcessEventRequest $request, BureaucracyProcess $process, int $event, CorrectProcessEvent $correct): JsonResponse
    {
        $data = $request->validated();

        return $this->receipt($correct->execute($request->user(), $process, $event, $data['payload'],
            (int) $data['expected_version'], $data['request_id']));
    }

    private function receipt(BureaucracyProcessEvent $event): JsonResponse
    {
        return response()->json(['event_id' => $event->id, 'process_id' => $event->process_id, 'version' => $event->process_version])
            ->header('Cache-Control', 'private, no-store');
    }
}
