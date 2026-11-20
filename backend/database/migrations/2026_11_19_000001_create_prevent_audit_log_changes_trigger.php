<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared("
                CREATE OR REPLACE FUNCTION prevent_audit_log_changes()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_logs records are immutable';
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS prevent_audit_log_changes ON audit_logs;
                CREATE TRIGGER prevent_audit_log_changes
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW
                EXECUTE FUNCTION prevent_audit_log_changes();

                DO $$
                BEGIN
                    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'compliance_app') THEN
                        REVOKE UPDATE, DELETE ON audit_logs FROM compliance_app;
                    END IF;
                END
                $$;
            ");
        } elseif ($driver === 'sqlite') {
            DB::unprepared("
                CREATE TRIGGER IF NOT EXISTS prevent_audit_log_update
                BEFORE UPDATE ON audit_logs
                BEGIN
                    SELECT RAISE(FAIL, 'audit_logs records are immutable');
                END;

                CREATE TRIGGER IF NOT EXISTS prevent_audit_log_delete
                BEFORE DELETE ON audit_logs
                BEGIN
                    SELECT RAISE(FAIL, 'audit_logs records are immutable');
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS prevent_audit_log_changes ON audit_logs;
                DROP FUNCTION IF EXISTS prevent_audit_log_changes();
            ');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS prevent_audit_log_update;
                DROP TRIGGER IF EXISTS prevent_audit_log_delete;
            ');
        }
    }
};
