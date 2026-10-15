<?php
declare(strict_types=1);

// Only for isolated ZAD Sync staging. Executes before migrate --force.
// Never copies, modifies, or queries Production intentionally.
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function stopStaging(string $why): never {
    fwrite(STDERR, "STAGING DB GUARD - BLOCK: {$why}\n");
    exit(42);
}

if (!$app->environment('staging')) {
    stopStaging('APP_ENV must be exactly staging; migrations not started.');
}

$expectedHost = 'dpg-db783kqj9qps73bdatn0-a';
$expectedDb = 'zad_staging';

try {
    $connection = $app['db']->connection();
    $driver = strtolower((string) $connection->getDriverName());
    $configuredHost = strtolower(trim((string) $connection->getConfig('host')));
    $configuredDb = (string) $connection->getDatabaseName();

    if ($driver !== 'pgsql') {
        stopStaging('DB driver is not pgsql.');
    }
    if ($configuredHost !== $expectedHost || $configuredDb !== $expectedDb) {
        stopStaging('Connection host/database is not the pinned Staging target.');
    }

    // Read only. Confirms which database the actual runtime connection reaches.
    $row = $connection->selectOne('SELECT current_database() AS db_name');
    if ((string) ($row->db_name ?? '') !== $expectedDb) {
        stopStaging('Connected PostgreSQL database is not zad_staging.');
    }
} catch (\Throwable $e) {
    stopStaging('Database connection/bootstrap failed; migrations blocked.');
}

echo "STAGING DB GUARD: PASS - actual PostgreSQL connection is isolated staging.\n";