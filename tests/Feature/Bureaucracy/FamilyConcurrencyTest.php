<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyInvitation;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

test('family commands finish consistently when invitations are acted on concurrently', function (string $otherOperation) {
    expect(app()->environment('testing'))->toBeTrue();
    expect((bool) getenv('BUREAUCRACY_TEST_MANIFEST') || getenv('CI') === 'true')->toBeTrue();
    User::query()->count(); // Establish the normal isolated test schema first.
    $original = DB::getDefaultConnection();
    config(['database.connections.family_fixture' => config('database.connections.'.$original)]);
    DB::setDefaultConnection('family_fixture');
    $users = [];
    $processes = [];
    try {
        $helper = User::factory()->onboarded()->create();
        $subject = User::factory()->onboarded()->create();
        $users = [$helper->id, $subject->id];
        $firstInvite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_plan']);
        $otherInvite = $otherOperation === 'accept'
            ? app(ManageDelegation::class)->invite($subject, app(EnsureAccountHolder::class)->person($subject)->workspace, $helper->email, ['view_plan'])
            : $firstInvite;
        $operations = [
            ['accept', $subject->id, $firstInvite],
            [$otherOperation, $helper->id, $otherInvite],
        ];
        $inputs = [];
        foreach ($operations as [$mode, $userId, $invite]) {
            $input = new InputStream;
            $process = new Process([PHP_BINARY, base_path('tests/Support/FamilyConcurrencyWorker.php'), $mode, (string) $userId, (string) $invite['invitation']->id], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.'.$original.'.database'),
                'BUREAUCRACY_TEST_INVITATION_TOKEN' => $invite['token'],
            ], $input, 15);
            $processes[] = $process;
            $inputs[] = $input;
            $process->start();
            expect($process->waitUntil(fn ($type, $output) => str_contains($output, 'locked')))->toBeTrue();
        }
        foreach ($inputs as $input) {
            $input->write("continue\n");
            $input->close();
        }
        do {
            $running = false;
            foreach ($processes as $process) {
                $process->checkTimeout();
                $running = $process->isRunning() || $running;
            }
            usleep(10000);
        } while ($running);
        foreach ($processes as $process) {
            expect($process->getExitCode())->toBe(0)
                ->and($process->getOutput())->not->toContain('database_or_worker_error');
        }
        expect($processes[0]->getOutput())->toContain('accepted');
        expect($processes[1]->getOutput())->toContain($otherOperation === 'accept' ? 'accepted' : 'already_accepted');
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $people = BureaucracyPerson::query()->whereIn('account_user_id', $users)->pluck('id')->all();
        $workspaces = BureaucracyWorkspace::query()->whereIn('owner_user_id', $users)->pluck('id')->all();
        BureaucracyInvitation::query()->whereIn('inviter_user_id', $users)->delete();
        BureaucracyOutboxEvent::query()->where('aggregate_type', 'person')->whereIn('aggregate_id', $people)->delete();
        BureaucracyPerson::query()->whereKey($people)->delete();
        BureaucracyWorkspace::query()->whereKey($workspaces)->delete();
        User::query()->whereKey($users)->delete();
        DB::disconnect('family_fixture');
        DB::setDefaultConnection($original);
    }
})->with(['cancel', 'accept']);
