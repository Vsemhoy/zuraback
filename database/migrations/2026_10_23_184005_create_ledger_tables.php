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
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('creator_name');
            $table->string('title', 160);
            $table->string('type', 20)->default('bank');
            $table->string('currency', 3)->default('RUB');
            $table->bigInteger('opening_minor')->default(0);
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#7c6a46');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['scope_id', 'project_id', 'sort_order']);
        });
        Schema::create('ledger_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('project_id')->constrained()->restrictOnDelete();
            $table->string('title', 120);
            $table->string('kind', 20)->default('both');
            $table->string('color', 7)->default('#7c6a46');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['scope_id', 'project_id', 'sort_order']);
        });
        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignUlid('target_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignUlid('category_id')->nullable()->constrained('ledger_categories')->restrictOnDelete();
            $table->foreignUlid('exploitation_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('creator_name');
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->date('occurred_on');
            $table->string('status', 20)->default('planned');
            $table->string('title');
            $table->longText('note_md')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['scope_id', 'idempotency_key']);
            $table->index(['scope_id', 'occurred_on', 'id']);
            $table->index(['account_id', 'status', 'occurred_on']);
            $table->index(['target_account_id', 'status', 'occurred_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_categories');
        Schema::dropIfExists('ledger_accounts');
    }
};
