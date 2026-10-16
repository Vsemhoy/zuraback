<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagrams', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('visibility', 16)->default('private');
            $table->unsignedInteger('revision')->default(1);
            $table->longText('xml');
            $table->longText('preview_svg')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scope_id', 'updated_at']);
        });
        Schema::create('diagram_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('diagram_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->longText('xml');
            $table->longText('preview_svg')->nullable();
            $table->timestamps();
            $table->unique(['diagram_id', 'number']);
        });
        Schema::create('diagram_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('diagram_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 32);
            $table->ulid('subject_id');
            $table->timestamps();
            $table->unique(['diagram_id', 'subject_type', 'subject_id'], 'diagram_subject_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagram_attachments');
        Schema::dropIfExists('diagram_revisions');
        Schema::dropIfExists('diagrams');
    }
};
