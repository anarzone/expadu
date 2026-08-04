<?php

namespace App\Http\Controllers;

use App\Bureaucracy\Cases\UpdateCaseTask;
use App\Enums\TaskStatus;
use App\Http\Requests\UpdateBureaucracyCaseTaskRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;

class BureaucracyCaseTaskController extends Controller
{
    public function __invoke(
        UpdateBureaucracyCaseTaskRequest $request,
        Task $task,
        UpdateCaseTask $updateCaseTask,
    ): RedirectResponse {
        $updateCaseTask->update(
            $request->user(),
            $task,
            TaskStatus::from($request->validated('status')),
        );

        return back();
    }
}
