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
        Schema::create('content_navigation_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 16);
            $table->ulid('source_id');
            $table->string('target_type', 16);
            $table->ulid('target_id');
            $table->string('label', 160)->nullable();
            $table->json('region')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scope_id', 'source_type', 'source_id'], 'content_navigation_source');
            $table->index(['scope_id', 'target_type', 'target_id'], 'content_navigation_target');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_navigation_links');
    }
};
