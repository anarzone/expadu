<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\QuestionPreviewRequest;
use App\Http\Resources\Bureaucracy\PersonPlanResource;
use App\Models\BureaucracyPerson;
use Illuminate\Http\JsonResponse;

class PersonPlanController extends Controller
{
    public function __invoke(QuestionPreviewRequest $request, BureaucracyPerson $person, PlanReadModel $plans): JsonResponse
    {
        return (new PersonPlanResource($plans->for($request->user(), $person, $request->validated('jurisdiction'))))
            ->response()->header('Cache-Control', 'private, no-store');
    }
}
