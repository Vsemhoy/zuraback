<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filer_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('category', 24);
            $table->string('visibility', 16)->default('private');
            $table->string('disk', 32);
            $table->string('path')->unique();
            $table->string('mime', 160);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
            $table->index(['scope_id', 'category', 'created_at']);
        });
        Schema::create('filer_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('filer_file_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 24);
            $table->ulid('subject_id');
            $table->timestamps();
            $table->unique(['filer_file_id', 'subject_type', 'subject_id'], 'filer_attachment_unique');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filer_attachments');
        Schema::dropIfExists('filer_files');
    }
};
