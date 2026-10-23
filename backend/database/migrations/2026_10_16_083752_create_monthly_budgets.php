<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->date('month_start');
            $table->unsignedBigInteger('copied_from_budget_id')->nullable();
            $table->unsignedBigInteger('created_by_member_id');
            $table->timestampsTz();
            $table->unique(['household_id', 'month_start']);
            $table->unique(['id', 'household_id']);
            $table->foreign(['copied_from_budget_id', 'household_id'])->references(['id', 'household_id'])->on('budgets')->restrictOnDelete();
            $table->foreign(['created_by_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
        });
        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('budget_id');
            $table->unsignedBigInteger('category_id');
            $table->decimal('limit_amount', 19, 2);
            $table->unique(['budget_id', 'category_id']);
            $table->foreign(['budget_id', 'household_id'])->references(['id', 'household_id'])->on('budgets')->restrictOnDelete();
            $table->foreign(['category_id', 'household_id'])->references(['id', 'household_id'])->on('categories')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE budgets ADD CONSTRAINT budget_month_first CHECK (EXTRACT(DAY FROM month_start) = 1)');
        DB::statement('ALTER TABLE budget_lines ADD CONSTRAINT budget_limit_positive CHECK (limit_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
