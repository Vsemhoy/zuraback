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
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->unsignedInteger('interest_rate_bps')->nullable();
            $table->date('interest_start_on')->nullable();
            $table->date('interest_end_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropColumn(['interest_rate_bps', 'interest_start_on', 'interest_end_on']);
        });
    }
};
