<?php

namespace App\Bureaucracy\Ai\Contracts;

use App\Bureaucracy\Ai\CaseFactExtractionRequest;
use App\Bureaucracy\Ai\CaseFactExtractionResult;

interface ExtractsCaseFact
{
    public function extract(CaseFactExtractionRequest $request): CaseFactExtractionResult;
}
