<?php

namespace App\Http\Controllers;

use App\Models\UserPlace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserPlaceController extends Controller
{
    /**
     * Store a new user place.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $request->user()->places()->create([
            ...$validated,
            'sort_order' => $request->user()->places()->count(),
        ]);

        return back();
    }

    /**
     * Delete a user place.
     */
    public function destroy(Request $request, UserPlace $userPlace): RedirectResponse
    {
        if ($userPlace->user_id !== $request->user()->id) {
            abort(403);
        }

        $userPlace->delete();

        return back();
    }
}
