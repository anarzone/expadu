<?php

namespace App\Http\Controllers;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\PermanentResidencyEligibility;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\UserTask;
use App\Profile\ProfileEngine;
use App\Services\BuergeramtService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin/local-only demo: render the REAL bureaucracy page as any synthetic
 * expat persona so every case can be eyeballed before release. Nothing is
 * written — the persona is an in-memory User and its cards are in-memory
 * UserTask instances fed to the same BureaucracyController::buildPayload the
 * live page uses, so the demo can never diverge from production rendering.
 */
class BureaucracyDemoController extends Controller
{
    public function __invoke(Request $request, BureaucracyController $page, BuergeramtService $buergeramtService, ProfileEngine $profileEngine, PathGenerator $generator, PermanentResidencyEligibility $eligibility): Response
    {
        abort_unless($request->user()?->is_admin || app()->environment('local'), 403);

        $personas = BureaucracyPersonas::demo();
        $key = (string) $request->query('persona', $personas[0]['key']);
        $persona = collect($personas)->firstWhere('key', $key) ?? $personas[0];

        $user = BureaucracyPersonas::userFor($persona);
        $profile = $profileEngine->build($user);

        // In-memory user_tasks for every published task — buildPayload filters
        // them by applicability exactly like the live page. Set attributes
        // directly (not mass-assign) so is_applicable can't be dropped by the
        // fillable guard and silently sort every card into "not applicable".
        $userTasks = Task::query()
            ->where('is_published', true)
            ->get()
            ->map(function (Task $task): UserTask {
                $userTask = new UserTask;
                $userTask->is_applicable = true;
                $userTask->status = TaskStatus::NotStarted;
                $userTask->setRelation('task', $task);

                return $userTask;
            });

        $payload = $page->buildPayload($user, $profile, $userTasks, $buergeramtService, $profileEngine, $generator, $eligibility);

        return Inertia::render('bureaucracy', [
            ...$payload,
            // Drives the read-only persona switcher + "demo" banner on the page.
            'preview' => [
                'active' => $persona['key'],
                'label' => $persona['label'],
                'personas' => collect($personas)
                    ->map(fn (array $p): array => ['key' => $p['key'], 'label' => $p['label']])
                    ->all(),
            ],
        ]);
    }
}
