<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->decimal('target_amount', 19, 2);
            $table->date('target_date')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('created_by_member_id');
            $table->foreign(['created_by_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'household_id']);
            $table->index(['household_id', 'is_archived']);
        });
        DB::statement('ALTER TABLE goals ADD CONSTRAINT goal_target_positive CHECK (target_amount > 0)');
        Schema::create('goal_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('goal_id')->primary();
            $table->unsignedBigInteger('household_id');
            $table->foreign(['goal_id', 'household_id'])->references(['id', 'household_id'])->on('goals')->restrictOnDelete();
            $table->decimal('balance', 19, 2)->default('0.00');
            $table->timestampTz('updated_at');
        });
        DB::statement('ALTER TABLE goal_balances ADD CONSTRAINT goal_balance_nonnegative CHECK (balance >= 0)');
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('goal_id')->nullable();
            $table->foreign(['goal_id', 'household_id'])->references(['id', 'household_id'])->on('goals')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE operations ADD CONSTRAINT goal_operation_reference CHECK ((type IN ('goal_contribution', 'goal_withdrawal') AND goal_id IS NOT NULL AND category_id IS NULL) OR (type NOT IN ('goal_contribution', 'goal_withdrawal') AND goal_id IS NULL))");
        Schema::table('balance_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet_id')->nullable()->change();
            $table->unsignedBigInteger('goal_id')->nullable();
            $table->foreign(['goal_id', 'household_id'])->references(['id', 'household_id'])->on('goals')->restrictOnDelete();
            $table->index(['goal_id', 'id']);
        });
        DB::statement('ALTER TABLE balance_movements ADD CONSTRAINT movement_one_account CHECK ((wallet_id IS NOT NULL)::integer + (goal_id IS NOT NULL)::integer = 1)');
        $this->reversalFunction(true);
    }

    private function reversalFunction(bool $goals): void
    {
        $account = $goals ? 'm.wallet_id IS NOT DISTINCT FROM NEW.wallet_id AND m.goal_id IS NOT DISTINCT FROM NEW.goal_id' : 'm.wallet_id = NEW.wallet_id';
        DB::unprepared("CREATE OR REPLACE FUNCTION validate_reversal_movement() RETURNS trigger AS $$
        BEGIN
            IF NEW.entry_kind = 'reversal' AND NOT EXISTS (
                SELECT 1 FROM balance_movements m WHERE m.id = NEW.reversal_of_id
                AND m.household_id = NEW.household_id AND m.operation_id = NEW.operation_id
                AND {$account} AND m.entry_kind = 'original' AND m.amount_signed = -NEW.amount_signed
            ) THEN
                RAISE EXCEPTION 'Reversal must exactly negate original movement' USING ERRCODE = '23514';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;");
    }

    public function down(): void
    {
        if (DB::table('balance_movements')->whereNotNull('goal_id')->exists()) {
            throw new RuntimeException('Cannot remove goal ledger while financial history exists.');
        }
        $this->reversalFunction(false);
        DB::statement('ALTER TABLE balance_movements DROP CONSTRAINT movement_one_account');
        Schema::table('balance_movements', function (Blueprint $table): void {
            $table->dropForeign(['goal_id', 'household_id']);
            $table->dropIndex(['goal_id', 'id']);
            $table->dropColumn('goal_id');
            $table->unsignedBigInteger('wallet_id')->nullable(false)->change();
        });
        DB::statement('ALTER TABLE operations DROP CONSTRAINT goal_operation_reference');
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['goal_id', 'household_id']);
            $table->dropColumn('goal_id');
        });
        Schema::dropIfExists('goal_balances');
        Schema::dropIfExists('goals');
    }
};
