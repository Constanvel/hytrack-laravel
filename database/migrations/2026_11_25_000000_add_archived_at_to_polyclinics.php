<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Hapus" on a polyclinic archives it: it leaves the lists and booking, while its doctors
 * and every queue keep pointing at it, so visit history and reports stay intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('polyclinics', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('polyclinics', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
