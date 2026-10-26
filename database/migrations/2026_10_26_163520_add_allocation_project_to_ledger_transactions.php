<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->foreignUlid('allocation_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->index(['scope_id', 'allocation_project_id', 'occurred_on'], 'ledger_allocation_date');
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->dropIndex('ledger_allocation_date');
            $table->dropConstrainedForeignId('allocation_project_id');
        });
    }
};
