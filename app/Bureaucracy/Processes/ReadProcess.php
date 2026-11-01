<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyProcess;
use App\Models\User;

final class ReadProcess
{
    public function __construct(private PlanReadModel $plans) {}

    /** Detail is a projection of the same authorised plan, never a second decision engine. */
    public function for(User $actor, BureaucracyProcess $process): array
    {
        $plan = $this->plans->for($actor, $process->dossier->person, $process->jurisdiction);
        $current = collect([...$plan['processes'], ...$plan['history']])->firstWhere('id', $process->id);
        abort_if($current === null, 404);

        return [...$current, 'assessment_revision' => $plan['assessment_revision'],
            'evaluated_at' => $plan['evaluated_at'], 'next_reassessment_at' => $plan['next_reassessment_at']];
    }
}
