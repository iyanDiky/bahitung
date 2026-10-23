<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('household_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('household_id')->constrained()->restrictOnDelete();
            $table->string('invited_email');
            $table->enum('target_role', ['member', 'viewer']);
            $table->char('token_hash', 64)->unique();
            $table->enum('status', ['pending', 'accepted', 'revoked'])->default('pending');
            $table->timestampTz('expires_at');
            $table->unsignedBigInteger('invited_by_member_id');
            $table->foreign(['invited_by_member_id', 'household_id'])->references(['id', 'household_id'])->on('household_members')->restrictOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['household_id', 'invited_email', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX household_pending_invitation ON household_invitations (household_id, invited_email) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('household_invitations');
    }
};
