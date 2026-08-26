<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scopes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 9)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('default_module', 32)->default('tasker');
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('auto_lock_minutes')->nullable();
            $table->boolean('is_private')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'is_active']);
        });

        Schema::create('scope_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['scope_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scope_members');
        Schema::dropIfExists('scopes');
    }
};
