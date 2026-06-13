<?php

namespace App\Composer;

/**
 * What the universal prompt box was asked to do. The parser (LLM or
 * heuristic) classifies into exactly one of these; the controller then
 * routes to the matching engine. The LLM only ever picks a value here —
 * it never produces the answer itself.
 */
enum PromptIntent: string
{
    case PlanDay = 'plan_day';
    case BureaucracyQ = 'bureaucracy_q';
    case Find = 'find';
    case TakeMeThere = 'take_me_there';
    case Unknown = 'unknown';
}
