<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->char('month', 7);
            $table->string('person_id', 26)->nullable();
            $table->string('person_name')->nullable();
            $table->string('timezone', 64);
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->json('snapshot');
            $table->timestamps();
            $table->index(['scope_id', 'created_by', 'month']);
        });
        Schema::create('monthly_task_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('expected_result')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'month']);
            $table->index(['scope_id', 'month', 'assignee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_task_plans');
        Schema::dropIfExists('monthly_reports');
    }
};
