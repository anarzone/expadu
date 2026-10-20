<?php

namespace App\Http\Controllers\Bureaucracy\V2;

use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FactSchemaController extends Controller
{
    /** Public reviewed registry wording and answer shapes only; no person, value or legacy mapping. */
    public function __invoke(FactRegistry $registry): JsonResponse
    {
        $facts = $registry->all()->map(fn (FactDefinition $definition) => ['key' => $definition->key, 'type' => $definition->type,
            'options' => $definition->options, 'question' => $definition->question, 'why' => $definition->why,
            'date_semantics' => $definition->dateSemantics, 'allows_not_applicable' => $definition->allowsNotApplicable,
            'subject_scope' => $definition->subjectScope, 'sensitivity' => $definition->sensitivity,
            'reconfirm_after_days' => $definition->reconfirmAfterDays])->values()->all();

        return response()->json(['schema_version' => 'bureaucracy.fact-schema.1', 'registry_version' => $registry->version(), 'facts' => $facts])
            ->header('Cache-Control', 'private, no-store');
    }
}
