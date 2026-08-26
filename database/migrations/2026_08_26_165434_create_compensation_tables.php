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
        Schema::create('responsibility_areas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->longText('description')->nullable();
            $table->string('kind', 16)->default('salary');
            $table->unsignedSmallInteger('points')->default(0);
            $table->unsignedSmallInteger('minimum_completed_tasks')->default(1);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_id', 'kind', 'is_active']);
        });

        Schema::create('responsibility_area_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('responsibility_area_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->constrained('users')->restrictOnDelete();
            $table->date('active_from');
            $table->date('active_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'active_from', 'active_until']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignUlid('responsibility_area_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignUlid('responsibility_area_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('counts_for_compensation')->default(true);
        });

        Schema::create('compensation_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->decimal('salary_amount', 14, 2);
            $table->unsignedSmallInteger('earned_points')->default(0);
            $table->unsignedSmallInteger('payable_percent')->default(0);
            $table->decimal('bonus_amount', 14, 2)->default(0);
            $table->string('status', 16)->default('open');
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['scope_id', 'user_id', 'month']);
            $table->index(['scope_id', 'month', 'status']);
        });

        Schema::create('compensation_area_results', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('compensation_period_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('responsibility_area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('area_name');
            $table->string('area_kind', 16);
            $table->unsignedSmallInteger('area_points');
            $table->unsignedSmallInteger('minimum_completed_tasks');
            $table->unsignedSmallInteger('completed_tasks')->default(0);
            $table->boolean('is_qualified')->default(false);
            $table->unsignedSmallInteger('awarded_points')->default(0);
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(['compensation_period_id', 'responsibility_area_id'], 'comp_period_area_unique');
        });

        Schema::create('compensation_result_tasks', function (Blueprint $table): void {
            $table->foreignUlid('compensation_area_result_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('credited_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->primary(['compensation_area_result_id', 'task_id'], 'comp_result_task_primary');
            $table->unique(['task_id', 'credited_user_id'], 'comp_task_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compensation_result_tasks');
        Schema::dropIfExists('compensation_area_results');
        Schema::dropIfExists('compensation_periods');
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsibility_area_id');
            $table->dropColumn('counts_for_compensation');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsibility_area_id');
        });
        Schema::dropIfExists('responsibility_area_assignments');
        Schema::dropIfExists('responsibility_areas');
    }
};
