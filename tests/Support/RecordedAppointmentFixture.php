<?php

namespace Tests\Support;

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final class RecordedAppointmentFixture
{
    public static function for(User $user, CarbonInterface $start): void
    {
        $case = app(EnsureAccountHolder::class)->dossier($user);
        $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.recorded_appointment',
            'applies_if' => [], 'deadline_type' => 'none', 'depends_on' => [], 'type' => 'task',
            'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
        $store = app(CatalogueReleaseStore::class);
        $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
            'process_id' => 'fixture.recorded_appointment', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
        ]]));
        $store->activate($release->id, null);
        $process = app(ReconcileProcesses::class)->execute($user, $case->person, 'de-nrw-cologne')[0];
        app(RecordProcessEvent::class)->execute($user, $process, 'appointment_recorded', [
            'appointment_id' => (string) Str::uuid(), 'starts_at' => $start->toIso8601String(), 'timezone' => 'Europe/Berlin',
            'duration_minutes' => 25, 'location' => ['label' => 'My recorded meeting place', 'lat' => 50.9416, 'lng' => 7.0009],
        ], 1, (string) Str::uuid());
    }
}
