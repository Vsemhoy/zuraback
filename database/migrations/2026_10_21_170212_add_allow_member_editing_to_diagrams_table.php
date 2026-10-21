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
        Schema::table('diagrams', function (Blueprint $table): void {
            $table->boolean('allow_member_editing')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diagrams', function (Blueprint $table): void {
            $table->dropColumn('allow_member_editing');
        });
    }
};
