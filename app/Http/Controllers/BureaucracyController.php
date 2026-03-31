<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\BuergeramtService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BureaucracyController extends Controller
{
    public function index(Request $request, BuergeramtService $buergeramtService): Response
    {
        $user = $request->user();

        // Tasks — from DB, filtered by user's situation
        $situation = $user->situation?->value;
        $allTasks = Task::query()
            ->when($situation, function ($q) use ($situation) {
                $q->whereJsonContains('situation', $situation);
            })
            ->orderByRaw("CASE urgency WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->get();

        // User's task completion status
        $userTasks = $user->userTasks()->pluck('completed_at', 'task_id');

        $formattedTasks = $allTasks->map(function (Task $task) use ($user, $userTasks) {
            $deadline = $task->computeDeadlineFor($user);
            $daysRemaining = $deadline ? (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false) : null;
            $isCompleted = isset($userTasks[$task->id]) && $userTasks[$task->id] !== null;

            $deadlineUrgency = 'none';
            if ($daysRemaining !== null && ! $isCompleted) {
                if ($daysRemaining < 0) {
                    $deadlineUrgency = 'overdue';
                } elseif ($daysRemaining <= 3) {
                    $deadlineUrgency = 'critical';
                } elseif ($daysRemaining <= 7) {
                    $deadlineUrgency = 'urgent';
                } elseif ($daysRemaining <= 14) {
                    $deadlineUrgency = 'approaching';
                } else {
                    $deadlineUrgency = 'on_track';
                }
            }

            return [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'urgency' => $task->urgency->value,
                'phase' => $task->phase,
                'deadline_type' => $task->deadline_type->value,
                'deadline_days' => $task->deadline_days,
                'documents_required' => $task->documents_required ?? [],
                'links' => $task->links ?? [],
                'completed_at' => $userTasks[$task->id] ?? null,
                'absolute_deadline' => $deadline?->toDateString(),
                'days_remaining' => $daysRemaining,
                'deadline_urgency' => $deadlineUrgency,
            ];
        });

        $completedCount = $formattedTasks->whereNotNull('completed_at')->count();
        $totalCount = $formattedTasks->count();

        // Slots
        $slots = $buergeramtService->checkSlots();
        $monitors = $user->slotMonitors()
            ->where('is_active', true)
            ->pluck('office_id')
            ->all();

        return Inertia::render('bureaucracy', [
            'dbTasks' => $formattedTasks,
            'taskProgress' => [
                'completed' => $completedCount,
                'total' => $totalCount,
                'percent' => $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0,
            ],
            'slots' => $slots,
            'monitors' => $monitors,
            'bookingServices' => collect(BuergeramtService::SERVICES)->map(fn ($s, $key) => [
                'key' => $key,
                'name' => $s['name'],
                'name_en' => $s['name_en'],
                'emoji' => $s['emoji'],
                'duration' => $s['duration'],
                'url' => BuergeramtService::BOOKING_URLS[$s['category']].'&service='.$s['uid'],
            ])->values(),
        ]);
    }
}
