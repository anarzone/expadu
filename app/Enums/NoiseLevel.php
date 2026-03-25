<?php

namespace App\Enums;

enum NoiseLevel: string
{
    case Quiet = 'quiet';
    case Moderate = 'moderate';
    case Loud = 'loud';
}
