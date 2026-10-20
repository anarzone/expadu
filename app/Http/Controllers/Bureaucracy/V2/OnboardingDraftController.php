<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\CompleteOnboardingDraftRequest;
use App\Http\Requests\Bureaucracy\V2\DiscardOnboardingDraftRequest;
use App\Http\Requests\Bureaucracy\V2\ReadOnboardingDraftRequest;
use App\Http\Requests\Bureaucracy\V2\SaveOnboardingDraftRequest;
use App\Models\BureaucracyPerson;
use App\Onboarding\BureaucracyDraftSchema;
use App\Onboarding\CompleteBureaucracyOnboarding;
use App\Onboarding\ReviewBureaucracyDraft;
use App\Onboarding\SaveBureaucracyDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OnboardingDraftController extends Controller
{
    public function show(ReadOnboardingDraftRequest $request, BureaucracyPerson $person, SaveBureaucracyDraft $drafts, BureaucracyDraftSchema $schema): JsonResponse
    {
        return response()->json(['draft' => $drafts->read($request->user(), $person), 'schema_version' => $schema->version()])
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(SaveOnboardingDraftRequest $request, BureaucracyPerson $person, SaveBureaucracyDraft $drafts): JsonResponse
    {
        $data = $request->validated();
        $drafts->execute($request->user(), $person, $data['draft_id'], (int) $data['expected_version'], (int) $data['step'], $data['answers']);

        return response()->json(['draft' => $drafts->read($request->user(), $person)])->header('Cache-Control', 'private, no-store');
    }

    public function review(ReadOnboardingDraftRequest $request, BureaucracyPerson $person, ReviewBureaucracyDraft $review): JsonResponse
    {
        return response()->json($review->for($request->user(), $person))->header('Cache-Control', 'private, no-store');
    }

    public function complete(CompleteOnboardingDraftRequest $request, BureaucracyPerson $person, CompleteBureaucracyOnboarding $complete): JsonResponse
    {
        $data = $request->validated();

        return response()->json($complete->execute($request->user(), $person, $data['draft_id'], (int) $data['draft_version'],
            (int) $data['expected_fact_revision'], $data['request_id']))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(DiscardOnboardingDraftRequest $request, BureaucracyPerson $person, SaveBureaucracyDraft $drafts): Response
    {
        $data = $request->validated();
        $drafts->discard($request->user(), $person, $data['draft_id'], (int) $data['expected_version']);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
