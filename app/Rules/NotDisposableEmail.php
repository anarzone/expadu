<?php

namespace App\Rules;

use App\Security\DisposableEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Turns away throwaway-inbox addresses (see DisposableEmail). */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && app(DisposableEmail::class)->isDisposable($value)) {
            $fail('Please use an email address you keep. Throwaway inboxes aren’t accepted.');
        }
    }
}
