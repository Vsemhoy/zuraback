<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lore_areas', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('lore_areas')->nullOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['scope_id', 'project_id', 'slug']);
        });

        Schema::create('lore_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->string('color', 16)->nullable();
            $table->timestamps();
            $table->unique(['scope_id', 'slug']);
        });

        Schema::create('lore_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('area_id')->nullable()->constrained('lore_areas')->nullOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('type', 32)->default('decision');
            $table->string('importance', 32)->default('mechanic');
            $table->string('criticality', 32)->default('informational');
            $table->string('visibility', 16)->default('scope');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['scope_id', 'code']);
            $table->index(['scope_id', 'project_id', 'importance']);
        });

        Schema::create('lore_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('lore_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->longText('content');
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('active');
            $table->dateTime('effective_from');
            $table->dateTime('effective_until')->nullable();
            $table->timestamps();
            $table->unique(['lore_entry_id', 'version']);
            $table->index(['lore_entry_id', 'effective_from', 'effective_until'], 'lore_revision_effectivity_idx');
        });

        Schema::create('lore_entry_tag', function (Blueprint $table): void {
            $table->foreignUlid('lore_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('lore_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['lore_entry_id', 'lore_tag_id']);
        });

        Schema::create('lore_stars', function (Blueprint $table): void {
            $table->foreignUlid('lore_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['lore_entry_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lore_stars');
        Schema::dropIfExists('lore_entry_tag');
        Schema::dropIfExists('lore_revisions');
        Schema::dropIfExists('lore_entries');
        Schema::dropIfExists('lore_tags');
        Schema::dropIfExists('lore_areas');
    }
};
