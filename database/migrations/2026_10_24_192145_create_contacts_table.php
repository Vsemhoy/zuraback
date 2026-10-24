<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users');
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('visibility', 16)->default('private');
            $table->boolean('allow_member_editing')->default(false);
            $table->string('kind', 24)->default('person');
            $table->string('name', 255);
            foreach (['nickname', 'company', 'role', 'phone', 'email'] as $field) {
                $table->string($field)->nullable();
            }
            $table->date('birthday_on')->nullable();
            $table->date('met_at')->nullable();
            $table->text('met_context')->nullable();
            $table->timestamp('last_contact_at')->nullable();
            $table->longText('note_md')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scope_id', 'visibility', 'created_by']);
            $table->index(['scope_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
