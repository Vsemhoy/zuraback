<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['scope_id', 'name']);
        });
        foreach (['scope_members', 'projects', 'tasks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignUlid('department_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
        Schema::create('department_project', function (Blueprint $table): void {
            $table->foreignUlid('department_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->primary(['department_id', 'project_id']);
        });
        Schema::create('kpi_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('profile_key', 26);
            $table->string('effective_month', 7);
            $table->json('targets');
            $table->json('items');
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['scope_id', 'profile_key', 'effective_month'], 'kpi_profile_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_profiles');
        Schema::dropIfExists('department_project');
        foreach (['tasks', 'projects', 'scope_members'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('department_id'));
        }
        Schema::dropIfExists('departments');
    }
};
