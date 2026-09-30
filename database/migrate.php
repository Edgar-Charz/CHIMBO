<?php

/**
 * Database setup tool — run from the command line only:
 *
 *   C:\xampp\php\php.exe database\migrate.php            apply new migrations
 *   C:\xampp\php\php.exe database\migrate.php --seed     apply new migrations, then run the seeds
 *   C:\xampp\php\php.exe database\migrate.php --status   show which migrations have run
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$migrator = new Migrator(Database::instance());
$option   = $argv[1] ?? '';

try {
    if ($option === '--status') {
        foreach ($migrator->status() as $file_name => $is_applied) {
            echo ($is_applied ? '[applied] ' : '[pending] ') . $file_name . PHP_EOL;
        }
        exit(0);
    }

    $applied_files = $migrator->runPendingMigrations();
    echo $applied_files
        ? 'Applied: ' . implode(', ', $applied_files) . PHP_EOL
        : 'Database is up to date.' . PHP_EOL;

    if ($option === '--seed') {
        $seed_files = $migrator->runSeeds();
        echo 'Seeds run: ' . implode(', ', $seed_files) . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
