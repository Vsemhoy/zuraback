<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->string('kind', 16)->default('book')->after('scope_id');
            $table->index(['scope_id', 'kind', 'is_archived']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'kind', 'is_archived']);
            $table->dropColumn('kind');
        });
    }
};
