<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rolling back the archive must never reactivate a revoked credential.
        DB::table('personal_access_tokens')->whereNotNull('revoked_at')->update(['expires_at' => now()]);
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
