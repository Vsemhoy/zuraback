<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facts', function (Blueprint $table): void {
            // Existing records keep the scope visibility agreed for legacy facts.
            $table->string('visibility', 16)->default('scope');
            $table->foreignUlid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('allow_member_editing')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->index(['scope_id', 'visibility', 'created_by']);
        });
        Schema::table('facts', fn (Blueprint $table) => $table->string('visibility', 16)->default('private')->change());
    }

    public function down(): void
    {
        Schema::table('facts', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['scope_id', 'visibility', 'created_by']);
            $table->dropColumn(['visibility', 'project_id', 'allow_member_editing', 'revision']);
        });
    }
};
