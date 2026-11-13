<?php

namespace App\Console\Commands\Security;

use App\Security\SecurityWatch;
use Illuminate\Console\Command;

/** Every ten minutes: alerts the admins when the last hour looks like an attack (SecurityWatch). */
class WatchSecurity extends Command
{
    protected $signature = 'security:watch';

    protected $description = 'Alert the admins when sign-ups, blocked posts, failed sign-ins or email use pass their marks';

    public function handle(SecurityWatch $watch): int
    {
        $alerted = $watch->check();
        $this->info($alerted === [] ? 'All quiet.' : 'Alerted: '.implode(', ', $alerted));

        return self::SUCCESS;
    }
}
