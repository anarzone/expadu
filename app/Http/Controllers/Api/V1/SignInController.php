<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Auth\AppleIdentity;
use App\Auth\AppSignIn;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SignInResource;
use App\Models\User;
use App\Security\SecurityLog;
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
    public function __construct(private AppSignIn $signIn, private SecurityLog $log) {}

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
            $this->log->record(SecurityLog::LoginFailed, $user instanceof User ? $user : null, email: $data['email']);

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

        $challenged = $this->signIn->challengedUser($data['challenge']);
        $answer = $this->signIn->answer($data['challenge'], $data['code'] ?? null, $data['recovery_code'] ?? null);
        if ($answer === null) {
            $this->log->record(SecurityLog::TwoFactorFailed, $challenged);

            throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
        }

        $this->log->record(SecurityLog::Login, $answer['user']);

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

    /**
     * Sign in with Apple: the sheet's identity token, checked against Apple's keys and the nonce
     * the app made. The same Apple ID always opens the same account; a verified address joins one.
     */
    public function apple(Request $request, AppleIdentity $apple): SignInResource
    {
        $data = $request->validate([
            'identity_token' => ['required', 'string', 'max:8192'],
            'nonce' => ['required', 'string', 'min:32', 'max:128'],
            'name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $identity = $apple->verify($data['identity_token'], $data['nonce']);
        if ($identity === null) {
            throw ValidationException::withMessages(['identity_token' => __('auth.failed')]);
        }

        $user = User::where('apple_id', $identity['sub'])->first();
        if (! $user && $identity['email_verified']) {
            $user = User::where('email', $identity['email'])->first();
            $user?->forceFill(['apple_id' => $identity['sub']])->save();
        }

        if (! $user) {
            if ($identity['email'] === null || User::where('email', $identity['email'])->exists()) {
                throw ValidationException::withMessages(['identity_token' => __('Apple didn’t share an email we can use. Sign in with your email instead.')]);
            }

            $user = new User;
            $user->forceFill([
                'name' => trim((string) ($data['name'] ?? '')) ?: 'User',
                'email' => $identity['email'],
                'apple_id' => $identity['sub'],
                'email_verified_at' => $identity['email_verified'] ? now() : null,
            ])->save();
            if ($identity['email_verified']) {
                $this->log->record(SecurityLog::Signup, $user, reason: 'apple');
            } else {
                event(new Registered($user));
            }
        }

        return $this->signedIn($user, $data['device_name'], true);
    }

    /** A new account, signed in, its verification link on the way (as the web's). */
    public function register(Request $request, CreateNewUser $creator): SignInResource
    {
        $request->validate([
            'name' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'password_confirmation' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

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

        $this->log->record(SecurityLog::Login, $user);

        return new SignInResource(['user' => $user, ...$this->signIn->issue($user, $device, $remember)]);
    }
}
