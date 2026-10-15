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
        Schema::table('plan_items', function (Blueprint $table): void {
            $table->foreignUlid('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['scope_id', 'department_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plan_items', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'department_id', 'month']);
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
