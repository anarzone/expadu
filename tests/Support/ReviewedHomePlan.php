<?php

namespace Tests\Support;

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\Task;
use App\Models\User;

/** Synthetic reviewed clocks for consumer tests, never actual legal approval. */
final class ReviewedHomePlan
{
    public static function activate(User $user, array $rules, array $facts): array
    {
        $user->update(['city' => 'Köln']);
        $case = app(EnsureAccountHolder::class)->dossier($user);
        foreach ($facts as $key => $value) {
            app(RecordFactChange::class)->execute($user, $case->person, $key, $value, null, $case->fresh()->fact_version);
        }
        $tasks = [];
        $mapping = [];
        foreach ($rules as $key => $attributes) {
            $task = Task::factory()->approvedFixture()->create([
                'key' => $key, 'title' => 'Synthetic reviewed task', 'type' => 'task', 'applies_if' => [],
                'depends_on' => [], 'documents_required' => [], 'how_to_steps' => [], 'links' => [],
                'deadline_type' => 'days_since_arrival', 'deadline_days' => 14, ...$attributes,
            ])->fresh();
            $tasks[$key] = $task;
            $mapping[$key] = ['process_id' => $key, 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial',
                'temporal_policy' => ['kind' => 'legal_due', 'version' => 'synthetic-test.1',
                    'content_version' => $task->content_version, 'reviewed_by' => $task->reviewed_by,
                    'verified_at' => $task->verified_at->toDateString(),
                    'source_url' => collect($task->legal_sources)->firstWhere('kind', 'primary')['url']]];
            if ($task->deadline_type->value === 'none') {
                unset($mapping[$key]['temporal_policy']);
            }
        }
        $store = app(CatalogueReleaseStore::class);
        $release = $store->stage(app(CatalogueCompiler::class)->compile(array_values($tasks), $mapping));
        $store->activate($release->id, null);

        return ['case' => $case->fresh(), 'tasks' => $tasks, 'plan' => app(AccountHolderPlan::class)->for($user)];
    }
}
