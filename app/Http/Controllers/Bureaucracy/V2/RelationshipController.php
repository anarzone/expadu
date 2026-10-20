<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\ReadRelationshipReview;
use App\Bureaucracy\People\RecordRelationship;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bureaucracy\V2\RecordRelationshipRequest;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyRelationship;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RelationshipController extends Controller
{
    public function __construct(private RecordRelationship $records, private PersonAccess $access) {}

    public function index(Request $request, BureaucracyPerson $person, ReadRelationshipReview $review): JsonResponse
    {
        return response()->json($review->for($request->user(), $person))->header('Cache-Control', 'private, no-store');
    }

    public function store(RecordRelationshipRequest $request, BureaucracyPerson $person): JsonResponse
    {
        $data = $request->validated();
        $related = BureaucracyPerson::query()->findOrFail($data['related_person_id']);
        $link = $this->records->execute($request->user(), $person, $related, $data['type'], $data['effective_from'] ?? null, (int) $data['expected_revision']);

        return response()->json(['relationship_id' => $link->id, 'record_version' => $person->fresh()->record_version], 201)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, BureaucracyPerson $person, int $relationship): Response
    {
        $this->access->authorize($request->user(), $person, AccessScope::EditFacts);
        $link = BureaucracyRelationship::query()->whereKey($relationship)->where('person_id', $person->id)->firstOrFail();
        $this->records->revoke($request->user(), $link);

        return response()->noContent();
    }
}
