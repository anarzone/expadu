<?php

namespace App\Http\Controllers;

use App\Profile\ProfileEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Layer-3 just-in-time profiling: teaser cards, the "I've moved in" action
 * and future life-event buttons all land here. One attribute per request,
 * whitelisted, logged append-only.
 */
class ProfileAttributeController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $writable = [...array_keys(ProfileEngine::ATTRIBUTE_VALUES), ...ProfileEngine::DATE_ATTRIBUTES];

        $data = $request->validate([
            'attribute' => ['required', 'string', Rule::in($writable)],
            'value' => ['required'],
            'source' => ['sometimes', 'string', Rule::in(['onboarding', 'teaser', 'banner', 'life_event'])],
        ]);

        $user = $request->user();
        $source = $data['source'] ?? 'teaser';

        if (in_array($data['attribute'], ProfileEngine::DATE_ATTRIBUTES, true)) {
            $request->validate(['value' => ['date', 'before_or_equal:today']]);
            $user->setProfileAttribute($data['attribute'], $data['value'], $source);

            // Moving in ends temporary housing by definition.
            if ($data['attribute'] === 'moved_in_at') {
                $user->setProfileAttribute('housing_status', 'long_term', $source);
            }

            return back();
        }

        $request->validate([
            'value' => [Rule::in(ProfileEngine::ATTRIBUTE_VALUES[$data['attribute']])],
        ]);

        $user->setProfileAttribute($data['attribute'], $data['value'], $source);

        return back();
    }
}
