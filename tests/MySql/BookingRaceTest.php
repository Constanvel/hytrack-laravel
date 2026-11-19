<?php

namespace Tests\MySql;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Polyclinic;
use App\Models\Queue;
use App\Models\User;
use App\Support\HospitalTime;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency on MySQL (row locks are what is being tested; SQLite cannot show it).
 * Runs against its own database, hytrack_laravel_test, never hytrack_laravel:
 *
 *   php artisan test --testsuite=MySQL
 *
 * Skipped when MySQL is not running.
 */
class BookingRaceTest extends TestCase
{
    private const DATABASE = 'hytrack_laravel_test';

    protected function setUp(): void
    {
        parent::setUp();

        $mysql = ['host' => env('DB_HOST', '127.0.0.1'), 'port' => env('DB_PORT', '3306'), 'username' => env('DB_USERNAME', 'root'), 'password' => env('DB_PASSWORD', '')];
        try {
            (new PDO("mysql:host={$mysql['host']};port={$mysql['port']}", $mysql['username'], $mysql['password']))
                ->exec('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $e) {
            $this->markTestSkipped('MySQL tidak berjalan: '.$e->getMessage());
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [...config('database.connections.mysql'), ...$mysql, 'url' => null, 'database' => self::DATABASE],
        ]);
        DB::purge('mysql');
        Artisan::call('migrate', ['--force' => true]);

        // Start every run from empty tables, in this test database only.
        $this->assertSame(self::DATABASE, DB::connection()->getDatabaseName());
        Schema::disableForeignKeyConstraints();
        foreach (['notifications', 'companion_shares', 'pharmacy_orders', 'patient_journeys', 'queues', 'doctors', 'polyclinics', 'patients', 'staff', 'users'] as $table) {
            DB::table($table)->truncate();
        }
        Schema::enableForeignKeyConstraints();
    }

    public function test_two_patients_racing_for_the_last_slot_get_one_ticket(): void
    {
        $doctor = $this->doctor();
        $patients = [$this->patient(), $this->patient()];

        $results = $this->race(array_map(fn (Patient $patient) => [$patient, $doctor], $patients), quota: 1);

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok']));
        $this->assertContains('Kuota dokter pada tanggal ini sudah penuh.', array_column($results, 'message'));
        $this->assertSame(1, Queue::count());
    }

    public function test_one_patient_double_submitting_gets_one_ticket(): void
    {
        $patient = $this->patient();
        // Two different doctors, so only the patient lock stands between the two requests.
        $results = $this->race([[$patient, $this->doctor()], [$patient, $this->doctor()]], quota: 30);

        $this->assertCount(1, array_filter($results, fn (array $result) => $result['ok']));
        $this->assertContains('Anda sudah memiliki satu kunjungan aktif.', array_column($results, 'message'));
        $this->assertSame(1, $patient->queues()->count());
    }

    /** @param list<array{Patient, Doctor}> $attempts */
    private function race(array $attempts, int $quota): array
    {
        // Workers boot (several seconds on Windows) and connect first; then all start at once.
        $barrier = storage_path('framework/testing/race-'.bin2hex(random_bytes(4)));
        mkdir($barrier, 0777, true);
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_DATABASE' => self::DATABASE,
            'HYTRACK_DAILY_QUOTA' => (string) $quota,
            'CACHE_STORE' => 'array',
        ];
        $processes = array_map(function (array $attempt, int $worker) use ($barrier, $env) {
            [$patient, $doctor] = $attempt;
            $process = new Process(
                [PHP_BINARY, base_path('tests/MySql/book-worker.php'), (string) $patient->patient_id, (string) $doctor->doctor_id, HospitalTime::today(), $barrier, (string) $worker],
                base_path(),
                $env,
                timeout: 120,
            );
            $process->start();

            return $process;
        }, $attempts, array_keys($attempts));

        $waitUntil = microtime(true) + 90;
        while (count(glob("{$barrier}/ready-*")) < count($attempts) && microtime(true) < $waitUntil) {
            usleep(10000);
        }
        touch("{$barrier}/go");

        $results = array_map(function (Process $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true) + ['message' => null];
        }, $processes);
        array_map('unlink', glob("{$barrier}/*"));
        rmdir($barrier);

        return $results;
    }

    private function doctor(): Doctor
    {
        // Practice ends at midnight so the race can run at any time of day.
        return Doctor::factory()->create([
            'polyclinic_id' => Polyclinic::factory()->create()->polyclinic_id,
            'schedule_start' => '23:58:00',
            'schedule_end' => '23:59:00',
        ]);
    }

    private function patient(): Patient
    {
        return Patient::factory()->create(['user_id' => User::factory()->create()->user_id]);
    }
}
