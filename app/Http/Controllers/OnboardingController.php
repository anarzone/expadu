<?php

namespace App\Http\Controllers;

use App\Enums\DeadlineType;
use App\Http\Requests\OnboardingRequest;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('onboarding', [
            'veedels' => config('veedels'),
            'taskPreviews' => $this->taskPreviews(),
        ]);
    }

    /**
     * The first three actionable tasks per (base branch, EU status) — shown
     * on the onboarding confirmation step so the plan feels real before the
     * user ever sees the bureaucracy page.
     *
     * @return array<string, array{eu: list<array<string, mixed>>, non_eu: list<array<string, mixed>>}>
     */
    private function taskPreviews(): array
    {
        $phaseOrder = ['arrival' => 0, 'first_30_days' => 1, 'settling' => 2, 'ongoing' => 3];
        $branches = ['core', 'eu_employee', 'non_eu_employee', 'student', 'freelancer', 'family_reunification'];

        $tasks = Task::query()
            ->where('is_published', true)
            ->where('type', 'task')
            ->get();

        $previews = [];
        foreach ($branches as $branch) {
            foreach ([true, false] as $isEu) {
                $previews[$branch][$isEu ? 'eu' : 'non_eu'] = $tasks
                    ->filter(fn (Task $t) => in_array($branch, (array) $t->situation, true) && $t->matchesEuStatus($isEu))
                    ->sortBy([
                        fn (Task $a, Task $b) => ($phaseOrder[$a->phase] ?? 9) <=> ($phaseOrder[$b->phase] ?? 9),
                        fn (Task $a, Task $b) => ($a->deadline_days ?? 999) <=> ($b->deadline_days ?? 999),
                    ])
                    ->take(3)
                    ->map(fn (Task $t) => [
                        'title' => $t->title,
                        'meta' => $this->previewMeta($t),
                        'deadline_days' => $t->deadline_type === DeadlineType::DaysSinceArrival ? $t->deadline_days : null,
                    ])
                    ->values()
                    ->all();
            }
        }

        return $previews;
    }

    private function previewMeta(Task $task): ?string
    {
        if ($task->booking_service_key === 'anmeldung') {
            return 'Bürgeramt · walk-in Mon & Wed';
        }

        return match ($task->phase) {
            'arrival' => 'First days',
            'first_30_days' => 'First 30 days',
            'settling' => 'Settling in',
            default => null,
        };
    }

    public function complete(OnboardingRequest $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validated();
        // Long-tail attributes go to the attribute bag (with audit log),
        // not to user columns.
        $entryMode = $validated['entry_mode'] ?? null;
        $housing = $validated['housing_status'] ?? null;
        unset($validated['entry_mode'], $validated['housing_status']);

        $user->update([
            ...$validated,
            'city' => 'Köln', // Cologne-only for now; the field unlocks other NRW cities later
            'onboarded_at' => now(),
        ]);

        if ($entryMode !== null) {
            $user->setProfileAttribute('entry_mode', $entryMode, 'onboarding');
        }
        if ($housing !== null) {
            $user->setProfileAttribute('housing_status', $housing, 'onboarding');
        }

        // Auto-create required Home + Work places if they don't exist
        if (! $user->places()->where('category', 'home')->exists()) {
            $user->places()->create([
                'emoji' => '🏠',
                'name' => 'Home',
                'category' => 'home',
                'sort_order' => 0,
            ]);
        }

        if (! $user->places()->where('category', 'work')->exists()) {
            $user->places()->create([
                'emoji' => '💼',
                'name' => 'Work',
                'category' => 'work',
                'sort_order' => 1,
            ]);
        }

        return redirect()->route('dashboard');
    }
}
