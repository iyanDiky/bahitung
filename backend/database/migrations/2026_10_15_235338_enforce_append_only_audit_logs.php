<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_audit_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Audit records are append-only' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS reject_audit_mutation()');
    }
};
