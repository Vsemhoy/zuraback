<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('visibility', 16)->nullable()->after('color');
        });

        // Existing and system-imported projects keep their current shared behaviour.
        // User-facing API creation explicitly defaults to private.
        DB::table('projects')->whereNull('visibility')->update(['visibility' => 'scope']);

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('visibility', 16)->default('scope')->nullable(false)->change();
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['visibility']);
            $table->dropColumn('visibility');
        });
    }
};
