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
        Schema::create('event_types', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('description')->nullable();
            $table->string('color', 9)->nullable();
            $table->string('background_color', 9)->nullable();
            $table->string('icon', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['scope_id', 'is_archived', 'sort_order']);
        });

        Schema::create('event_sections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 96);
            $table->string('slug', 160)->nullable();
            $table->string('description')->nullable();
            $table->string('color', 9)->nullable();
            $table->string('background_color', 9)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('visibility', 16)->default('private');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->json('decor')->nullable();
            $table->json('seo')->nullable();
            $table->timestamps();

            $table->unique(['scope_id', 'slug']);
            $table->index(['scope_id', 'is_archived', 'sort_order']);
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('type_id')->nullable()->constrained('event_types')->nullOnDelete();
            $table->foreignUlid('section_id')->nullable()->constrained('event_sections')->nullOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignUlid('root_id')->nullable()->constrained('events')->nullOnDelete();
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('format', 16)->default('markdown');
            $table->string('language', 16)->nullable();
            $table->string('code_language', 32)->nullable();
            $table->string('status', 24)->default('draft');
            $table->string('relation_type', 24)->nullable();
            $table->string('location')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_expert')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_id', 'status', 'occurred_at']);
            $table->index(['section_id', 'status', 'occurred_at']);
            $table->index(['root_id', 'occurred_at']);
            $table->index(['scope_id', 'is_pinned', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_sections');
        Schema::dropIfExists('event_types');
    }
};
