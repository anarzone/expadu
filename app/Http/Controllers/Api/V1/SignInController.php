<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Auth\AppSignIn;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SignInResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class SignInController extends Controller
{
    public function __construct(private AppSignIn $signIn) {}

    /** Email and password. An account with two-step sign-in gets a challenge, not a token. */
    public function token(Request $request): SignInResource
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $data['email']]);
        if (! $user instanceof User || ! $provider->validateCredentials($user, ['password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return $this->signedIn($user, $data['device_name'], (bool) ($data['remember'] ?? true));
    }

    /** The second step: the app's code, or a recovery code. */
    public function twoFactor(Request $request): SignInResource
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $answer = $this->signIn->answer($data['challenge'], $data['code'] ?? null, $data['recovery_code'] ?? null);
        if ($answer === null) {
            throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
        }

        return new SignInResource($answer);
    }

    /** Trades the code from Google's sign-in sheet for a token, proving it's the app that asked. */
    public function exchange(Request $request): SignInResource
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'verifier' => ['required', 'string', 'min:43', 'max:128'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = $this->signIn->exchange($data['code'], $data['verifier']);
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['code' => __('auth.failed')]);
        }

        return $this->signedIn($user, $data['device_name'], true);
    }

    /** A new account, signed in, its verification link on the way (as the web's). */
    public function register(Request $request, CreateNewUser $creator): SignInResource
    {
        $request->validate(['device_name' => ['required', 'string', 'max:255']]);

        $user = $creator->create($request->only(['name', 'email', 'password', 'password_confirmation']));
        event(new Registered($user));

        return new SignInResource(['user' => $user, ...$this->signIn->issue($user, $request->string('device_name')->value(), true)]);
    }

    /** Answers the same whether or not the address has an account. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);
        Password::broker()->sendResetLink(['email' => $data['email']]);

        return response()->json(['sent' => true]);
    }

    /** A fresh token for the one making the request, which stops working. */
    public function refresh(Request $request): SignInResource
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        abort_unless($current instanceof PersonalAccessToken, 401);

        $issued = $this->signIn->issue($user, (string) $current->name, true);
        $current->delete();

        return new SignInResource(['user' => $user, ...$issued]);
    }

    public function signOut(Request $request): Response
    {
        $current = $request->user()->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return response()->noContent();
    }

    private function signedIn(User $user, string $device, bool $remember): SignInResource
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return new SignInResource(['challenge' => $this->signIn->challenge($user, $device, $remember)]);
        }

        return new SignInResource(['user' => $user, ...$this->signIn->issue($user, $device, $remember)]);
    }
}
