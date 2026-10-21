<?php

namespace Tests\Support;

use App\Bureaucracy\Verification\ClaimCheck;
use App\Bureaucracy\Verification\SourceCheckRecorder;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Test double: the suite has no network, so an automatically checked card counts as
 * passing whenever its claims pass every offline part of the check. Tests that exercise
 * the real check bind SourceCheckRecorder back explicitly.
 */
final class OfflineSourceChecks extends SourceCheckRecorder
{
    public function window(Task $task, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today(config('app.timezone'));

        return app(ClaimCheck::class)->offlineErrors($task->attributesToArray()) === []
            ? ['verified_at' => $today->toDateString(), 'review_due_at' => $today->addDays(self::PassDays)->toDateString()]
            : ['verified_at' => null, 'review_due_at' => null];
    }
}
