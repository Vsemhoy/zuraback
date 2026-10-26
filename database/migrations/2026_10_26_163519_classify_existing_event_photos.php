<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep IDs, bytes and paths intact so existing covers and references continue working.
        DB::table('filer_files')->where('container', 'files')->where('category', 'event')
            ->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])
            ->whereIn('id', DB::table('filer_attachments')->where('subject_type', 'event')->select('filer_file_id'))
            ->update(['container' => 'event_photos']);
    }

    public function down(): void
    {
        // Classification is preserved; the preceding schema rollback removes the column.
    }
};
