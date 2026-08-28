<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scopes', function (Blueprint $table): void {
            $table->string('task_prefix', 10)->default('TSK')->after('slug');
            $table->unsignedBigInteger('next_task_number')->default(1)->after('task_prefix');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('key', 10)->nullable()->after('title');
            $table->unsignedBigInteger('next_task_number')->default(1)->after('key');
            $table->unique(['scope_id', 'key'], 'projects_scope_key_unique');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('number')->nullable()->after('parent_id');
            $table->string('task_key', 32)->nullable()->after('number');
            $table->unique(['scope_id', 'task_key'], 'tasks_scope_key_unique');
        });

        Schema::create('task_key_aliases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->string('task_key', 32);
            $table->timestamps();

            $table->unique(['scope_id', 'task_key']);
            $table->unique(['task_id', 'task_key']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'sort_order']);
            $table->index(['assignee_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_key_aliases');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique('tasks_scope_key_unique');
            $table->dropColumn(['number', 'task_key']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique('projects_scope_key_unique');
            $table->dropColumn(['key', 'next_task_number']);
        });

        Schema::table('scopes', function (Blueprint $table): void {
            $table->dropColumn(['task_prefix', 'next_task_number']);
        });
    }
};
