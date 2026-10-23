<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->char('currency_code', 3)->default('IDR');
            $table->string('timezone', 64)->default('Asia/Makassar');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('household_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['owner', 'admin', 'member', 'viewer']);
            $table->enum('status', ['active', 'suspended', 'removed'])->default('active');
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();
            $table->unique(['household_id', 'user_id']);
            $table->unique(['id', 'household_id']);
            $table->index(['household_id', 'role', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX household_one_active_owner ON household_members (household_id) WHERE role = 'owner' AND status = 'active'");
        DB::statement("ALTER TABLE households ADD CONSTRAINT household_idr_only CHECK (currency_code = 'IDR')");
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_member_id');
            $table->foreign(['actor_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
            $table->string('action', 100);
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('household_members');
        Schema::dropIfExists('households');
    }
};
