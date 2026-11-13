<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Security\FormTraps;
use App\Security\SecurityLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private readonly FormTraps $traps) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // The web form carries FormTraps; a scripted post there would mail a
        // verification link to a stranger. The iPhone app registers through
        // the API, which has no form to trap.
        $request = request();
        if ($request->routeIs('register.store') && ($suspicion = $this->traps->suspicion($request)) !== null) {
            Log::info('signup.blocked', ['form' => 'register', 'reason' => $suspicion]);
            app(SecurityLog::class)->record(SecurityLog::Blocked, email: is_string($input['email'] ?? null) ? $input['email'] : null, reason: $suspicion);

            throw ValidationException::withMessages([
                'email' => 'We couldn’t create your account just now. Please try again.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
    }
}
