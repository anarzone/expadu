<?php

namespace App\Http\Controllers;

use App\Models\Spot;
use App\Places\PlaceIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Spot $spot): JsonResponse
    {
        $reviews = $spot->effectiveReviews()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($reviews);
    }

    public function store(Request $request, Spot $spot): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = app(PlaceIdentity::class)->withCanonicalLock($spot->id, function (Spot $canonical) use ($request, $validated) {
            $review = $canonical->reviews()->updateOrCreate(
                ['user_id' => $request->user()->id],
                $validated,
            );
            $review->touch();
            $canonical->updateRating();

            return $review;
        });

        return response()->json($review, 201);
    }
}
