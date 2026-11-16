<?php

namespace App\Http\Controllers\Api\V1;

use App\Composer\Actions\ActionRefused;
use App\Composer\Actions\SettingsActions;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Their situation and German level, changed on "What Expadu knows" by the profile's own rules (the
 * ones Composer's update_profile checks), so the phone and Composer can't disagree on what's allowed.
 */
class ProfileController extends Controller
{
    /**
     * One of the two fields, set to one of its values; why not (422) when it isn't one.
     */
    public function update(Request $request, SettingsActions $settings): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'in:situation,german_level'],
            'value' => ['required', 'string', 'max:40'],
        ]);
        $user = $request->user();
        $current = $user->{$validated['field']}?->value;
        if ($current !== $validated['value']) {
            try {
                $now = CarbonImmutable::now();
                $settings->run($user, 'update_profile', $settings->prepare($user, 'update_profile', $validated, $now), $now);
            } catch (ActionRefused $refused) {
                return response()->json(['message' => $refused->getMessage()], 422);
            }
        }
        $user->refresh();

        return response()->json([
            'situation' => $user->situation?->value,
            'german_level' => $user->german_level?->value,
        ]);
    }
}
