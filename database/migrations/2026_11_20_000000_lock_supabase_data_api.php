<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supabase exposes every table in the public schema through its Data API (PostgREST) to
 * the anon and authenticated roles. HyTrack talks to Postgres only from Laravel on the
 * server, so those roles get no access at all: grants revoked, row level security on with
 * no policies (Laravel connects as the table owner, which RLS does not restrict), and the
 * same for tables later migrations create. Does nothing on MySQL, SQLite or Postgres
 * servers without these roles (Neon, local).
 */
return new class extends Migration
{
    private const ROLES = 'anon, authenticated';

    public function up(): void
    {
        if (! $this->supabase()) {
            return;
        }

        foreach ($this->tables() as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("REVOKE ALL ON TABLE public.\"{$table}\" FROM ".self::ROLES);
        }
        DB::statement('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM '.self::ROLES);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM '.self::ROLES);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM '.self::ROLES);
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON FUNCTIONS FROM '.self::ROLES);
    }

    /** Access stays closed on rollback: reopening patient data to the public API is never wanted. */
    public function down(): void {}

    private function supabase(): bool
    {
        return DB::getDriverName() === 'pgsql'
            && DB::scalar("SELECT count(*) FROM pg_roles WHERE rolname IN ('anon', 'authenticated')") == 2;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map(fn ($row) => $row->tablename,
            DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"));
    }
};
