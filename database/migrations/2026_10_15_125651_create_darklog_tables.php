<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('darklogs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            foreach (['description', 'impact', 'workaround', 'resolution', 'decision_reason'] as $field) {
                $table->longText($field)->nullable();
            }
            $table->string('category', 100)->nullable();
            $table->string('kind', 24)->default('incident');
            $table->string('origin', 24)->default('unknown');
            $table->string('severity', 16)->default('medium');
            $table->string('status', 24)->default('new');
            $table->timestamp('expected_end_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['scope_id', 'number']);
            $table->index(['scope_id', 'status', 'created_at']);
        });
        Schema::create('darklog_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('darklog_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['darklog_id', 'starts_at', 'ends_at']);
        });
        Schema::create('darklog_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('darklog_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 24);
            $table->ulid('target_id');
            $table->string('relation', 24);
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['darklog_id', 'target_type', 'target_id', 'relation'], 'darklog_target_relation_unique');
            $table->index(['target_type', 'target_id']);
        });
        Schema::create('darklog_kpi_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('darklog_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('kpi_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['darklog_id', 'user_id', 'kpi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('darklog_kpi_links');
        Schema::dropIfExists('darklog_links');
        Schema::dropIfExists('darklog_periods');
        Schema::dropIfExists('darklogs');
    }
};
