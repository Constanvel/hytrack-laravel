<?php

namespace App\Console\Commands;

use Database\Seeders\ClinicalStaffSeeder;
use Illuminate\Console\Command;

/**
 * Brings an existing database up to date without touching its data: new migrations, then the
 * clinical room accounts a demo database seeded before them is missing. Vercel runs this on
 * each new instance (HYTRACK_AUTO_MIGRATE), so a deploy needs no manual database step.
 */
class UpgradeHytrack extends Command
{
    protected $signature = 'hytrack:upgrade';

    protected $description = 'Jalankan migrasi baru dan tambahkan akun ruang klinis demo yang belum ada';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--class' => ClinicalStaffSeeder::class, '--force' => true]);

        return self::SUCCESS;
    }
}
