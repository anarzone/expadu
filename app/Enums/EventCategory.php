<?php

namespace App\Enums;

/**
 * What kind of event a listing is (owner, 28 Oct 2026: What's on's filters, by what people
 * choose between). Culture, which held two thirds of everything, is split into Music, Stage &
 * comedy, Tours and Festivals & markets; Kids & family is new; Language exchange, Stammtisch and
 * International, 14 listings between them, are Meet people with the city's community events.
 */
enum EventCategory: string
{
    case Music = 'music';
    case Stage = 'stage';
    case Tours = 'tours';
    case Festivals = 'festivals';
    case Kids = 'kids';
    case Party = 'party';
    case Sports = 'sports';
    case MeetPeople = 'meet_people';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Music => 'Music',
            self::Stage => 'Stage & comedy',
            self::Tours => 'Tours',
            self::Festivals => 'Festivals & markets',
            self::Kids => 'Kids & family',
            self::Party => 'Party',
            self::Sports => 'Sports',
            self::MeetPeople => 'Meet people',
            self::Other => 'Other',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Music => '🎵',
            self::Stage => '🎭',
            self::Tours => '🚶',
            self::Festivals => '🎪',
            self::Kids => '🎈',
            self::Party => '🎉',
            self::Sports => '⚽',
            self::MeetPeople => '🗣️',
            self::Other => '📌',
        };
    }

    /** What belongs here, as the classifier is told it. */
    public function description(): string
    {
        return match ($this) {
            self::Music => 'concerts, gigs, DJs, classical, jazz, choirs',
            self::Stage => 'theatre, comedy, cabaret, opera, musicals, dance shows, readings, circus',
            self::Tours => 'guided tours and walks, boat trips, tastings and visits with a guide',
            self::Festivals => 'festivals, Christmas markets, fairs, flea and food markets, carnival',
            self::Kids => 'made for children or families: read-alouds, workshops for kids, family days',
            self::Party => 'parties and club nights',
            self::Sports => 'matches, races, sport to watch or join',
            self::MeetPeople => 'language exchanges, Stammtisch, expat and international meetups, community groups such as repair cafés, reading circles and knitting groups',
            self::Other => 'anything else: talks, workshops for adults, exhibitions, film',
        };
    }

    /**
     * Older categories, still on rows not yet sorted again (events:recategorise), shown under
     * what they now are. Culture had no single new home, so it reads as Other until sorted.
     */
    public static function fromLegacy(?string $category): self
    {
        return match ($category) {
            'language', 'language_exchange', 'stammtisch', 'intl_meetup', 'social' => self::MeetPeople,
            default => self::tryFrom((string) $category) ?? self::Other,
        };
    }
}
