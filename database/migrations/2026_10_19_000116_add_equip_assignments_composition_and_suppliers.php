<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thing_suppliers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->timestamps();
            $table->unique(['scope_id', 'normalized_name']);
        });
        Schema::table('things', function (Blueprint $table): void {
            $table->foreignUlid('supplier_id')->nullable()->constrained('thing_suppliers')->restrictOnDelete();
            $table->string('container_kind', 16)->default('none');
        });
        DB::table('things')->whereNotNull('supplier')->orderBy('id')->chunkById(200, function ($things): void {
            foreach ($things as $thing) {
                $name = preg_replace('/\s+/u', ' ', trim($thing->supplier));
                if ($name === '') {
                    continue;
                }
                $key = mb_strtolower($name);
                $id = DB::table('thing_suppliers')->where('scope_id', $thing->scope_id)->where('normalized_name', $key)->value('id');
                if (! $id) {
                    $id = (string) Str::ulid();
                    DB::table('thing_suppliers')->insert(['id' => $id, 'scope_id' => $thing->scope_id, 'name' => $name, 'normalized_name' => $key, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('things')->where('id', $thing->id)->update(['supplier_id' => $id]);
            }
        });
        Schema::create('thing_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('thing_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('user_name');
            $table->string('responsibility', 24);
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->unsignedTinyInteger('active')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->text('closing_note')->nullable();
            $table->foreignUlid('batch_root_id')->constrained('things')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['thing_id', 'active']);
            $table->index(['scope_id', 'user_id', 'active']);
        });
        Schema::create('thing_compositions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('scope_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('parent_id')->constrained('things')->restrictOnDelete();
            $table->foreignUlid('child_id')->constrained('things')->restrictOnDelete();
            $table->string('parent_name');
            $table->string('child_name');
            $table->string('kind', 16);
            $table->timestamp('started_at', 6);
            $table->timestamp('ended_at', 6)->nullable();
            $table->unsignedTinyInteger('active')->nullable();
            $table->unsignedTinyInteger('physical_active')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->unique(['parent_id', 'child_id', 'active'], 'thing_composition_active_pair');
            $table->unique(['child_id', 'physical_active'], 'thing_composition_physical_parent');
            $table->index(['scope_id', 'active', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thing_compositions');
        Schema::dropIfExists('thing_assignments');
        Schema::table('things', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('container_kind');
        });
        Schema::dropIfExists('thing_suppliers');
    }
};
