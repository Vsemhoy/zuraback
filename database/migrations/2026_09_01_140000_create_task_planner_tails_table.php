<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_planner_tails', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
            $table->date('planned_on');
            $table->timestamps();

            $table->unique(['task_id', 'planned_on']);
            $table->index(['scope_id', 'planned_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_planner_tails');
    }
};
