<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Security\FormTraps;
use App\Security\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /** New accounts one visitor's address may make in a day, website and app together. */
    public const DailyPerIp = 5;

    public function __construct(private readonly FormTraps $traps) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // The web form and the iPhone app both carry FormTraps' stamp (the
        // app fetches it when its sign-in screen opens); a scripted post would
        // mail a verification link to a stranger.
        $request = request();
        $form = $request->routeIs('register.store') || $request->is('api/v1/auth/register');
        $email = is_string($input['email'] ?? null) ? $input['email'] : null;

        if ($form && ($suspicion = $this->traps->suspicion($request) ?? ($this->traps->expired($request) ? 'expired' : null)) !== null) {
            $this->refuse($suspicion, $email);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        if ($form) {
            if (RateLimiter::tooManyAttempts($this->ipKey($request), self::DailyPerIp)) {
                $this->refuse('ip_daily_cap', $email, 'Too many new accounts from this connection today. Please try again tomorrow.');
            }
            // One account per stamp: the same stamp posted again is a script.
            if (! $this->traps->claim($request)) {
                $this->refuse('reused', $email);
            }
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        if ($form) {
            RateLimiter::hit($this->ipKey($request), 86_400);
        }

        return $user;
    }

    private function refuse(string $reason, ?string $email, string $message = 'We couldn’t create your account just now. Please try again.'): never
    {
        Log::info('signup.blocked', ['form' => 'register', 'reason' => $reason]);
        app(SecurityLog::class)->record(SecurityLog::Blocked, email: $email, reason: $reason);

        throw ValidationException::withMessages(['email' => $message]);
    }

    private function ipKey(Request $request): string
    {
        return 'signups:'.$request->ip();
    }
}
