<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ImportTaskerRequest;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\TaskKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AgentTaskerImportController extends Controller
{
    public function __construct(
        private readonly ContractorAccessService $access,
        private readonly ContractorContext $context,
        private readonly TaskKeyService $keys,
    ) {}

    public function store(ImportTaskerRequest $request, Scope $scope): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->context->actor($request);
        $plan = $this->plan($scope, $actor, $data);

        if ($request->boolean('dry_run')) {
            return response()->json(['data' => ['dry_run' => true, ...$plan]]);
        }

        $result = DB::transaction(fn (): array => $this->import($scope, $actor, $data));

        return response()->json(['data' => ['dry_run' => false, ...$result]], 201);
    }

    /** @param array<string, mixed> $data */
    private function plan(Scope $scope, User $actor, array $data): array
    {
        $projectExternalIds = collect($data['projects'] ?? [])->pluck('external_id');
        $taskExternalIds = collect($data['tasks'] ?? [])->pluck('external_id');
        $this->assertReferencesExist($data, $projectExternalIds->all(), $taskExternalIds->all());

        $projects = collect($data['projects'] ?? [])->map(function (array $payload) use ($scope, $actor): array {
            $existingById = Project::query()->find($payload['external_id']);
            abort_if($existingById !== null && ($existingById->scope_id !== $scope->id || $existingById->key !== $payload['key']), 409, "Project ID {$payload['external_id']} is already in use.");
            $existing = $existingById ?? Project::query()->where('scope_id', $scope->id)->where('key', $payload['key'])->first();

            if ($existing !== null) {
                abort_unless($this->access->canAccessProject($actor, $scope, $existing, 'task.create'), 403, "Project {$payload['key']} is outside the agent access boundary.");
            } else {
                abort_unless($this->access->canAccessUnprojected($actor, $scope), 403, 'Creating imported projects requires all-project access in the scope.');
            }

            return [
                'external_id' => $payload['external_id'],
                'key' => $payload['key'],
                'action' => $existing === null ? 'create' : 'reuse',
                'project_id' => $existing?->id,
            ];
        });

        $taskIds = $taskExternalIds->all();
        $checklistIds = collect($data['checklist_items'] ?? [])->pluck('external_id')->all();
        $commentIds = collect($data['comments'] ?? [])->pluck('external_id')->all();
        $activityIds = collect($data['activities'] ?? [])->pluck('external_id')->all();

        foreach (Task::query()->whereIn('id', $taskIds)->get() as $task) {
            $this->assertImportedModel($task, $scope, $actor, $data['source'], $task->id);
        }

        return [
            'source' => $data['source'],
            'projects' => $projects->values()->all(),
            'counts' => [
                'projects' => $this->counts($projects->pluck('action')->all()),
                'tasks' => $this->createReuseCounts(Task::query()->whereIn('id', $taskIds)->count(), count($taskIds)),
                'checklist_items' => $this->createReuseCounts(TaskChecklistItem::query()->whereIn('id', $checklistIds)->count(), count($checklistIds)),
                'comments' => $this->createReuseCounts(Comment::query()->whereIn('id', $commentIds)->count(), count($commentIds)),
                'activities' => $this->createReuseCounts(ActivityLog::query()->whereIn('id', $activityIds)->count(), count($activityIds)),
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function import(Scope $scope, User $actor, array $data): array
    {
        $projectMap = [];
        $taskMap = [];
        $counts = [
            'projects' => ['created' => 0, 'reused' => 0],
            'tasks' => ['created' => 0, 'reused' => 0],
            'checklist_items' => ['created' => 0, 'reused' => 0],
            'comments' => ['created' => 0, 'reused' => 0],
            'activities' => ['created' => 0, 'reused' => 0],
        ];

        foreach ($data['projects'] ?? [] as $payload) {
            $projectById = Project::query()->find($payload['external_id']);
            abort_if($projectById !== null && ($projectById->scope_id !== $scope->id || $projectById->key !== $payload['key']), 409, "Project ID {$payload['external_id']} is already in use.");
            $project = $projectById ?? Project::query()->where('scope_id', $scope->id)->where('key', $payload['key'])->first();
            if ($project === null) {
                $project = Project::query()->forceCreate([
                    ...Arr::except($payload, ['external_id']),
                    'id' => strtolower($payload['external_id']),
                    'scope_id' => $scope->id,
                    'created_by' => $actor->id,
                    'meta' => ['import' => ['source' => $data['source'], 'external_id' => $payload['external_id']]],
                ]);
                $counts['projects']['created']++;
            } else {
                abort_unless($this->access->canAccessProject($actor, $scope, $project, 'task.create'), 403, "Project {$payload['key']} is outside the agent access boundary.");
                $counts['projects']['reused']++;
            }
            $projectMap[$payload['external_id']] = $project;
        }

        foreach ($data['tasks'] ?? [] as $payload) {
            $task = Task::query()->find($payload['external_id']);
            if ($task === null) {
                $project = $projectMap[$payload['project_external_id']];
                $this->assertAssigneeCanAccess($scope, $project, $payload['assignee_id'] ?? null);
                $identity = $this->keys->reserve($scope, $project);
                $task = Task::query()->forceCreate([
                    ...Arr::except($payload, ['external_id', 'project_external_id', 'parent_external_id', 'legacy_assignee', 'legacy_spans']),
                    ...$identity,
                    'id' => strtolower($payload['external_id']),
                    'scope_id' => $scope->id,
                    'project_id' => $project->id,
                    'parent_id' => null,
                    'created_by' => $actor->id,
                    'meta' => [
                        'import' => ['source' => $data['source'], 'external_id' => $payload['external_id']],
                        'legacy_assignee' => $payload['legacy_assignee'] ?? null,
                        'legacy_spans' => $payload['legacy_spans'] ?? [],
                    ],
                ]);
                $counts['tasks']['created']++;
            } else {
                $this->assertImportedModel($task, $scope, $actor, $data['source'], $payload['external_id']);
                $counts['tasks']['reused']++;
            }
            $taskMap[$payload['external_id']] = $task;
        }

        foreach ($data['tasks'] ?? [] as $payload) {
            if (! empty($payload['parent_external_id'])) {
                $taskMap[$payload['external_id']]->updateQuietly(['parent_id' => $taskMap[$payload['parent_external_id']]->id]);
            }
        }

        foreach ($data['checklist_items'] ?? [] as $payload) {
            $existing = TaskChecklistItem::query()->find($payload['external_id']);
            if ($existing !== null) {
                abort_unless($existing->task_id === $taskMap[$payload['task_external_id']]->id, 409, "Checklist ID {$payload['external_id']} is already in use.");
                $this->assertImportMetadata($existing->meta, $data['source'], $payload['external_id']);
                $counts['checklist_items']['reused']++;

                continue;
            }
            $task = $taskMap[$payload['task_external_id']];
            TaskChecklistItem::query()->forceCreate([
                ...Arr::except($payload, ['external_id', 'task_external_id', 'is_completed']),
                'id' => strtolower($payload['external_id']),
                'task_id' => $task->id,
                'created_by' => $actor->id,
                'completed_by' => $payload['is_completed'] ? $actor->id : null,
                'completed_at' => $payload['is_completed'] ? ($payload['completed_at'] ?? $payload['updated_at']) : null,
                'meta' => ['import' => ['source' => $data['source'], 'external_id' => $payload['external_id']]],
            ]);
            $counts['checklist_items']['created']++;
        }

        foreach ($data['comments'] ?? [] as $payload) {
            $existing = Comment::query()->find($payload['external_id']);
            if ($existing !== null) {
                abort_unless($existing->scope_id === $scope->id && $existing->commentable_type === 'task' && $existing->commentable_id === $taskMap[$payload['task_external_id']]->id, 409, "Comment ID {$payload['external_id']} is already in use.");
                $counts['comments']['reused']++;

                continue;
            }
            Comment::query()->forceCreate([
                'id' => strtolower($payload['external_id']),
                'scope_id' => $scope->id,
                'commentable_type' => 'task',
                'commentable_id' => $taskMap[$payload['task_external_id']]->id,
                'created_by' => $actor->id,
                'content' => ($payload['kind'] === 'report' ? "**Отчёт из Teftele**\n\n" : '').$payload['content'],
                'created_at' => $payload['created_at'],
                'updated_at' => $payload['updated_at'],
            ]);
            $counts['comments']['created']++;
        }

        foreach ($data['activities'] ?? [] as $payload) {
            $existing = ActivityLog::query()->find($payload['external_id']);
            if ($existing !== null) {
                abort_unless($existing->scope_id === $scope->id && $existing->subject_type === 'task' && $existing->subject_id === $taskMap[$payload['task_external_id']]->id, 409, "Activity ID {$payload['external_id']} is already in use.");
                $counts['activities']['reused']++;

                continue;
            }
            ActivityLog::query()->forceCreate([
                'id' => strtolower($payload['external_id']),
                'scope_id' => $scope->id,
                'actor_id' => $actor->id,
                'subject_type' => 'task',
                'subject_id' => $taskMap[$payload['task_external_id']]->id,
                'action' => 'task.updated',
                'before' => $payload['before'],
                'after' => $payload['after'],
                'context' => ['import' => ['source' => $data['source'], 'external_id' => $payload['external_id']]],
                'created_at' => $payload['created_at'],
            ]);
            $counts['activities']['created']++;
        }

        return [
            'source' => $data['source'],
            'counts' => $counts,
            'project_map' => collect($projectMap)->map->id->all(),
            'task_map' => collect($taskMap)->map->id->all(),
        ];
    }

    /** @param array<string, mixed> $data @param array<int, string> $projectIds @param array<int, string> $taskIds */
    private function assertReferencesExist(array $data, array $projectIds, array $taskIds): void
    {
        foreach ($data['tasks'] ?? [] as $task) {
            abort_unless(in_array($task['project_external_id'], $projectIds, true), 422, "Unknown project_external_id {$task['project_external_id']}.");
            abort_if(! empty($task['parent_external_id']) && ! in_array($task['parent_external_id'], $taskIds, true), 422, "Unknown parent_external_id {$task['parent_external_id']}.");
        }
        foreach (['checklist_items', 'comments', 'activities'] as $collection) {
            foreach ($data[$collection] ?? [] as $item) {
                abort_unless(in_array($item['task_external_id'], $taskIds, true), 422, "Unknown task_external_id {$item['task_external_id']}.");
            }
        }
    }

    private function assertAssigneeCanAccess(Scope $scope, Project $project, ?string $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }
        $assignee = User::query()->findOrFail($assigneeId);
        abort_unless($this->access->allows($assignee, $scope, 'task.view', $project), 422, "Assignee {$assigneeId} cannot access project {$project->key}.");
    }

    private function assertImportedModel(Task $task, Scope $scope, User $actor, string $source, string $externalId): void
    {
        abort_unless($task->scope_id === $scope->id, 409, "Task ID {$externalId} is already in use.");
        abort_unless($this->access->canAccessTask($actor, $scope, $task, 'task.update'), 403, "Task {$externalId} is outside the agent access boundary.");
        $this->assertImportMetadata($task->meta, $source, $externalId);
    }

    /** @param array<string, mixed>|null $meta */
    private function assertImportMetadata(?array $meta, string $source, string $externalId): void
    {
        abort_unless(
            ($meta['import']['source'] ?? null) === $source
                && strcasecmp((string) ($meta['import']['external_id'] ?? ''), $externalId) === 0,
            409,
            "External ID {$externalId} is already in use by a non-imported record.",
        );
    }

    /** @param array<int, string> $actions */
    private function counts(array $actions): array
    {
        return ['create' => count(array_filter($actions, fn (string $action): bool => $action === 'create')), 'reuse' => count(array_filter($actions, fn (string $action): bool => $action === 'reuse'))];
    }

    private function createReuseCounts(int $reused, int $total): array
    {
        return ['create' => $total - $reused, 'reuse' => $reused];
    }
}
