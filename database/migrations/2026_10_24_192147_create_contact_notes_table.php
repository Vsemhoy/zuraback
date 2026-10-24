<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users');
            $table->string('title', 255)->nullable();
            $table->longText('content');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['contact_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_notes');
    }
};
