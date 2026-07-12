<?php

namespace App\Exceptions;

use Exception;

class CologneBoundaryUnavailable extends Exception
{
    public static function incomplete(int $available, int $expected): self
    {
        return new self("Event coordinate safety requires {$expected} official Cologne polygons; {$available} are available.");
    }
}
