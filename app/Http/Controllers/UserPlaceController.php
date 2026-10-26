<?php

namespace App\Http\Controllers;

use App\Models\UserPlace;
use App\Places\AddressLocator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserPlaceController extends Controller
{
    public function store(Request $request, AddressLocator $locator): RedirectResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'arrive_by' => ['nullable', 'date_format:H:i'],
            'day_mode' => ['nullable', 'in:all,weekdays,weekends,custom'],
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
        ]);

        [$validated, $notFound] = $this->locate($validated, $locator);

        $request->user()->places()->create([
            ...$validated,
            'sort_order' => $request->user()->places()->count(),
        ]);

        return $notFound ? back()->with('status', $notFound) : back();
    }

    /**
     * Your Home or Work from Travel's address line (the mock's "Add home"):
     * the place you picked, with its point. It fills the one you have (an
     * older account may hold an empty one) or makes it.
     */
    public function setCategory(Request $request, string $category): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $place = $request->user()->places()->firstOrNew(['category' => $category]);
        $place->fill([
            ...$validated,
            'emoji' => $place->emoji ?: ($category === 'home' ? '🏠' : '💼'),
        ]);
        $place->sort_order ??= $request->user()->places()->count();
        $place->save();

        return back();
    }

    public function update(Request $request, UserPlace $userPlace, AddressLocator $locator): RedirectResponse
    {
        if ($userPlace->user_id !== $request->user()->id) {
            abort(403);
        }

        $validated = $request->validate([
            'emoji' => ['sometimes', 'string', 'max:10'],
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'arrive_by' => ['nullable', 'date_format:H:i'],
            'day_mode' => ['nullable', 'in:all,weekdays,weekends,custom'],
            'active_days' => ['nullable', 'array'],
            'active_days.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
        ]);

        // Prevent changing category of protected places
        if ($userPlace->isProtected()) {
            unset($validated['category']);
        }

        [$validated, $notFound] = $this->locate($validated, $locator);

        $userPlace->update($validated);

        return $notFound ? back()->with('status', $notFound) : back();
    }

    /**
     * A typed address without a picked suggestion is looked up. Found, it
     * gets that point; not found, it keeps none and the person is told: a
     * point is never guessed, and Leave now and trips need a real one.
     *
     * @param  array<string, mixed>  $validated
     * @return array{array<string, mixed>, string|null}
     */
    private function locate(array $validated, AddressLocator $locator): array
    {
        if (! array_key_exists('address', $validated)) {
            return [$validated, null];
        }

        if (isset($validated['lat'], $validated['lng']) || blank($validated['address'])) {
            return [[...$validated, 'located_from_address' => false], null];
        }

        $point = $locator->locate((string) $validated['address']);

        if ($point !== null) {
            return [[...$validated, ...$point, 'located_from_address' => true], null];
        }

        return [
            [...$validated, 'lat' => null, 'lng' => null, 'located_from_address' => false],
            'We couldn’t find that address on the map. Edit the place and pick a suggestion so trips can start there.',
        ];
    }

    public function destroy(Request $request, UserPlace $userPlace): RedirectResponse
    {
        if ($userPlace->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($userPlace->isProtected()) {
            return back()->withErrors(['place' => 'Home and Work places cannot be removed — they are needed for route recommendations.']);
        }

        $userPlace->delete();

        return back();
    }
}
