<?php

/*
 * One booking attempt in its own PHP process, for BookingRaceTest. Each worker boots Laravel
 * and opens its database connection, reports "ready", then waits for the shared "go" file,
 * so that all workers enter QueueService::book at the same moment.
 * Usage: php tests/MySql/book-worker.php <patient_id> <doctor_id> <Y-m-d> <barrier dir> <worker no>
 */

use App\Exceptions\QueueRuleException;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\QueueService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Never against the application database.
if (! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    fwrite(STDERR, "Refusing to run outside a *_test database.\n");
    exit(2);
}

[, $patientId, $doctorId, $date, $barrier, $worker] = $argv;

$patient = Patient::findOrFail($patientId);
$doctor = Doctor::findOrFail($doctorId);
DB::select('select 1'); // connection open before the start signal
touch("{$barrier}/ready-{$worker}");

$deadline = microtime(true) + 60;
while (! file_exists("{$barrier}/go") && microtime(true) < $deadline) {
    usleep(200);
}

try {
    $queue = app(QueueService::class)->book($patient, $doctor, $date);
    echo json_encode(['ok' => true, 'number' => $queue->queue_number]);
} catch (QueueRuleException $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
