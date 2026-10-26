<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $groups = DB::table('legacy_import_records')->where('source', 'home.zuratax.com')->where('source_type', 'ledger_category')
                ->where('target_type', 'ledger_category')->get()->groupBy('scope_id');
            foreach ($groups as $scopeId => $records) {
                $mapped = $records->keyBy(fn ($record): string => strtolower($record->source_id));
                $snapshots = $records->mapWithKeys(fn ($record): array => [strtolower($record->source_id) => json_decode(Crypt::decryptString($record->snapshot), true, 512, JSON_THROW_ON_ERROR)]);
                foreach ($records as $record) {
                    $sourceId = strtolower($record->source_id);
                    $snapshot = $snapshots[$sourceId];
                    $parentId = strtolower($snapshot['parent_id'] ?? '');
                    if ($parentId === '' || ! isset($mapped[$parentId])) {
                        continue;
                    }
                    $names = [$snapshot['name']];
                    $seen = [$sourceId => true];
                    $ancestor = $parentId;
                    while ($ancestor !== '') {
                        if (isset($seen[$ancestor]) || ! isset($snapshots[$ancestor]) || count($seen) >= 5) {
                            continue 2;
                        }
                        $seen[$ancestor] = true;
                        array_unshift($names, $snapshots[$ancestor]['name']);
                        $ancestor = strtolower($snapshots[$ancestor]['parent_id'] ?? '');
                    }
                    $category = DB::table('ledger_categories')->where('scope_id', $scopeId)->where('id', $record->target_id)->lockForUpdate()->first();
                    $parent = DB::table('ledger_categories')->where('scope_id', $scopeId)->where('id', $mapped[$parentId]->target_id)->first();
                    // Only restore unchanged imported paths; never infer hierarchy from arbitrary slashes.
                    if (! $category || ! $parent || $category->project_id !== $parent->project_id || $category->parent_id !== null || $category->title !== implode(' / ', $names)) {
                        continue;
                    }
                    DB::table('ledger_categories')->where('id', $category->id)->update(['title' => $snapshot['name'], 'parent_id' => $parent->id, 'revision' => $category->revision + 1]);
                }
            }
        });
    }

    public function down(): void
    {
        // Restored user data is retained. A forward migration is required to flatten it again.
    }
};
