<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Stacktrace;
use Sentry\UserDataBag;

/**
 * The `before_send` gate for every error leaving the server. Two jobs:
 *
 *  1. Attribute the error to a user by NUMERIC ID ONLY — enough to find who
 *     hit it, never their email/name (send_default_pii stays off).
 *  2. Redact anything sensitive that could ride along in request data: auth
 *     tokens, session cookies, secrets, and — specific to this app — the GPS
 *     coordinates, case facts and AI input we treat as private. Error type and
 *     code location remain useful without arbitrary message bodies or locals.
 *     If sanitisation fails, do not send the unsanitised event.
 */
class SentryScrubber
{
    /** Keys whose values are always replaced, matched case-insensitively as substrings. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'auth',
        'cookie', 'api_key', 'apikey', 'key', 'dsn', 'session',
        'lat', 'lng', 'latitude', 'longitude', 'coord', 'location',
        'vapid', 'csrf', 'xsrf',
        'prompt', 'text', 'message', 'content', 'answer', 'facts', 'value', 'headers',
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
                $path = parse_url((string) ($request['url'] ?? ''), PHP_URL_PATH) ?: '';
                if (preg_match('~/(?:bureaucracy|composer|onboarding)(?:/|$)~', $path)) {
                    $request['data'] = self::REDACTED;
                }
                $event->setRequest(self::redact($request));
            }

            $extra = $event->getExtra();
            if ($extra !== []) {
                $event->setExtra(self::redact($extra));
            }

            foreach ($event->getContexts() as $name => $context) {
                $event->setContext($name, self::isSensitive($name)
                    ? ['redacted' => true]
                    : self::redact($context));
            }

            if ($event->getMessage() !== null || $event->getMessageFormatted() !== null) {
                $event->setMessage(self::REDACTED, [], self::REDACTED);
            }

            foreach ($event->getExceptions() as $exception) {
                $exception->setValue(self::REDACTED);
                self::removeVariables($exception->getStacktrace());
            }
            self::removeVariables($event->getStacktrace());

            $event->setBreadcrumb(array_map(function (Breadcrumb $breadcrumb): Breadcrumb {
                $clean = $breadcrumb->withMessage(self::REDACTED);
                foreach (self::redact($breadcrumb->getMetadata()) as $key => $value) {
                    $clean = $clean->withMetadata($key, $value);
                }

                return $clean;
            }, $event->getBreadcrumbs()));
        } catch (\Throwable) {
            return null;
        }

        return $event;
    }

    private static function removeVariables(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
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
            if (is_object($value) || is_resource($value)) {
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
