<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('compensation_result_tasks');
        Schema::dropIfExists('compensation_area_results');
        Schema::dropIfExists('compensation_periods');
        Schema::dropIfExists('responsibility_area_assignments');

        if (Schema::hasColumn('projects', 'responsibility_area_id')) {
            Schema::table('projects', fn (Blueprint $table) => $table->dropConstrainedForeignId('responsibility_area_id'));
        }

        $legacyTaskColumn = Schema::hasColumn('tasks', 'responsibility_area_id');
        if ($legacyTaskColumn) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign(['responsibility_area_id']));
        }
        if (Schema::hasTable('responsibility_areas') && ! Schema::hasTable('kpis')) {
            Schema::rename('responsibility_areas', 'kpis');
        }
        if ($legacyTaskColumn) {
            Schema::table('tasks', fn (Blueprint $table) => $table->renameColumn('responsibility_area_id', 'kpi_id'));
            Schema::table('tasks', fn (Blueprint $table) => $table->foreign('kpi_id')->references('id')->on('kpis')->nullOnDelete());
        }
        if (Schema::hasColumn('tasks', 'counts_for_compensation')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('counts_for_compensation'));
        }
        if (Schema::hasColumn('kpis', 'requires_approval')) {
            Schema::table('kpis', fn (Blueprint $table) => $table->dropColumn('requires_approval'));
        }

        if (Schema::hasTable('entity_links')) {
            DB::table('entity_links')->where('source_type', 'responsibility_area')->update(['source_type' => 'kpi']);
            DB::table('entity_links')->where('target_type', 'responsibility_area')->update(['target_type' => 'kpi']);
        }
        if (Schema::hasTable('taggables')) {
            DB::table('taggables')->where('taggable_type', 'responsibility_area')->update(['taggable_type' => 'kpi']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tasks', 'kpi_id')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropForeign(['kpi_id']));
            Schema::table('tasks', fn (Blueprint $table) => $table->renameColumn('kpi_id', 'responsibility_area_id'));
        }
        if (Schema::hasTable('kpis') && ! Schema::hasTable('responsibility_areas')) {
            Schema::rename('kpis', 'responsibility_areas');
        }
    }
};
