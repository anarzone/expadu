<?php

namespace App\Enums;

enum SpotCategory: string
{
    // v2 physical-leisure categories — the primary Places content
    case Park = 'park';
    case Playground = 'playground';
    case Pitch = 'pitch';
    case Basketball = 'basketball';
    case Tennis = 'tennis';
    case Skatepark = 'skatepark';
    case Swimming = 'swimming';
    case Lake = 'lake';
    case DogPark = 'dog_park';
    case TableTennis = 'table_tennis';
    case Boules = 'boules';
    case Bbq = 'bbq';
    case Viewpoint = 'viewpoint';

    // Indoor / legacy categories — kept so existing rows stay valid and the
    // composer can still mix in cafés on rainy days; de-emphasised in the UI.
    case Cafe = 'cafe';
    case Coworking = 'coworking';
    case Library = 'library';
    case Restaurant = 'restaurant';
    case FastFood = 'fast_food';
    case Bar = 'bar';
    case Bakery = 'bakery';

    public function isOutdoor(): bool
    {
        return match ($this) {
            self::Park, self::Playground, self::Pitch, self::Basketball,
            self::Tennis, self::Skatepark, self::Lake, self::DogPark,
            self::TableTennis, self::Boules, self::Bbq, self::Viewpoint => true,
            default => false,
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Park => '🌳',
            self::Playground => '🛝',
            self::Pitch => '⚽',
            self::Basketball => '🏀',
            self::Tennis => '🎾',
            self::Skatepark => '🛹',
            self::Swimming => '🏊',
            self::Lake => '🏞️',
            self::DogPark => '🐕',
            self::TableTennis => '🏓',
            self::Boules => '🎯',
            self::Bbq => '🧺',
            self::Viewpoint => '🌅',
            self::Cafe => '☕',
            self::Coworking => '💻',
            self::Library => '📚',
            self::Restaurant => '🍽️',
            self::FastFood => '🌯',
            self::Bar => '🍻',
            self::Bakery => '🥐',
        };
    }

    /**
     * The six coarse Places filter buckets the UI exposes. Indoor/legacy
     * categories map to 'other' and are excluded from the Places page.
     */
    public function coarse(): string
    {
        return match ($this) {
            self::Park, self::Viewpoint, self::Bbq => 'park',
            self::Pitch => 'pitch',
            self::Basketball, self::Tennis, self::TableTennis, self::Boules, self::Skatepark => 'court',
            self::Swimming, self::Lake => 'swimming',
            self::Playground => 'playground',
            self::DogPark => 'dog_park',
            default => 'other',
        };
    }

    /**
     * Fine category values that roll up into a coarse Places bucket.
     *
     * @return list<string>
     */
    public static function finesForCoarse(string $coarse): array
    {
        return array_values(array_map(
            fn (self $c) => $c->value,
            array_filter(self::cases(), fn (self $c) => $c->coarse() === $coarse),
        ));
    }

    /** All fine category values that belong to the Places page (non-'other'). */
    public static function placesFines(): array
    {
        return array_values(array_map(
            fn (self $c) => $c->value,
            array_filter(self::cases(), fn (self $c) => $c->coarse() !== 'other'),
        ));
    }

    public static function coarseLabel(string $coarse): string
    {
        return match ($coarse) {
            'park' => 'Parks',
            'pitch' => 'Pitches',
            'court' => 'Courts',
            'swimming' => 'Swimming',
            'playground' => 'Playgrounds',
            'dog_park' => 'Dog parks',
            default => ucfirst($coarse),
        };
    }
}
