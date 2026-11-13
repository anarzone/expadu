<?php

namespace App\Rules;

use App\Security\DisposableEmail;
use App\Security\SecurityLog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Turns away throwaway-inbox addresses (see DisposableEmail). */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(DisposableEmail::class)->isDisposable($value)) {
            app(SecurityLog::class)->record(SecurityLog::Blocked, email: $value, reason: 'disposable', channel: request()->routeIs('waitlist.*') ? 'waitlist' : null);
            $fail('Please use an email address you keep. Throwaway inboxes aren’t accepted.');
        }
    }
}
