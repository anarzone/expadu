<?php

namespace App\Http\Controllers;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\Cases\CasePlanPresenter;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\PermanentResidencyEligibility;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\ProfileEngine;
use App\Services\BuergeramtService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    public function __invoke(Request $request, BureaucracyController $page, BuergeramtService $buergeramtService, ProfileEngine $profileEngine, PathGenerator $generator, PermanentResidencyEligibility $eligibility, CaseMatcher $matcher, CasePlanComposer $composer, CasePlanPresenter $presenter): Response
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

        $case = $this->syntheticCase($user, $persona);
        $casePlan = $presenter->preview(
            $case,
            $composer->compose($case, $result = $matcher->match($case)),
            $result->coverageState->value,
        );

        return Inertia::render('bureaucracy', [
            ...$payload,
            'casePlan' => $casePlan,
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

    /**
     * An unsaved case whose user and confirmed facts live only in memory.
     *
     * The verified plan used to be missing from this page entirely: `casePlan`
     * is added outside buildPayload, and CurrentCasePlan bootstraps a real row,
     * takes a lock and writes a snapshot — none of which a preview may do. So
     * the demo showed the unreviewed catalogue and nothing else, while claiming
     * it could never diverge from production rendering.
     *
     * Nothing here is saved. The facts mirror the confirmed state
     * ScenarioFactSynchronizer persists for the same persona, so the two agree
     * on what the case knows.
     *
     * @param  array<string, mixed>  $persona
     */
    private function syntheticCase(User $user, array $persona): BureaucracyCase
    {
        $case = new BureaucracyCase;
        $case->setRelation('user', $user);

        $facts = collect($persona['facts'] ?? [])
            ->values()
            ->map(function (mixed $value, int $index) use ($persona): BureaucracyCaseFact {
                $fact = new BureaucracyCaseFact;
                $fact->id = $index + 1;
                $fact->key = array_keys($persona['facts'])[$index];
                // Raw, not stringified: the cast JSON-encodes it, so a boolean
                // must stay a boolean and an hour count an integer. Casting
                // them to "true" and "25" made conditions that compare types
                // stop matching, and the persona quietly lost its route.
                $fact->value = $value;
                $fact->state = 'confirmed';
                $fact->source = 'qa_persona:'.$persona['key'];
                $fact->confirmed_at = Carbon::now();

                return $fact;
            });

        $case->setRelation('facts', new EloquentCollection($facts->all()));

        return $case;
    }
}
