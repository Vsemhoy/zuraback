<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->unsignedTinyInteger('version_depth')->default(25)->after('structure_mode');
        });

        Schema::table('book_pages', function (Blueprint $table): void {
            $table->foreignUlid('editing_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('editing_started_at')->nullable()->after('editing_by');
        });

        Schema::create('book_page_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained('book_pages')->cascadeOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['page_id', 'version_number']);
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_page_versions');
        Schema::table('book_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('editing_by');
            $table->dropColumn('editing_started_at');
        });
        Schema::table('books', function (Blueprint $table): void {
            $table->dropColumn('version_depth');
        });
    }
};
