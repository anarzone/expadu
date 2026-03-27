<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\UserTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
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
}
