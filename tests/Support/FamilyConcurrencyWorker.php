<?php

use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\IsolatedTestEnvironment;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
IsolatedTestEnvironment::checkConfiguredManifest($app['config']);
if (! $app->environment('testing') || (! getenv('BUREAUCRACY_TEST_MANIFEST') && getenv('CI') !== 'true')) {
    throw new RuntimeException('Concurrency worker requires isolated test services.');
}
Http::preventStrayRequests();
DB::statement("SET lock_timeout = '5s'");
$mode = $argv[1];
$actor = User::query()->findOrFail((int) $argv[2]);
$invitationId = (int) $argv[3];
$paused = false;
DB::listen(function ($query) use ($mode, &$paused): void {
    $lock = str_contains($query->sql, 'for update') || str_contains($query->sql, 'for no key update');
    $table = $mode === 'cancel' ? '"users"' : '"bureaucracy_invitations"';
    if (! $paused && $lock && str_contains($query->sql, $table)) {
        $paused = true;
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('Test barrier was not released.');
        }
    }
});
try {
    $command = $app->make(ManageDelegation::class);
    if ($mode === 'cancel') {
        $command->cancelInvitation($actor, BureaucracyInvitation::query()->findOrFail($invitationId));
        $result = 'cancelled';
    } else {
        $command->accept($actor, (string) getenv('BUREAUCRACY_TEST_INVITATION_TOKEN'), ['view_plan']);
        $result = 'accepted';
    }
} catch (ValidationException) {
    $result = 'already_accepted';
} catch (AuthorizationException) {
    $result = 'denied';
} catch (Throwable $error) {
    $result = 'database_or_worker_error:'.$error::class.':'.$error->getCode();
}
fwrite(STDOUT, $result."\n");
