<?php

namespace App\Bureaucracy\Assessment;

use App\Privacy\ProcessingConsentStore;

final class AssessmentRevision
{
    public const ProjectionVersion = '2026-09-08.unified-plan.1';

    public function for(array $dependencies): string
    {
        return ProcessingConsentStore::digest(['projection_version' => self::ProjectionVersion, 'dependencies' => $dependencies]);
    }
}
