<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\UserEvent;
use App\Models\UserTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * verified_at is nulled once this many users flag a guide as outdated,
     * removing the "verified" badge until the owner re-checks the source.
     */
    private const OUTDATED_REPORT_THRESHOLD = 3;

    /**
     * Toggle task completion for the authenticated user.
     */
    public function toggle(Request $request, Task $task): RedirectResponse
    {
        $userTask = UserTask::firstOrCreate(
            ['user_id' => $request->user()->id, 'task_id' => $task->id],
        );

        $userTask->update([
            'completed_at' => $userTask->completed_at ? null : now(),
        ]);

        return back();
    }

    /**
     * A user flags a guide as outdated — the freshness mechanism that keeps
     * wrong bureaucracy info from quietly surviving.
     */
    public function reportOutdated(Request $request, Task $task): RedirectResponse
    {
        // One report per user per task: dedupe against prior report events.
        $alreadyReported = UserEvent::query()
            ->where('user_id', $request->user()->id)
            ->where('event_type', 'task_reported_outdated')
            ->where('payload->task_id', $task->id)
            ->exists();

        if ($alreadyReported) {
            return back();
        }

        UserEvent::create([
            'user_id' => $request->user()->id,
            'event_type' => 'task_reported_outdated',
            'payload' => ['task_id' => $task->id, 'task_key' => $task->key],
        ]);

        $task->increment('outdated_reports');

        if ($task->outdated_reports >= self::OUTDATED_REPORT_THRESHOLD && $task->verified_at !== null) {
            $task->update(['verified_at' => null]);
        }

        return back()->with('status', 'Thanks — we will re-check this guide.');
    }
}
