<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactRegistry;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyPlanSnapshot;
use App\Models\UserTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class CasePlanPresenter
{
    /** @var list<string> */
    private const SectionKeys = [
        'current_status',
        'do_now',
        'next',
        'coming_up',
        'options',
        'waiting',
        'information_needed',
        'not_covered',
    ];

    public function __construct(private FactRegistry $factRegistry) {}

    /**
     * @return array<string, mixed>
     */
    public function present(
        BureaucracyCase $case,
        BureaucracyPlanSnapshot $snapshot,
        ?BureaucracyCaseQuestion $question,
    ): array {
        $rawSections = is_array($snapshot->sections) ? $snapshot->sections : [];
        $taskStates = $this->taskStates($case, $rawSections);
        $sections = [];

        foreach (self::SectionKeys as $sectionKey) {
            $items = $rawSections[$sectionKey] ?? [];
            $sections[$sectionKey] = collect(is_array($items) ? $items : [])
                ->map(fn (mixed $item): mixed => is_array($item)
                    ? $this->withTaskState($item, $taskStates)
                    : $item)
                ->values()
                ->all();
        }

        return [
            'coverage_state' => $snapshot->coverage_state,
            'generated_at' => $snapshot->generated_at?->toIso8601String(),
            'reassessment_at' => $snapshot->reassessment_at?->toIso8601String(),
            'sections' => $sections,
            'next_question' => $this->question($question),
        ];
    }

    /**
     * @param  array<string, mixed>  $sections
     * @return Collection<string, UserTask>
     */
    private function taskStates(BureaucracyCase $case, array $sections): Collection
    {
        $keys = collect($sections)
            ->flatten(1)
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            return collect();
        }

        return UserTask::query()
            ->where('user_id', $case->user_id)
            ->whereHas('task', fn ($query) => $query->whereIn('key', $keys))
            ->with('task:id,key')
            ->get()
            ->filter(fn (UserTask $userTask): bool => is_string($userTask->task?->key))
            ->keyBy(fn (UserTask $userTask): string => $userTask->task->key);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, UserTask>  $taskStates
     * @return array<string, mixed>
     */
    private function withTaskState(array $item, Collection $taskStates): array
    {
        $key = $item['key'] ?? null;

        if (! is_string($key)) {
            return $item;
        }

        $userTask = $taskStates->get($key);
        $status = $userTask?->status ?? TaskStatus::NotStarted;

        return [
            ...$item,
            'status' => $status->value,
            'status_label' => $status->label(),
            'completed_at' => $userTask?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function question(?BureaucracyCaseQuestion $question): ?array
    {
        if (! $question instanceof BureaucracyCaseQuestion) {
            return null;
        }

        $definition = $this->factRegistry->definition($question->fact_key);

        return [
            'id' => $question->getKey(),
            'type' => $definition->type,
            'question' => $definition->question,
            'why' => $definition->why,
            'sensitivity' => $definition->sensitivity,
            'attempt' => $question->attempt,
            'options' => collect($definition->options)
                ->map(fn (string $value): array => [
                    'value' => $value,
                    'label' => $this->optionLabel($definition, $value),
                ])
                ->values()
                ->all(),
        ];
    }

    private function optionLabel(FactDefinition $definition, string $value): string
    {
        return match ($value) {
            'national_d_visa' => 'National D visa',
            'standard_work_permit' => 'Standard work permit',
            'blue_card', 'blue_card_pending' => 'EU Blue Card'.($value === 'blue_card_pending' ? ' pending' : ''),
            'family_reunification' => 'Family reunification permit',
            'settlement_permit_18c' => 'Settlement permit (§18c)',
            'family_reunification_permit' => 'Family reunification permit',
            'renew_current_title' => 'Renew my current title',
            'settlement_permit' => 'Apply for a settlement permit',
            'understand_options' => 'Understand my options',
            'd_visa' => 'D visa',
            'visa_free' => 'Visa-free entry',
            'has_permit' => 'Already have a permit',
            'eu' => 'EU citizen',
            'non_eu' => 'Non-EU citizen',
            'a1', 'a2', 'b1', 'b2', 'c1', 'c2' => Str::upper($value),
            default => Str::headline($value),
        };
    }
}
