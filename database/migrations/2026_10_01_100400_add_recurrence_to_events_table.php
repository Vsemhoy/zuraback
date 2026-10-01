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
        Schema::table('events', function (Blueprint $table) {
            $table->string('recurrence_frequency', 16)->nullable();
            $table->date('recurrence_until')->nullable();
            $table->string('recurrence_timezone', 64)->default('UTC');
            $table->foreignUlid('recurrence_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['scope_id', 'recurrence_frequency', 'starts_at'], 'events_recurrence_start_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_recurrence_start_index');
            $table->dropForeign(['recurrence_user_id']);
            $table->dropColumn(['recurrence_frequency', 'recurrence_until', 'recurrence_timezone', 'recurrence_user_id']);
        });
    }
};
