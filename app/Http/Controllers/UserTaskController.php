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
     * Update one user_task. Accepts any subset of {status, is_applicable,
     * notes, documents_checked} — the bureaucracy page sends partial payloads
     * as the user clicks through a task's lifecycle (Not started → In progress
     * → Submitted → Done), opts out via "doesn't apply to me", or ticks
     * documents off the per-task checklist.
     */
    public function update(Request $request, UserTask $userTask): RedirectResponse
    {
        abort_unless($userTask->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'is_applicable' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'documents_checked' => ['sometimes', 'array', 'max:50'],
            'documents_checked.*' => ['string', 'max:500'],
            // The booked office appointment — becomes the effective deadline.
            'appointment_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('documents_checked', $data)) {
            // Only documents the task actually lists can be checked — stale
            // entries (after a content update) are silently dropped. Entries
            // are plain strings or {label, note?} objects; checks store labels.
            $known = collect($userTask->task?->documents_required ?? [])
                ->map(fn ($doc) => is_array($doc) ? ($doc['label'] ?? null) : $doc)
                ->filter()
                ->all();
            $data['documents_checked'] = array_values(array_intersect($data['documents_checked'], $known));
        }

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
