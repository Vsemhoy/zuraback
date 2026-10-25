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
        Schema::create('content_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 96);
            $table->string('description')->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['scope_id', 'name']);
            $table->index(['scope_id', 'is_archived', 'sort_order']);
        });
        Schema::table('event_sections', function (Blueprint $table): void {
            $table->foreignUlid('parent_id')->nullable()->constrained('event_sections')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->index(['scope_id', 'parent_id', 'sort_order']);
        });
        Schema::table('events', function (Blueprint $table): void {
            $table->foreignUlid('category_id')->nullable()->constrained('content_categories')->restrictOnDelete();
            $table->index(['scope_id', 'section_id', 'category_id']);
        });
        Schema::table('books', function (Blueprint $table): void {
            $table->foreignUlid('section_id')->nullable()->constrained('event_sections')->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('content_categories')->restrictOnDelete();
            $table->index(['scope_id', 'kind', 'section_id', 'category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'kind', 'section_id', 'category_id']);
            $table->dropConstrainedForeignId('section_id');
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'section_id', 'category_id']);
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::table('event_sections', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'parent_id', 'sort_order']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('revision');
        });
        Schema::dropIfExists('content_categories');
    }
};
