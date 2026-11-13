<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Privacy\DeleteAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProfileController extends Controller
{
    /**
     * The profile is Settings' Your account now.
     */
    public function edit(): RedirectResponse
    {
        return redirect()->route('settings.section', 'account');
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
    public function destroy(ProfileDeleteRequest $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $deleteAccount->delete($request->user(), fn () => Auth::logout());

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
