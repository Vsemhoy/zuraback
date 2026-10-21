<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('thing_types')->insertOrIgnore([
            'id' => (string) Str::ulid(), 'scope_id' => null, 'code' => 'workplace',
            'name' => 'Рабочее место', 'schema' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep the shared type on rollback: existing equipment may already reference it.
    }
};
