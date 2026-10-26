<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->string('status', 20)->default('posted')->change();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->string('status', 20)->default('planned')->change();
        });
    }
};
