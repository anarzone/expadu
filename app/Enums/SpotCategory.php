<?php

namespace App\Enums;

enum SpotCategory: string
{
    case Cafe = 'cafe';
    case Coworking = 'coworking';
    case Library = 'library';
    case Park = 'park';
    case Restaurant = 'restaurant';
    case FastFood = 'fast_food';
    case Bar = 'bar';
    case Bakery = 'bakery';
}
