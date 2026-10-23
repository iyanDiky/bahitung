<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->enum('type', ['cash', 'bank', 'ewallet', 'other']);
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('created_by_member_id');
            $table->foreign(['created_by_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['id', 'household_id']);
            $table->index(['household_id', 'is_archived']);
        });
        Schema::create('wallet_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('wallet_id')->primary();
            $table->unsignedBigInteger('household_id');
            $table->foreign(['wallet_id', 'household_id'])->references(['id', 'household_id'])->on('wallets')->restrictOnDelete();
            $table->decimal('balance', 19, 2)->default('0.00');
            $table->timestampTz('updated_at');
        });
        DB::statement('ALTER TABLE wallet_balances ADD CONSTRAINT wallet_balance_nonnegative CHECK (balance >= 0)');
        Schema::create('operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['opening', 'income', 'expense', 'transfer', 'goal_contribution', 'goal_withdrawal', 'adjustment']);
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->decimal('amount', 19, 2);
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by_member_id');
            $table->foreign(['created_by_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->timestampsTz();
            $table->unique(['id', 'household_id']);
            $table->unique(['household_id', 'idempotency_key']);
            $table->index(['household_id', 'occurred_on', 'type', 'status']);
        });
        DB::statement('ALTER TABLE operations ADD CONSTRAINT operation_amount_positive CHECK (amount > 0)');
        Schema::create('balance_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('wallet_id');
            $table->foreign(['operation_id', 'household_id'])->references(['id', 'household_id'])->on('operations')->restrictOnDelete();
            $table->foreign(['wallet_id', 'household_id'])->references(['id', 'household_id'])->on('wallets')->restrictOnDelete();
            $table->decimal('amount_signed', 19, 2);
            $table->enum('entry_kind', ['original', 'reversal']);
            $table->timestampTz('created_at');
            $table->index(['wallet_id', 'id']);
        });
        DB::statement('ALTER TABLE balance_movements ADD CONSTRAINT movement_nonzero CHECK (amount_signed <> 0)');
        Schema::create('idempotency_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->uuid('key');
            $table->char('payload_hash', 64);
            $table->string('endpoint');
            $table->unsignedSmallInteger('response_status');
            $table->jsonb('response_body');
            $table->timestampTz('created_at');
            $table->unique(['household_id', 'key']);
        });
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_ledger_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Financial history is append-only' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER balance_movements_immutable BEFORE UPDATE OR DELETE ON balance_movements
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_mutation();
            CREATE TRIGGER idempotency_receipts_immutable BEFORE UPDATE OR DELETE ON idempotency_receipts
            FOR EACH ROW EXECUTE FUNCTION reject_ledger_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_receipts');
        Schema::dropIfExists('balance_movements');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('wallet_balances');
        Schema::dropIfExists('wallets');
        DB::statement('DROP FUNCTION IF EXISTS reject_ledger_mutation()');
    }
};
