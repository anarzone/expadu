<?php

namespace App\Support;

use Sentry\Event;
use Sentry\UserDataBag;

/**
 * The `before_send` gate for every error leaving the server. Two jobs:
 *
 *  1. Attribute the error to a user by NUMERIC ID ONLY — enough to find who
 *     hit it, never their email/name (send_default_pii stays off).
 *  2. Redact anything sensitive that could ride along in request data: auth
 *     tokens, session cookies, secrets, and — specific to this app — the GPS
 *     coordinates and location pings we treat as private. Wrapped so a shape
 *     we don't expect can never drop the whole event.
 */
class SentryScrubber
{
    /** Keys whose values are always replaced, matched case-insensitively as substrings. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'auth',
        'cookie', 'api_key', 'apikey', 'key', 'dsn', 'session',
        'lat', 'lng', 'latitude', 'longitude', 'coord', 'location',
        'vapid', 'csrf', 'xsrf',
    ];

    private const REDACTED = '[redacted]';

    public static function scrub(Event $event): ?Event
    {
        try {
            if ($id = auth()->id()) {
                // ID only — attributable without leaking who they are.
                $event->setUser(UserDataBag::createFromArray(['id' => (string) $id]));
            }

            $request = $event->getRequest();
            if ($request !== []) {
                $event->setRequest(self::redact($request));
            }

            $extra = $event->getExtra();
            if ($extra !== []) {
                $event->setExtra(self::redact($extra));
            }
        } catch (\Throwable) {
            // Never let scrubbing itself swallow the error report.
        }

        return $event;
    }

    /**
     * Recursively replace the value of any sensitive-looking key, and strip
     * query strings off request URLs (coordinates must never sit in a URL).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $key === 'query_string') {
                $data[$key] = self::REDACTED;

                continue;
            }
            if (is_string($key) && $key === 'url' && is_string($value)) {
                $data[$key] = explode('?', $value)[0];

                continue;
            }
            if (is_string($key) && self::isSensitive($key)) {
                $data[$key] = self::REDACTED;

                continue;
            }
            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private static function isSensitive(string $key): bool
    {
        $lower = mb_strtolower($key);
        foreach (self::SENSITIVE_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}
