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
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('show_in_tasker')->default(true)->after('visibility');
            $table->boolean('show_in_eventor')->default(true)->after('show_in_tasker');
            $table->boolean('event_comments_enabled')->default(true)->after('show_in_eventor');
        });

        Schema::table('event_types', function (Blueprint $table): void {
            $table->string('code', 24)->nullable()->after('scope_id');
            $table->unique(['scope_id', 'code']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignUlid('project_id')->nullable()->after('type_id')->constrained()->nullOnDelete();
            $table->foreignUlid('requester_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->string('importance', 24)->default('undefined')->after('status');
            $table->string('visibility', 16)->default('scope')->after('importance');
            $table->boolean('comments_enabled')->nullable()->after('is_locked');
            $table->boolean('is_blurred')->default(false)->after('comments_enabled');
            $table->json('diagram')->nullable()->after('meta');
            $table->index(['scope_id', 'project_id', 'starts_at']);
            $table->index(['scope_id', 'visibility', 'created_by']);
        });

        $types = [
            ['none', 'Без типа', '#7b8798', '#eef1f5'],
            ['request', 'Заявка', '#9b5c12', '#fff1dc'],
            ['action', 'Действие', '#176b51', '#e3f6ef'],
            ['event', 'Событие', '#2d6cdf', '#e7f0fd'],
            ['state', 'Состояние', '#7048a8', '#f0e8fb'],
            ['note', 'Заметка', '#b04d73', '#fae8ef'],
            ['synopsis', 'Конспект', '#456276', '#e7eef2'],
        ];

        foreach (DB::table('scopes')->pluck('id') as $scopeId) {
            foreach ($types as $index => [$code, $name, $color, $background]) {
                if (DB::table('event_types')->where('scope_id', $scopeId)->where('code', $code)->exists()) {
                    continue;
                }
                DB::table('event_types')->insert([
                    'id' => (string) Str::ulid(), 'scope_id' => $scopeId, 'code' => $code,
                    'name' => $name, 'color' => $color, 'background_color' => $background,
                    'sort_order' => $index * 1000, 'is_default' => $code === 'none', 'is_archived' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(['scope_id', 'project_id', 'starts_at']);
            $table->dropIndex(['scope_id', 'visibility', 'created_by']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('requester_id');
            $table->dropColumn(['importance', 'visibility', 'comments_enabled', 'is_blurred', 'diagram']);
        });
        Schema::table('event_types', function (Blueprint $table): void {
            $table->dropUnique(['scope_id', 'code']);
            $table->dropColumn('code');
        });
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['show_in_tasker', 'show_in_eventor', 'event_comments_enabled']);
        });
    }
};
