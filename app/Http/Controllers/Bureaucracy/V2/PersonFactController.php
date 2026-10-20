<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\FactAnswerHistory;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\CorrectFactRequest;
use App\Http\Requests\Bureaucracy\V2\RecordFactChangeRequest;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonFactController extends Controller
{
    public function __construct(private PersonAccess $access, private ConfirmedFactView $view, private RecordFactChange $changes, private CorrectFact $corrections, private FactRegistry $registry) {}

    public function index(Request $request, BureaucracyPerson $person): JsonResponse
    {
        $this->access->authorize($request->user(), $person, AccessScope::ViewFacts);
        $case = $person->dossier()->firstOrFail();

        $view = $this->view->forCase($case, now()->toDateString());
        $checked = BureaucracyCaseFact::query()->where('case_id', $case->id)->whereIn('id', array_column($view['evidence'], 'fact_id'))->pluck('confirmed_at', 'id');
        foreach ($view['evidence'] as $key => $evidence) {
            $view['evidence'][$key]['checked_at'] = $checked[$evidence['fact_id']]?->utc()->toIso8601String();
        }

        return response()->json($view)->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, BureaucracyPerson $person, string $key, FactAnswerHistory $history): JsonResponse
    {
        $this->access->authorize($request->user(), $person, AccessScope::ViewFacts);
        abort_unless($this->registry->all()->has($key), 404);

        return response()->json($history->for($person->dossier()->firstOrFail(), $key))->header('Cache-Control', 'private, no-store');
    }

    public function change(RecordFactChangeRequest $request, BureaucracyPerson $person): JsonResponse
    {
        $data = $request->validated();
        $fact = $this->changes->execute($request->user(), $person, $data['key'], $data['value'], $data['effective_from'] ?? null, (int) $data['expected_revision'], $data['answer_state'] ?? 'value');

        return response()->json(['fact_id' => $fact->id, 'revision' => $fact->case->fact_version])->header('Cache-Control', 'private, no-store');
    }

    public function correct(CorrectFactRequest $request, BureaucracyPerson $person, int $fact): JsonResponse
    {
        $data = $request->validated();
        $corrected = $this->corrections->execute($request->user(), $person, $fact, $data['value'], (int) $data['expected_revision'], $data['answer_state'] ?? 'value');

        return response()->json(['fact_id' => $corrected->id, 'revision' => $corrected->case->fact_version])->header('Cache-Control', 'private, no-store');
    }
}
