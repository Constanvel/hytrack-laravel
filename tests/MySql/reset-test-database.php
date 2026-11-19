<?php

/*
 * Prepares the separate MySQL test database for the operational end-to-end test:
 * creates it if needed, applies the migrations, empties its tables and seeds demo data.
 * Refuses to touch any database whose name does not end in "_test", so the application
 * database (hytrack_laravel) is never reset by tests.
 *
 *   DB_DATABASE=hytrack_laravel_test php tests/MySql/reset-test-database.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$config = config('database.connections.mysql');
if (config('database.default') !== 'mysql' || ! str_ends_with((string) $config['database'], '_test')) {
    fwrite(STDERR, "Refusing to reset: DB_CONNECTION must be mysql and DB_DATABASE must end in _test.\n");
    exit(2);
}

(new PDO("mysql:host={$config['host']};port={$config['port']}", $config['username'], $config['password']))
    ->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

Artisan::call('migrate', ['--force' => true]);

Schema::disableForeignKeyConstraints();
foreach (['notifications', 'companion_shares', 'pharmacy_orders', 'patient_journeys', 'queues', 'doctors', 'polyclinics', 'patients', 'staff', 'users', 'sessions', 'cache'] as $table) {
    DB::table($table)->truncate();
}
Schema::enableForeignKeyConstraints();

Artisan::call('db:seed', ['--force' => true]);
echo "Test database {$config['database']} ready.\n";
