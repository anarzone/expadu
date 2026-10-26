<?php

namespace App\Support;

/**
 * Where "Sign in again" comes back to (?return= on the sign-in page): a path
 * on this site, never another host ("//x", "/\x", "https://x" are refused).
 */
final class ReturnPath
{
    public static function from(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^/(?![/\\\\])[^\\\\\s]*$#', $value) === 1 ? $value : null;
    }
}
