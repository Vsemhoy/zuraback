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
        Schema::create('projects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->longText('result')->nullable();
            $table->string('status', 32)->default('planning');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->date('started_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_id', 'status', 'due_on']);
            $table->index(['scope_id', 'is_pinned', 'sort_order']);
        });

        Schema::create('tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->longText('result')->nullable();
            $table->string('status', 32)->default('todo');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('tracked_seconds')->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_id', 'status', 'due_at']);
            $table->index(['scope_id', 'assignee_id', 'status']);
            $table->index(['project_id', 'status', 'sort_order']);
            $table->index(['parent_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('projects');
    }
};
