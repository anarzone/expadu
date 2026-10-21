<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A KVB notice in plain language. See App\Transit\NoticeBriefWriter.
 */
#[Fillable(['input_hash', 'locale', 'headline', 'body', 'impact', 'model', 'prompt_version'])]
class NoticeBrief extends Model
{
    /**
     * The notice's own words, without the per-line prefix ("Linie 16: ",
     * "Bus 126: "), so the copies KVB lists under each line share one brief.
     */
    public static function hashFor(string $title, ?string $details): string
    {
        return hash('sha256', json_encode([
            'v' => 1,
            'vehicle' => self::vehicle($title),
            'title' => self::withoutService($title),
            'details' => trim((string) $details),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{0: ?string, 1: string} the service ("Linie 16") and the rest
     */
    public static function split(string $title): array
    {
        return preg_match('/^((?:Linie|Bus)\s+\S+):\s*(.*)$/su', trim($title), $m)
            ? [$m[1], trim($m[2])]
            : [null, trim($title)];
    }

    /** "bus", "tram", or null for KVB news without a line prefix. */
    public static function vehicle(string $title): ?string
    {
        return match (true) {
            str_starts_with(trim($title), 'Bus ') => 'bus',
            str_starts_with(trim($title), 'Linie ') => 'tram',
            default => null,
        };
    }

    private static function withoutService(string $title): string
    {
        return self::split($title)[1];
    }
}
