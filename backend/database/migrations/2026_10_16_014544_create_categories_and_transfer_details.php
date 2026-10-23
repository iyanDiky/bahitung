<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->enum('type', ['income', 'expense']);
            $table->boolean('is_archived')->default(false);
            $table->timestampsTz();
            $table->unique(['id', 'household_id']);
        });
        DB::statement('CREATE UNIQUE INDEX categories_household_type_name_unique ON categories (household_id, type, LOWER(name))');
        Schema::table('operations', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable();
            $table->foreign(['category_id', 'household_id'])->references(['id', 'household_id'])->on('categories')->restrictOnDelete();
        });
        Schema::create('transfer_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('operation_id')->primary();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('source_wallet_id');
            $table->unsignedBigInteger('destination_wallet_id');
            $table->decimal('principal_amount', 19, 2);
            $table->decimal('fee_amount', 19, 2)->default('0.00');
            $table->unsignedBigInteger('fee_category_id')->nullable();
            $table->foreign(['operation_id', 'household_id'])->references(['id', 'household_id'])->on('operations')->restrictOnDelete();
            $table->foreign(['source_wallet_id', 'household_id'])->references(['id', 'household_id'])->on('wallets')->restrictOnDelete();
            $table->foreign(['destination_wallet_id', 'household_id'])->references(['id', 'household_id'])->on('wallets')->restrictOnDelete();
            $table->foreign(['fee_category_id', 'household_id'])->references(['id', 'household_id'])->on('categories')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE transfer_details ADD CONSTRAINT valid_transfer CHECK (source_wallet_id <> destination_wallet_id AND principal_amount > 0 AND fee_amount >= 0 AND (fee_amount = 0 OR fee_category_id IS NOT NULL))');
        DB::unprepared('CREATE TRIGGER transfer_details_immutable BEFORE UPDATE OR DELETE ON transfer_details FOR EACH ROW EXECUTE FUNCTION reject_ledger_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_details');
        Schema::table('operations', function (Blueprint $table): void {
            $table->dropForeign(['category_id', 'household_id']);
            $table->dropColumn('category_id');
        });
        Schema::dropIfExists('categories');
    }
};
