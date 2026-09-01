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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('type', 16)->default('real')->after('id')->index();
            $table->string('status', 16)->default('active')->after('type')->index();
            $table->foreignUlid('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable()->after('email_verified_at');
            $table->json('profile')->nullable()->after('is_active');
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        Schema::table('scope_members', function (Blueprint $table): void {
            $table->string('project_access_mode', 16)->default('all')->after('permissions');
        });

        Schema::create('project_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('contractor_delegations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('operator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('contractor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['scope_id', 'operator_id', 'contractor_id'], 'contractor_delegations_unique');
            $table->index(['contractor_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contractor_delegations');
        Schema::dropIfExists('project_members');

        Schema::table('scope_members', function (Blueprint $table): void {
            $table->dropColumn('project_access_mode');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'status', 'activated_at', 'profile']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
