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
        Schema::table('filer_files', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('preview_status', 20)->nullable();
            $table->timestamp('preview_requested_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('filer_files', function (Blueprint $table) {
            $table->dropColumn(['description', 'preview_status', 'preview_requested_at']);
        });
    }
};
