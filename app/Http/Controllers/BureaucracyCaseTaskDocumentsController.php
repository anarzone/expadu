<?php

namespace App\Http\Controllers;

use App\Bureaucracy\Cases\UpdateCaseTask;
use App\Http\Requests\UpdateBureaucracyCaseTaskDocumentsRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;

class BureaucracyCaseTaskDocumentsController extends Controller
{
    public function __invoke(
        UpdateBureaucracyCaseTaskDocumentsRequest $request,
        Task $task,
        UpdateCaseTask $updateCaseTask,
    ): RedirectResponse {
        $updateCaseTask->updateDocuments(
            $request->user(),
            $task,
            $request->validated('documents_checked'),
        );

        return back();
    }
}
