<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\UserTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserTaskController extends Controller
{
    /**
     * Update one user_task. Accepts any subset of {status, is_applicable, notes}
     * — the framing-B page sends partial payloads as the user clicks through
     * a task's lifecycle (Not started → In progress → Submitted → Done) or
     * opts out via "doesn't apply to me".
     */
    public function update(Request $request, UserTask $userTask): RedirectResponse
    {
        abort_unless($userTask->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'is_applicable' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['status'])) {
            $status = TaskStatus::from((string) $data['status']);
            if ($status === TaskStatus::Done) {
                $userTask->markDone();
            } else {
                $userTask->status = $status;
                // Re-opening a previously-done task clears completed_at + next_due_at
                if ($userTask->completed_at !== null) {
                    $userTask->completed_at = null;
                    $userTask->next_due_at = null;
                }
                $userTask->save();
            }
            unset($data['status']);
        }

        if ($data !== []) {
            $userTask->update($data);
        }

        return back();
    }
}
