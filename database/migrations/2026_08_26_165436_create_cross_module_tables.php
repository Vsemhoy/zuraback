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
        Schema::create('entity_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->ulid('source_id');
            $table->string('target_type', 32);
            $table->ulid('target_id');
            $table->string('relation', 32)->default('related');
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'target_type', 'target_id', 'relation'], 'entity_links_unique');
            $table->index(['scope_id', 'source_type', 'source_id'], 'entity_links_source_idx');
            $table->index(['scope_id', 'target_type', 'target_id'], 'entity_links_target_idx');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('slug', 96);
            $table->string('color', 9)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->unique(['scope_id', 'slug']);
        });

        Schema::create('taggables', function (Blueprint $table): void {
            $table->foreignUlid('tag_id')->constrained()->cascadeOnDelete();
            $table->string('taggable_type', 32);
            $table->ulid('taggable_id');
            $table->timestamps();

            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
            $table->index(['taggable_type', 'taggable_id']);
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->string('commentable_type', 32);
            $table->ulid('commentable_id');
            $table->foreignUlid('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->longText('content');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'comments_entity_time_idx');
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 32);
            $table->ulid('subject_id');
            $table->string('action', 48);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scope_id', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at'], 'activity_subject_time_idx');
            $table->index(['actor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('entity_links');
    }
};
