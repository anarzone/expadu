<?php

namespace App\Support;

class OfficialEventSchedule
{
    public static function isUnambiguous(array $record): bool
    {
        return is_string($record['beginndatum'] ?? null)
            && $record['beginndatum'] === ($record['endedatum'] ?? null)
            && self::clockRange($record['uhrzeit'] ?? null) !== null;
    }

    /** @return array{sh: int, sm: int, eh: int, em: int}|null */
    public static function clockRange(?string $time): ?array
    {
        $time = trim((string) preg_replace('/\s+/u', ' ', $time ?? ''));
        if (! preg_match('/^(?:von )?(?<sh>\d{1,2})(?::(?<sm>\d{2}))?\s*(?:bis|[-–])\s*(?<eh>\d{1,2})(?::(?<em>\d{2}))?(?<suffix>\s*Uhr)?$/iu', $time, $clock)
            || (trim($clock['suffix'] ?? '') === '' && (($clock['sm'] ?? '') === '' || ($clock['em'] ?? '') === ''))
            || (int) $clock['sh'] >= 24 || (int) $clock['eh'] >= 24
            || (int) ($clock['sm'] ?? 0) >= 60 || (int) ($clock['em'] ?? 0) >= 60) {
            return null;
        }

        return [
            'sh' => (int) $clock['sh'], 'sm' => (int) ($clock['sm'] ?? 0),
            'eh' => (int) $clock['eh'], 'em' => (int) ($clock['em'] ?? 0),
        ];
    }
}
