<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignUlid('customer_id')->nullable()->after('assignee_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('books', function (Blueprint $table): void {
            $table->foreignUlid('project_id')->nullable()->after('space_id')->constrained()->nullOnDelete();
        });
        Schema::table('scope_members', function (Blueprint $table): void {
            $table->string('book_access_mode', 24)->default('none')->after('project_access_mode');
        });
    }

    public function down(): void
    {
        Schema::table('scope_members', fn (Blueprint $table) => $table->dropColumn('book_access_mode'));
        Schema::table('books', fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
    }
};
