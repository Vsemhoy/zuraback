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
        Schema::table('departments', function (Blueprint $table): void {
            $table->string('code', 3)->nullable();
            $table->text('icon_svg')->nullable();
            $table->unique(['scope_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropUnique(['scope_id', 'code']);
            $table->dropColumn(['code', 'icon_svg']);
        });
    }
};
