<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filer_files', function (Blueprint $table): void {
            $table->string('container', 24)->default('files');
            $table->index(['scope_id', 'container', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('filer_files', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'container', 'id']);
            $table->dropColumn('container');
        });
    }
};
