<?php

namespace App\Bureaucracy\Ai;

use App\Models\BureaucracyCase;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingPurpose;

final class BureaucracyAiQuota
{
    public function remaining(BureaucracyCase $case): int
    {
        return max(0, $this->limit() - $this->used($case));
    }

    private function used(BureaucracyCase $case): int
    {
        return $case->user_id === null ? 0 : app(ExternalProcessingGate::class)->used($case->user_id, ProcessingPurpose::FactExtraction);
    }

    private function limit(): int
    {
        return max(1, (int) config('services.bureaucracy_llm.daily_limit', 20));
    }
}
