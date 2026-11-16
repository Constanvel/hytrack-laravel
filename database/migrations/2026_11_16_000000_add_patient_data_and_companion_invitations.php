<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient data (Figma 27:61–27:179), companion invitations bound to an account and
 * API tokens for the Android app. Additive: existing rows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Family members added by an account holder have no login of their own.
            $table->unsignedInteger('user_id')->nullable()->change();
            $table->unsignedInteger('managed_by_patient_id')->nullable()->after('user_id');
            $table->string('medical_record_number', 20)->nullable()->unique();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable(); // female | male
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('postal_code', 5)->nullable();
            $table->foreign('managed_by_patient_id')->references('patient_id')->on('patients')->restrictOnDelete();
        });

        Schema::table('companion_shares', function (Blueprint $table) {
            $table->unsignedInteger('companion_user_id')->nullable()->after('queue_id');
            $table->string('status', 20)->default('pending')->after('access_token_hash');
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreign('companion_user_id')->references('user_id')->on('users')->restrictOnDelete();
            $table->index(['companion_user_id', 'status']);
        });
        // Links issued before invitations existed were never bound to an account.
        DB::table('companion_shares')->where('is_active', false)->update(['status' => 'revoked']);
        Schema::table('companion_shares', fn (Blueprint $table) => $table->dropColumn('is_active'));

        Schema::table('users', function (Blueprint $table) {
            // SHA-256 of the bearer token used by the Android app (Laravel's token guard).
            $table->char('api_token_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['api_token_hash']);
            $table->dropColumn('api_token_hash');
        });

        Schema::table('companion_shares', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        DB::table('companion_shares')->where('status', '!=', 'pending')->where('status', '!=', 'accepted')
            ->update(['is_active' => false]);
        Schema::table('companion_shares', function (Blueprint $table) {
            $table->dropForeign(['companion_user_id']);
            $table->dropIndex(['companion_user_id', 'status']);
            $table->dropColumn(['companion_user_id', 'status', 'accepted_at', 'revoked_at']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropForeign(['managed_by_patient_id']);
            $table->dropUnique(['medical_record_number']);
            $table->dropColumn(['managed_by_patient_id', 'medical_record_number', 'birth_date', 'gender', 'address', 'city', 'postal_code']);
        });
    }
};
