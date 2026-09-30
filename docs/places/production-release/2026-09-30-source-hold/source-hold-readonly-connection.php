<?php

use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;

$target = $connect('expadu_staging');
$target->beginTransaction();
$target->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$target->exec("SET LOCAL statement_timeout='30s'");
$target->exec("SET LOCAL lock_timeout='5s'");
$target->exec("SET LOCAL idle_in_transaction_session_timeout='120s'");
labCheck($target->query('SELECT current_database()')->fetchColumn() === 'expadu_staging', 'Wrong target');
labCheck($target->query('SHOW transaction_read_only')->fetchColumn() === 'on', 'Read-only required');
$identityBefore = $target->query('SELECT current_user AS role,pg_backend_pid() AS pid,txid_current_snapshot()::text AS snapshot')->fetch(PDO::FETCH_ASSOC);
labCheck($identityBefore['role'] === getenv('DB_USERNAME'), 'Wrong audit role');

final class SourceHoldSelectOnlyConnection extends PostgresConnection
{
    public function statement($query, $bindings = [])
    {
        throw new DomainException('Audit cannot execute statements.');
    }

    public function affectingStatement($query, $bindings = [])
    {
        throw new DomainException('Audit cannot affect records.');
    }

    public function unprepared($query)
    {
        throw new DomainException('Audit cannot execute unprepared SQL.');
    }

    public function beginTransaction()
    {
        throw new DomainException('Audit cannot begin transactions.');
    }

    public function commit()
    {
        throw new DomainException('Audit cannot commit transactions.');
    }

    public function rollBack($toLevel = null)
    {
        throw new DomainException('Audit cannot alter transactions.');
    }
}

$name = 'source_hold_staging_readonly';
$auditConfig = ['name' => $name, 'driver' => 'pgsql', 'database' => 'expadu_staging', 'prefix' => '', 'url' => null];
config(['database.connections.'.$name => $auditConfig]);
$connection = new SourceHoldSelectOnlyConnection($target, 'expadu_staging', '', $auditConfig);
$connection->setReadPdo($target);
DB::extend($name, static fn () => $connection);
$connection = DB::connection($name);
DB::forgetExtension($name);
config(['database.connections.'.$name => null]);
$connection->unsetEventDispatcher();
$connection->disableQueryLog();
$denyReconnect = static function (): never {
    throw new DomainException('Audit cannot reconnect.');
};
$connection->setReconnector($denyReconnect);
$manager = app('db');
$manager->setReconnector($denyReconnect);
$syntheticId = -9223372036854770006;
$queryCount = 0;
$connection->beforeExecuting(static function (string $sql, array $bindings, $current) use ($target, $syntheticId, &$queryCount): void {
    labCheck($target->inTransaction() && $current->getRawPdo() === $target && $current->getRawReadPdo() === $target, 'Audit left its guarded snapshot');
    if (! preg_match('/^\s*(SELECT|WITH)\b/i', $sql) || str_contains($sql, ';')
        || preg_match('/\b(insert|update|delete|create|alter|drop|truncate|copy|call|do|vacuum|grant|revoke|lock|set|reset|into)\b/i', $sql)
        || preg_match('/\bfor\s+(?:update|share|key\s+share|no\s+key\s+update)\b/i', $sql)
        || preg_match('/\b(pg_advisory\w*|pg_read\w*|pg_write\w*|pg_ls\w*|lo_\w+|dblink\w*|nextval|setval|set_config|pg_notify|pg_terminate_backend|pg_cancel_backend)\s*\(/i', $sql)
        || preg_match('/\b(users|sessions|user_places|user_events|composer_workspaces|composer_plans|jobs|notifications|reviews)\b/i', $sql)) {
        throw new DomainException('Audit permits catalogue SELECTs only.');
    }
    if (str_contains($sql, 'spot_feedback') && (! str_contains($sql, '"spot_feedback"."user_id" = ?') || ! in_array($syntheticId, $bindings, true))) {
        throw new DomainException('Real feedback is outside this audit.');
    }
    $queryCount++;
});
$connection->beforeStartingTransaction(static function (): never {
    throw new DomainException('Audit cannot start transactions.');
});
foreach (DB::getConnections() as $otherName => $otherConnection) {
    if ($otherName !== $name) {
        $otherConnection->beforeExecuting(static function (): never {
            throw new DomainException('Audit cannot mix another catalogue connection.');
        });
    }
}
$guardChecks = 0;
foreach ([static fn () => $connection->statement('UPDATE spots SET is_active=false'), static fn () => $connection->beginTransaction(),
    static fn () => $connection->commit(), static fn () => $connection->rollBack(), static fn () => $connection->reconnect(),
    static fn () => $connection->select('SELECT * FROM users')] as $refusal) {
    try {
        $refusal();
        throw new RuntimeException('Audit safety guard did not refuse.');
    } catch (DomainException) {
        $guardChecks++;
    }
}
labCheck($guardChecks === 6 && $target->inTransaction(), 'Audit guard checks incomplete');
