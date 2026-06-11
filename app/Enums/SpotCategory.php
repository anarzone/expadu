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
}
