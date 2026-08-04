<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;

final class UnavailableCaseFactExtractor implements ExtractsCaseFact
{
    public function extract(CaseFactExtractionRequest $request): CaseFactExtractionResult
    {
        return CaseFactExtractionResult::unavailable();
    }
}
