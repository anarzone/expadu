<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\LocationDataRetention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProfileController extends Controller
{
    /**
     * The profile is a panel of Settings now (/settings).
     */
    public function edit(): RedirectResponse
    {
        return redirect()->route('settings');
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse|JsonResponse
    {
        $request->user()->fill($request->validated());

        $emailChanged = $request->user()->isDirty('email');

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // A changed email un-verifies the account, so send a fresh link —
        // otherwise the user is bounced to the verify wall with no way through.
        if ($emailChanged) {
            $request->user()->sendEmailVerificationNotification();
        }

        if ($request->expectsJson()) {
            return response()->json(['saved' => true]);
        }

        return back();
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, LocationDataRetention $retention): RedirectResponse
    {
        $user = $request->user();

        $retention->withUserLock($user->id, function () use ($user, $retention): void {
            $retention->purgeUser($user->id);
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
