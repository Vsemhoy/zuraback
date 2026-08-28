<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_blockers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('task_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->text('resolution_required');
            $table->foreignUlid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('responsible_text')->nullable();
            $table->string('previous_status', 32);
            $table->foreignUlid('blocked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('blocked_at');
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'resolved_at']);
            $table->index(['responsible_user_id', 'resolved_at']);
            $table->index('next_review_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_blockers');
    }
};
