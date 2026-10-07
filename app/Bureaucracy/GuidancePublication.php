<?php

namespace App\Bureaucracy;

use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\ScoredAction;
use App\Models\Task;
use Illuminate\Support\Collection;

/** One publication boundary for legacy consumers during the v2 cutover. No approval cache. */
final class GuidancePublication
{
    public function __construct(private RuleSourcePolicy $sources) {}

    /** @return Collection<int, Task> */
    public function tasks(): Collection
    {
        return Task::query()->authoritative()->orderBy('key')->get()
            ->filter(fn (Task $task): bool => $this->sources->persistedErrors($task) === []);
    }

    public function allows(?Task $task): bool
    {
        if ($task === null || ! $task->exists) {
            return false;
        }

        // Loaded relations and queued payloads may outlive a review or withdrawal.
        $current = Task::query()->authoritative()->find($task->getKey());

        return $current !== null
            && $current->content_version === $task->content_version
            && hash_equals($this->contentHash($current), $this->contentHash($task))
            && $this->sources->persistedErrors($current) === []
            && $this->sources->persistedErrors($task) === [];
    }

    public function allowsAction(ScoredAction $action, ?int $userId = null): bool
    {
        // This old action has no reviewed assessment behind its duration-only claim.
        if ($action->type === 'permanent_residency_eligible') {
            return false;
        }
        if ($action->type !== 'bureaucracy_task') {
            return true;
        }

        return $this->allowsReference($action->payload, $userId);
    }

    /** Old queued references are historical, not a second decision path. */
    public function allowsReference(?array $reference, ?int $userId = null): bool
    {
        return app(PlanReminderReference::class)->resolve($reference, $userId) !== null;
    }

    public function referenceFor(ScoredAction $action): array
    {
        return array_intersect_key($action->payload, array_flip(['schema_version', 'recipient_id', 'sealed', 'reservation', 'mute_key']));
    }

    public function contentHash(Task $task): string
    {
        $attributes = $task->attributesToArray();
        $content = [];
        foreach (['key', 'title', 'description', 'description_variants', 'type', 'situation', 'eu_filter',
            'applies_if', 'decision_options', 'trigger_event', 'phase', 'depends_on', 'deadline_type',
            'deadline_days', 'deadline_fact_key', 'urgency', 'links', 'documents_required',
            'recurrence_months', 'how_to_steps', 'booking_service_key', 'legal_sources', 'coverage_scope'] as $key) {
            $content[$key] = $attributes[$key] ?? null;
        }

        return hash('sha256', json_encode($this->canonical($content), JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $values @return array<mixed> */
    private function canonical(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonical($value);
            }
        }

        return $values;
    }
}
