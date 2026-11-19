<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HyTrack ERD v1.0 (docs/database/README.md). Column names, types and keys follow the ERD.
 * Service history is never cascaded away: every foreign key restricts deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->increments('staff_id');
            $table->unsignedInteger('user_id')->unique();
            $table->string('full_name', 100);
            $table->string('work_unit', 50);
            $table->foreign('user_id')->references('user_id')->on('users')->restrictOnDelete();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->increments('patient_id');
            $table->unsignedInteger('user_id')->unique();
            $table->string('full_name', 100);
            $table->string('phone_number', 20)->nullable();
            $table->decimal('shared_location_lat', 9, 6)->nullable();
            $table->decimal('shared_location_lng', 9, 6)->nullable();
            $table->dateTime('created_at');
            $table->foreign('user_id')->references('user_id')->on('users')->restrictOnDelete();
        });

        Schema::create('polyclinics', function (Blueprint $table) {
            $table->increments('polyclinic_id');
            $table->string('polyclinic_name', 100);
            $table->string('room_location', 100)->nullable();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->increments('doctor_id');
            $table->unsignedInteger('polyclinic_id');
            $table->string('doctor_name', 100);
            $table->time('schedule_start');
            $table->time('schedule_end');
            $table->string('availability_status', 30);
            $table->string('delay_reason')->nullable();
            $table->unsignedInteger('delay_minutes')->nullable();
            $table->foreign('polyclinic_id')->references('polyclinic_id')->on('polyclinics')->restrictOnDelete();
            // C1 candidate key: lets queues reference a doctor together with the doctor's polyclinic.
            $table->unique(['doctor_id', 'polyclinic_id']);
        });

        Schema::create('queues', function (Blueprint $table) {
            $table->increments('queue_id');
            $table->unsignedInteger('patient_id');
            $table->unsignedInteger('doctor_id');
            $table->unsignedInteger('polyclinic_id');
            $table->date('service_date');
            $table->string('queue_number', 10);
            $table->string('queue_status', 30);
            $table->unsignedInteger('estimated_wait_minutes')->nullable();
            $table->dateTime('recommended_departure_time')->nullable();
            $table->dateTime('checkin_deadline')->nullable();
            $table->dateTime('checkin_at')->nullable();
            $table->unsignedInteger('called_count')->default(0);
            $table->dateTime('last_synced_at')->nullable();
            $table->foreign('patient_id')->references('patient_id')->on('patients')->restrictOnDelete();
            $table->foreign('polyclinic_id')->references('polyclinic_id')->on('polyclinics')->restrictOnDelete();
            // C1: a ticket can never point at a doctor of another polyclinic.
            $table->foreign(['doctor_id', 'polyclinic_id'])
                ->references(['doctor_id', 'polyclinic_id'])->on('doctors')->restrictOnDelete();
            // D04: ticket numbers are issued per doctor per service date; needed for safe numbering.
            $table->unique(['doctor_id', 'service_date', 'queue_number']);
            $table->index(['patient_id', 'queue_status']);
            $table->index(['service_date', 'queue_status']);
        });

        Schema::create('patient_journeys', function (Blueprint $table) {
            $table->increments('journey_id');
            $table->unsignedInteger('queue_id');
            $table->unsignedInteger('updated_by_staff_id')->nullable();
            $table->string('stage_name', 50);
            $table->unsignedInteger('stage_order');
            $table->string('room_location', 100)->nullable();
            $table->string('stage_status', 30);
            $table->text('next_step_instruction')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreign('queue_id')->references('queue_id')->on('queues')->restrictOnDelete();
            $table->foreign('updated_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
            $table->unique(['queue_id', 'stage_order']); // C2
        });

        Schema::create('pharmacy_orders', function (Blueprint $table) {
            $table->increments('pharmacy_order_id');
            $table->unsignedInteger('queue_id');
            $table->unsignedInteger('updated_by_staff_id')->nullable();
            $table->string('prescription_status', 30);
            $table->dateTime('received_at')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('prepared_at')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreign('queue_id')->references('queue_id')->on('queues')->restrictOnDelete();
            $table->foreign('updated_by_staff_id')->references('staff_id')->on('staff')->restrictOnDelete();
        });

        Schema::create('companion_shares', function (Blueprint $table) {
            $table->increments('share_id');
            $table->unsignedInteger('queue_id');
            $table->char('access_token_hash', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at');
            $table->dateTime('created_at');
            $table->foreign('queue_id')->references('queue_id')->on('queues')->restrictOnDelete();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('notification_id');
            $table->unsignedInteger('queue_id');
            $table->unsignedInteger('recipient_patient_id');
            $table->string('trigger_type', 50);
            $table->text('message_body');
            $table->string('action_instruction')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->foreign('queue_id')->references('queue_id')->on('queues')->restrictOnDelete();
            $table->foreign('recipient_patient_id')->references('patient_id')->on('patients')->restrictOnDelete();
            $table->index(['recipient_patient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        $tables = [
            'notifications', 'companion_shares', 'pharmacy_orders', 'patient_journeys',
            'queues', 'doctors', 'polyclinics', 'patients', 'staff',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
