<?php

namespace App\Enums;

enum EventCategory: string
{
    case LanguageExchange = 'language_exchange';
    case Stammtisch = 'stammtisch';
    case IntlMeetup = 'intl_meetup';
    case Sports = 'sports';
    case Culture = 'culture';
    case Party = 'party';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LanguageExchange => 'Language exchange',
            self::Stammtisch => 'Stammtisch',
            self::IntlMeetup => 'International',
            self::Sports => 'Sports',
            self::Culture => 'Culture',
            self::Party => 'Party',
            self::Other => 'Other',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::LanguageExchange => '🗣️',
            self::Stammtisch => '🍻',
            self::IntlMeetup => '🌍',
            self::Sports => '⚽',
            self::Culture => '🎭',
            self::Party => '🎉',
            self::Other => '📌',
        };
    }

    /**
     * Map the legacy scraper categories onto the composer taxonomy so
     * pre-pivot rows stay filterable until the AI reprocesses them.
     */
    public static function fromLegacy(?string $category): self
    {
        return match ($category) {
            'language' => self::LanguageExchange,
            'social' => self::IntlMeetup,
            'culture', 'music' => self::Culture,
            'sports' => self::Sports,
            default => self::tryFrom((string) $category) ?? self::Other,
        };
    }
}
