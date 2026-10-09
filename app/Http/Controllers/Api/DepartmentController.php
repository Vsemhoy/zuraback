<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function index(Request $request, Scope $scope): JsonResource
    {
        return new JsonResource(['can_manage' => $this->access->allows($this->context->actor($request), $scope, 'contractor.manage'),
            'departments' => Department::where('scope_id', $scope->id)->with(['members' => fn ($q) => $q->where('is_active', true)->select(['id', 'department_id', 'user_id'])->with('user:id,name')])->orderBy('name')->get()]);
    }

    public function store(Request $request, Scope $scope): JsonResource
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('departments')->where('scope_id', $scope->id)], 'description' => ['nullable', 'string', 'max:2000'], 'color' => ['sometimes', 'required', Rule::in(Department::COLORS)]]);

        return DB::transaction(function () use ($request, $scope, $data): JsonResource {
            $department = Department::create(['scope_id' => $scope->id, ...$data]);

            return $this->update($request, $scope, $department);
        });
    }

    public function update(Request $request, Scope $scope, Department $department): JsonResource
    {
        abort_unless($department->scope_id === $scope->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('departments')->where('scope_id', $scope->id)->ignore($department->id)], 'description' => ['nullable', 'string', 'max:2000'], 'color' => ['sometimes', 'required', Rule::in(Department::COLORS)], 'user_ids' => ['sometimes', 'array'], 'user_ids.*' => ['ulid', 'distinct']]);
        DB::transaction(function () use ($scope, $department, $data): void {
            $scope->newQuery()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
            if (isset($data['user_ids'])) {
                foreach ($data['user_ids'] as $id) {
                    abort_unless(User::whereKey($id)->whereIn('type', ['real', 'virtual'])->exists() && ($scope->owner_id === $id || $scope->members()->where('user_id', $id)->where('is_active', true)->exists()), 422, 'Сотрудник недоступен в этом скоупе.');
                }
                $scope->members()->where('department_id', $department->id)->whereNotIn('user_id', $data['user_ids'])->update(['department_id' => null]);
                foreach ($data['user_ids'] as $id) {
                    $scope->members()->updateOrCreate(['user_id' => $id], ['department_id' => $department->id]);
                }
            }
            $department->update(collect($data)->only(['name', 'description', 'color'])->all());
        });

        return new JsonResource($department->fresh());
    }

    public function destroy(Scope $scope, Department $department): Response
    {
        abort_unless($department->scope_id === $scope->id, 404);
        abort_if($department->members()->exists() || Task::withTrashed()->where('department_id', $department->id)->exists() || Project::withTrashed()->where('department_id', $department->id)->exists() || DB::table('department_project')->where('department_id', $department->id)->exists(), 422, 'Сначала перенесите сотрудников, проекты и задачи отдела.');
        $department->delete();

        return response()->noContent();
    }

    public function claim(Request $request, Scope $scope, Task $task): JsonResource
    {
        $actor = $this->context->actor($request);

        return DB::transaction(function () use ($scope, $task, $actor): JsonResource {
            $task = Task::where('scope_id', $scope->id)->whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($task->department_id && $this->access->membership($actor, $scope)?->department_id === $task->department_id && $actor->is_executor, 403);
            abort_if($task->assignee_id || in_array($task->status, ['done', 'cancelled']), 409, 'Задача уже назначена или завершена.');
            $task->update(['assignee_id' => $actor->id]);
            ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id, 'action' => 'task.assigned', 'before' => ['assignee_id' => null], 'after' => ['assignee_id' => $actor->id]]);

            return new JsonResource($task);
        });
    }
}
