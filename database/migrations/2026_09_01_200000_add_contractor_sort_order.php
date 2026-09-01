<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scope_members', function (Blueprint $table): void {
            $table->integer('sort_order')->default(0)->after('book_access_mode');
            $table->index(['scope_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('scope_members', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'is_active', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
