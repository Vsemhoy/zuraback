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
        Schema::table('tasks', function (Blueprint $table): void {
            $table->boolean('is_agent_delegatable')->default(false)->after('assignee_id')->index();
            $table->foreignUlid('delegated_agent_id')->nullable()->after('is_agent_delegatable')->constrained('users')->nullOnDelete();
            $table->index(['delegated_agent_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['delegated_agent_id']);
            $table->dropIndex(['delegated_agent_id', 'status']);
            $table->dropColumn(['delegated_agent_id', 'is_agent_delegatable']);
        });
    }
};
