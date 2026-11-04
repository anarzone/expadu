<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\EmailConfirmation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Privacy\ForgetAccount;
use App\Services\LocationDataRetention;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function resendVerification(Request $request): JsonResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['sent' => true]);
    }

    /** For an account without a password: the code that confirms deleting it. */
    public function sendDeletionCode(Request $request, EmailConfirmation $confirmation): JsonResponse
    {
        abort_unless(EmailConfirmation::applies($request->user()), 422);

        return response()->json(['sent' => $confirmation->send($request, $request->user())]);
    }

    /** Deletes the account for good, with everything ForgetAccount and the web's Delete account remove. */
    #[BodyParameter('password', 'The password, for an account that has one.', required: false, type: 'string')]
    #[BodyParameter('code', 'The emailed code, for an account without a password.', required: false, type: 'string')]
    public function destroy(ProfileDeleteRequest $request, LocationDataRetention $retention, ForgetAccount $forget): Response
    {
        $user = $request->user();

        $retention->withUserLock($user->id, function () use ($user, $retention, $forget): void {
            $retention->purgeUser($user->id);
            $forget->forget($user);
            $user->delete();
        });

        return response()->noContent();
    }
}
