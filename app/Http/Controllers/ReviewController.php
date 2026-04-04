<?php

namespace App\Http\Controllers;

use App\Models\Spot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Spot $spot): JsonResponse
    {
        $reviews = $spot->reviews()
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

        $review = $spot->reviews()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated,
        );

        // Recalculate spot's average rating
        $spot->updateRating();

        return response()->json($review, 201);
    }
}
