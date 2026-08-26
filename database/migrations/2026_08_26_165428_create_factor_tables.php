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
        Schema::create('facts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('label', 160);
            $table->longText('value');
            $table->string('format', 24)->default('text');
            $table->string('language', 16)->nullable();
            $table->string('unit', 32)->nullable();
            $table->text('context')->nullable();
            $table->json('search_keywords')->nullable();
            $table->string('kind', 32)->default('other');
            $table->string('display_mode', 24)->default('plain');
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_expert')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['scope_id', 'kind', 'is_expert']);
            $table->index(['scope_id', 'is_pinned', 'sort_order']);
            $table->index(['scope_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facts');
    }
};
