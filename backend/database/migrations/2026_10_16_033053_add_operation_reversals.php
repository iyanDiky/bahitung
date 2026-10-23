<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('correction_of_id')->nullable()->unique();
            $table->foreign(['correction_of_id', 'household_id'])->references(['id', 'household_id'])->on('operations')->restrictOnDelete();
        });
        Schema::table('balance_movements', function (Blueprint $table): void {
            $table->unique(['id', 'household_id']);
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            $table->foreign(['reversal_of_id', 'household_id'])->references(['id', 'household_id'])->on('balance_movements')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE balance_movements ADD CONSTRAINT reversal_reference_required CHECK ((entry_kind = 'original' AND reversal_of_id IS NULL) OR (entry_kind = 'reversal' AND reversal_of_id IS NOT NULL))");
        Schema::create('operation_reversals', function (Blueprint $table): void {
            $table->unsignedBigInteger('operation_id')->primary();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('created_by_member_id');
            $table->text('reason');
            $table->timestampTz('created_at');
            $table->foreign(['operation_id', 'household_id'])->references(['id', 'household_id'])->on('operations')->restrictOnDelete();
            $table->foreign(['created_by_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
        CREATE TRIGGER operation_reversals_immutable BEFORE UPDATE OR DELETE ON operation_reversals FOR EACH ROW EXECUTE FUNCTION reject_ledger_mutation();
        CREATE FUNCTION validate_reversal_movement() RETURNS trigger AS $$
        BEGIN
            IF NEW.entry_kind = 'reversal' AND NOT EXISTS (
                SELECT 1 FROM balance_movements m WHERE m.id = NEW.reversal_of_id
                AND m.household_id = NEW.household_id AND m.operation_id = NEW.operation_id
                AND m.wallet_id = NEW.wallet_id AND m.entry_kind = 'original'
                AND m.amount_signed = -NEW.amount_signed
            ) THEN
                RAISE EXCEPTION 'Reversal must exactly negate original movement' USING ERRCODE = '23514';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER reversal_movement_valid BEFORE INSERT ON balance_movements FOR EACH ROW EXECUTE FUNCTION validate_reversal_movement();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER reversal_movement_valid ON balance_movements');
        DB::statement('DROP FUNCTION validate_reversal_movement()');
        Schema::dropIfExists('operation_reversals');
        DB::statement('ALTER TABLE balance_movements DROP CONSTRAINT reversal_reference_required');
        Schema::table('balance_movements', function (Blueprint $table): void {
            $table->dropForeign(['reversal_of_id', 'household_id']);
            $table->dropUnique(['reversal_of_id']);
            $table->dropColumn('reversal_of_id');
            $table->dropUnique(['id', 'household_id']);
        });
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['correction_of_id', 'household_id']);
            $table->dropUnique(['correction_of_id']);
            $table->dropColumn('correction_of_id');
        });
    }
};
