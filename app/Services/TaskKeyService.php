<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Scope;
use Illuminate\Support\Str;
use RuntimeException;

class TaskKeyService
{
    /** @return array{number: int, task_key: string} */
    public function reserve(Scope $scope, ?Project $project): array
    {
        if ($project) {
            $counter = Project::query()->lockForUpdate()->findOrFail($project->id);
            $prefix = $counter->key;
        } else {
            $counter = Scope::query()->lockForUpdate()->findOrFail($scope->id);
            $prefix = $counter->task_prefix;
        }

        if (! is_string($prefix) || $prefix === '') {
            throw new RuntimeException('A task key cannot be allocated until its project has a key.');
        }

        $number = (int) $counter->next_task_number;
        $counter->increment('next_task_number');

        return ['number' => $number, 'task_key' => Str::upper($prefix).'-'.$number];
    }
}
