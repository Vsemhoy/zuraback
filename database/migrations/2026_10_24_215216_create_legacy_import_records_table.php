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
        Schema::create('legacy_import_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->ulid('batch_id')->index();
            $table->string('source', 64);
            $table->string('source_type', 32);
            $table->ulid('source_id');
            $table->string('target_type', 32);
            $table->ulid('target_id');
            $table->string('source_hash', 64);
            $table->longText('snapshot');
            $table->timestamps();
            $table->unique(['source', 'source_type', 'source_id'], 'legacy_source_unique');
            $table->unique(['target_type', 'target_id'], 'legacy_target_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legacy_import_records');
    }
};
