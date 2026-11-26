<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Figma 47:2 frames 31–37: the doctor confirms the examination and writes the prescription,
 * support services (laboratory, radiology) return results to the referring doctor, and the
 * pharmacy works from the doctor's prescription. Additive: existing rows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A staff account that belongs to a doctor may confirm that doctor's examinations.
        Schema::table('staff', function (Blueprint $table) {
            $table->unsignedInteger('doctor_id')->nullable()->unique();
            $table->foreign('doctor_id')->references('doctor_id')->on('doctors')->restrictOnDelete();
        });

        Schema::create('examinations', function (Blueprint $table) {
            $table->increments('examination_id');
            $table->unsignedInteger('queue_id')->unique();
            $table->unsignedInteger('doctor_id');
            $table->text('notes')->nullable();
            $table->string('allergy_note', 120)->nullable();
            $table->string('pharmacy_note', 255)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedInteger('confirmed_by_staff_id')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign('queue_id')->references('queue_id')->on('queues')->restrictOnDelete();
            $table->foreign('doctor_id')->references('doctor_id')->on('doctors')->restrictOnDelete();
            $table->foreign('confirmed_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->increments('prescription_item_id');
            $table->unsignedInteger('examination_id');
            $table->unsignedSmallInteger('position');
            $table->string('medicine_name', 100);
            $table->string('dosage_form', 60)->nullable();
            $table->string('quantity', 40);
            $table->string('dosage', 100);
            $table->string('timing', 60)->nullable();
            $table->foreign('examination_id')->references('examination_id')->on('examinations')->cascadeOnDelete();
        });

        Schema::create('support_requests', function (Blueprint $table) {
            $table->increments('support_request_id');
            $table->unsignedInteger('queue_id');
            $table->unsignedInteger('requested_by_staff_id');
            $table->string('unit', 30);
            $table->string('test_name', 100);
            $table->string('status', 20);
            $table->text('staff_note')->nullable();
            $table->string('result_path')->nullable();
            $table->string('result_name', 150)->nullable();
            $table->string('result_mime', 100)->nullable();
            $table->dateTime('requested_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('updated_by_staff_id')->nullable();
            $table->foreign('queue_id')->references('queue_id')->on('queues')->restrictOnDelete();
            $table->foreign('requested_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->foreign('updated_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->index(['status', 'requested_at']);
        });

        Schema::table('pharmacy_orders', function (Blueprint $table) {
            $table->json('checks')->nullable();
            $table->dateTime('called_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->string('return_reason')->nullable();
        });

        // Supabase: keep the new medical tables closed to the public Data API (see 2026_11_20).
        if (DB::getDriverName() === 'pgsql'
            && DB::scalar("SELECT count(*) FROM pg_roles WHERE rolname IN ('anon', 'authenticated')") == 2) {
            foreach (['examinations', 'prescription_items', 'support_requests'] as $table) {
                DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("REVOKE ALL ON TABLE public.\"{$table}\" FROM anon, authenticated");
            }
        }
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table) {
            $table->dropColumn(['checks', 'called_at', 'returned_at', 'return_reason']);
        });
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('examinations');
        Schema::table('staff', function (Blueprint $table) {
            $table->dropForeign(['doctor_id']);
            $table->dropUnique(['doctor_id']);
            $table->dropColumn('doctor_id');
        });
    }
};
