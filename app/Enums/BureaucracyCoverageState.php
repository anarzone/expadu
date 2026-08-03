<?php

namespace App\Enums;

enum BureaucracyCoverageState: string
{
    case Matched = 'matched';
    case NeedsInformation = 'needs_information';
    case NotCovered = 'not_covered';
    case Conflict = 'conflict';
}
