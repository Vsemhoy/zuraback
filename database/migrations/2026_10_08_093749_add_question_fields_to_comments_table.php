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
        Schema::table('comments', function (Blueprint $table) {
            $table->string('kind', 16)->default('comment');
            $table->boolean('is_answered')->default(false);
            $table->index(['commentable_type', 'commentable_id', 'kind', 'is_answered'], 'comments_questions_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('comments_questions_index');
            $table->dropColumn(['kind', 'is_answered']);
        });
    }
};
