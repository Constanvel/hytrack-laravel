<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational audit trail on the ticket itself, so one row in `queues` answers
 * "who booked, who confirmed the arrival, who cancelled and why".
 * Additive only: every new column is nullable and existing rows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dateTime('booked_at')->nullable()->after('queue_status');
            // Set when staff booked for a walk-in patient; null when the patient booked in the app.
            $table->unsignedInteger('booked_by_staff_id')->nullable()->after('booked_at');
            // Staff member who confirmed the arrival, and how: qr | code (typed number).
            $table->unsignedInteger('checkin_by_staff_id')->nullable()->after('checkin_at');
            $table->string('checkin_method', 20)->nullable()->after('checkin_by_staff_id');
            $table->dateTime('no_show_at')->nullable()->after('called_count');
            $table->unsignedInteger('no_show_by_staff_id')->nullable()->after('no_show_at');
            $table->dateTime('cancelled_at')->nullable()->after('no_show_by_staff_id');
            // Null with a cancelled_at means the patient cancelled the visit.
            $table->unsignedInteger('cancelled_by_staff_id')->nullable()->after('cancelled_at');
            $table->string('cancel_reason')->nullable()->after('cancelled_by_staff_id');

            $table->foreign('booked_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->foreign('checkin_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->foreign('no_show_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->foreign('cancelled_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->index(['service_date', 'polyclinic_id']);
        });

        // Backfill check-ins recorded before this migration from the check-in stage.
        $checkIns = DB::table('patient_journeys')
            ->where('stage_order', 1)
            ->whereNotNull('updated_by_staff_id')
            ->pluck('updated_by_staff_id', 'queue_id');

        foreach ($checkIns as $queueId => $staffId) {
            DB::table('queues')->where('queue_id', $queueId)->whereNotNull('checkin_at')
                ->whereNull('checkin_by_staff_id')
                ->update(['checkin_by_staff_id' => $staffId]);
        }
    }

    public function down(): void
    {
        Schema::table('queues', function (Blueprint $table) {
            $table->dropForeign(['booked_by_staff_id']);
            $table->dropForeign(['checkin_by_staff_id']);
            $table->dropForeign(['no_show_by_staff_id']);
            $table->dropForeign(['cancelled_by_staff_id']);
            $table->dropIndex(['service_date', 'polyclinic_id']);
            $table->dropColumn([
                'booked_at', 'booked_by_staff_id', 'checkin_by_staff_id', 'checkin_method',
                'no_show_at', 'no_show_by_staff_id', 'cancelled_at', 'cancelled_by_staff_id', 'cancel_reason',
            ]);
        });
    }
};
