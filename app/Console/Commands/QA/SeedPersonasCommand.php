<?php

namespace App\Console\Commands\QA;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\PathGenerator;
use App\Enums\Situation;
use App\Models\User;
use App\Profile\Interest;
use Illuminate\Console\Command;
use Throwable;

/**
 * Provisions a fleet of QA demo accounts on a deployed environment — one
 * pre-onboarded account per App\Bureaucracy\BureaucracyPersonas::demo()
 * persona, plus an admin — so a human can log in and walk the REAL
 * onboarding + bureaucracy flow as every expat type without registering a
 * fresh account or hand-crafting profile attributes for each one.
 *
 * Every account is upserted by email (safe to re-run), pre-verified and
 * pre-onboarded, so it lands straight on the dashboard; `user:reset-journey
 * <email>` sends any one of them back through onboarding on demand.
 *
 * SAFETY: this writes real rows, so it refuses to run unless config('app.url')
 * resolves to a known local/staging host, or --force is passed. Staging runs
 * APP_ENV=production, so the guard checks the URL host rather than the
 * environment name.
 */
class SeedPersonasCommand extends Command
{
    protected $signature = 'qa:seed-personas
        {--password=password : Shared password for all seeded accounts}
        {--fresh : Delete each account\'s existing user_tasks for a clean slate}
        {--force : Bypass the environment host guard}';

    protected $description = 'Provision one pre-onboarded QA demo account per bureaucracy persona (+ an admin)';

    /**
     * Hosts this command may run against without --force.
     *
     * @var list<string>
     */
    private const ALLOWED_HOSTS = ['127.0.0.1', 'localhost', 'app.staging.expadu.com'];

    public function __construct(private PathGenerator $paths)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->hostIsAllowed()) {
            $host = $this->currentHost();
            $this->error("Refusing to seed QA personas against host \"{$host}\" — only ".implode(', ', self::ALLOWED_HOSTS).' are allowed. Pass --force to override.');

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        $fresh = (bool) $this->option('fresh');

        $rows = [];
        foreach (BureaucracyPersonas::demo() as $persona) {
            $user = $this->seedPersona($persona, $password, $fresh);
            $rows[] = ['Email' => $user->email, 'Persona' => $persona['label'], 'Password' => $password, 'Role' => 'Persona'];
        }

        $admin = $this->seedAdmin($password);
        $rows[] = ['Email' => $admin->email, 'Persona' => 'Admin', 'Password' => $password, 'Role' => 'Admin'];

        $this->newLine();
        $this->table(['Email', 'Persona', 'Password', 'Role'], $rows);

        $this->info("To replay ONBOARDING as any account: php artisan user:reset-journey <email> (then log in — you'll land on onboarding).");

        return self::SUCCESS;
    }

    /**
     * Upsert one pre-onboarded, pre-verified persona account and materialise
     * its bureaucracy path.
     *
     * @param  array<string, mixed>  $persona
     */
    private function seedPersona(array $persona, string $password, bool $fresh): User
    {
        // Reuse the engine's own attribute-bag builder rather than
        // re-deriving entry_mode/housing_status/license_country/life-event
        // dates here — it stays correct for free as BureaucracyPersonas
        // evolves. userFor() never persists anything; only the array it
        // computes is used below.
        $profileAttributes = BureaucracyPersonas::userFor($persona)->profile_attributes;
        $planned = (bool) ($persona['planned'] ?? false);

        $user = User::firstOrNew(['email' => "qa+{$persona['key']}@expadu.test"]);
        // email_verified_at is deliberately guarded (see database/seeders/
        // E2ETestUserSeeder.php) — updateOrCreate() silently drops it, which
        // would leave every seeded account stuck behind the verify-email
        // wall. forceFill bypasses mass-assignment guarding for the whole
        // payload instead.
        $user->forceFill([
            'name' => "QA {$persona['label']}",
            'password' => bcrypt($password),
            'email_verified_at' => now(),
            'situation' => $persona['situation']->value,
            'is_eu' => $persona['is_eu'],
            'bureaucracy_path' => $persona['path'],
            'arrival_date' => $planned ? null : now()->subDays(10)->toDateString(),
            'veedel' => 'Altstadt-Nord',
            'city' => 'Köln',
            'german_level' => null,
            'has_deutschlandticket' => false,
            'interests' => $this->defaultInterests(),
            'profile_attributes' => $profileAttributes,
            'onboarded_at' => now(),
        ])->save();

        $this->ensurePlaces($user);

        if ($fresh) {
            $user->userTasks()->delete();
        }

        try {
            $this->paths->ensure($user);
        } catch (Throwable $e) {
            $this->warn("Could not materialise the bureaucracy path for {$persona['label']} ({$user->email}): {$e->getMessage()}");
        }

        return $user;
    }

    /**
     * Upsert the admin account that unlocks /bureaucracy/demo and the
     * Filament /admin panel on staging.
     */
    private function seedAdmin(string $password): User
    {
        $user = User::firstOrNew(['email' => 'qa+admin@expadu.test']);
        $user->forceFill([
            'name' => 'QA Admin',
            'password' => bcrypt($password),
            'email_verified_at' => now(),
            'situation' => Situation::EuEmployee->value,
            'is_eu' => true,
            'veedel' => 'Altstadt-Nord',
            'city' => 'Köln',
            'interests' => $this->defaultInterests(),
            'onboarded_at' => now(),
            'is_admin' => true,
        ])->save();

        return $user;
    }

    /**
     * Create the required Home + Work places if they don't exist yet —
     * mirrors OnboardingController::complete() so a seeded account looks
     * exactly like one that just finished onboarding for real.
     */
    private function ensurePlaces(User $user): void
    {
        $user->places()->firstOrCreate(
            ['category' => 'home'],
            ['emoji' => '🏠', 'name' => 'Home', 'sort_order' => 0],
        );

        $user->places()->firstOrCreate(
            ['category' => 'work'],
            ['emoji' => '💼', 'name' => 'Work', 'sort_order' => 1],
        );
    }

    /**
     * The first Interest::MIN_SELECT values — a valid default selection so
     * seeded accounts satisfy onboarding's interest-count expectations.
     *
     * @return list<string>
     */
    private function defaultInterests(): array
    {
        return array_slice(
            array_map(fn (Interest $interest) => $interest->value, Interest::cases()),
            0,
            Interest::MIN_SELECT,
        );
    }

    private function hostIsAllowed(): bool
    {
        return in_array($this->currentHost(), self::ALLOWED_HOSTS, true);
    }

    private function currentHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
