<?php

namespace App\Bureaucracy\Ai;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseMessage;

final class BureaucracyAiQuota
{
    private const Operation = 'extract_case_fact';

    private const Role = 'user';

    public function consume(BureaucracyCase $case, string $content): ?BureaucracyCaseMessage
    {
        if ($this->used($case) >= $this->limit()) {
            return null;
        }

        return $case->messages()->create([
            'role' => self::Role,
            'content' => $content,
            'operation' => self::Operation,
            'prompt_version' => (string) config('services.bureaucracy_llm.prompt_version'),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function remaining(BureaucracyCase $case): int
    {
        return max(0, $this->limit() - $this->used($case));
    }

    private function used(BureaucracyCase $case): int
    {
        return BureaucracyCaseMessage::query()
            ->where('case_id', $case->getKey())
            ->where('role', self::Role)
            ->where('operation', self::Operation)
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    private function limit(): int
    {
        return max(1, (int) config('services.bureaucracy_llm.daily_limit', 20));
    }
}
