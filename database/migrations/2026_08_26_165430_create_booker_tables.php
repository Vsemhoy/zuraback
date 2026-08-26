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
        Schema::create('book_spaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('slug', 160)->nullable();
            $table->string('visibility', 16)->default('private');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scope_id', 'slug']);
            $table->index(['scope_id', 'is_archived', 'sort_order']);
        });

        Schema::create('books', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('space_id')->nullable()->constrained('book_spaces')->nullOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('slug', 160)->nullable();
            $table->longText('description')->nullable();
            $table->string('structure_mode', 16)->default('tree');
            $table->string('visibility', 16)->default('private');
            $table->string('cover_color', 24)->nullable();
            $table->text('cover_svg_url')->nullable();
            $table->longText('cover_svg_text')->nullable();
            $table->json('export_settings')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['scope_id', 'slug']);
            $table->index(['scope_id', 'space_id', 'is_archived']);
        });

        Schema::create('book_pages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('book_pages')->nullOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('slug', 160)->nullable();
            $table->string('visibility', 16)->default('private');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['book_id', 'slug']);
            $table->index(['book_id', 'parent_id', 'sort_order']);
        });

        Schema::create('book_block_groups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained('book_pages')->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->ulid('master_block_id')->nullable();
            $table->string('type', 32)->default('markdown');
            $table->string('role', 32)->default('content');
            $table->string('visibility', 16)->default('private');
            $table->boolean('is_hidden_by_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['page_id', 'sort_order']);
            $table->index(['page_id', 'role', 'is_hidden_by_default']);
        });

        Schema::create('book_blocks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('group_id')->constrained('book_block_groups')->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->text('title')->nullable();
            $table->longText('content')->nullable();
            $table->json('payload')->nullable();
            $table->longText('search_text')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['group_id', 'version_number']);
            $table->index(['group_id', 'status']);
        });

        Schema::table('book_block_groups', function (Blueprint $table): void {
            $table->foreign('master_block_id')->references('id')->on('book_blocks')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_block_groups', function (Blueprint $table): void {
            $table->dropForeign(['master_block_id']);
        });
        Schema::dropIfExists('book_blocks');
        Schema::dropIfExists('book_block_groups');
        Schema::dropIfExists('book_pages');
        Schema::dropIfExists('books');
        Schema::dropIfExists('book_spaces');
    }
};
