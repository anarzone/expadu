<?php

use App\Jobs\ProcessEventJob;
use App\Models\Event;
use Illuminate\Support\Facades\Queue;

test('translation backfill queues incomplete existing rows once in rate-aware batches', function () {
    Queue::fake();
    $missingTitle = Event::factory()->create([
        'title_en' => null, 'description_en' => 'Done', 'summary_en' => 'Done',
    ]);
    $missingDescription = Event::factory()->create([
        'description' => 'German source', 'title_en' => 'Done', 'description_en' => null, 'summary_en' => 'Done',
    ]);
    Event::factory()->create([
        'title_en' => 'Done', 'description_en' => 'Done', 'summary_en' => 'Done',
    ]);

    $this->artisan('events:backfill-translations --limit=10 --per-minute=1')->assertSuccessful();
    $this->artisan('events:backfill-translations --limit=10 --per-minute=1')->assertSuccessful();

    Queue::assertPushed(ProcessEventJob::class, 2);
    Queue::assertPushed(fn (ProcessEventJob $job): bool => $job->event->is($missingTitle));
    Queue::assertPushed(fn (ProcessEventJob $job): bool => $job->event->is($missingDescription)
        && $job->delay !== null);
});
