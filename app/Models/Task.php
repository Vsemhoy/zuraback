<?php

namespace App\Models;

use App\Models\Concerns\HasEntityLinks;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

#[Fillable(['scope_id', 'project_id', 'parent_id', 'number', 'task_key', 'created_by', 'assignee_id', 'customer_id', 'is_agent_delegatable', 'delegated_agent_id', 'approved_by', 'kpi_id', 'title', 'description', 'result', 'agent_notes', 'status', 'priority', 'due_at', 'completed_at', 'approved_at', 'tracked_seconds', 'is_pinned', 'sort_order', 'meta'])]
class Task extends DomainModel
{
    use HasEntityLinks, SoftDeletes;

    protected static function booted(): void
    {
        static::updating(function (Task $task): void {
            $status = $task->getOriginal('status');
            if (! in_array($status, ['done', 'cancelled'], true)) {
                return;
            }
            // Board ordering does not change the task's content or accounting.
            $allowed = $status === 'done' ? ['project_id', 'kpi_id', 'sort_order', 'updated_at'] : ['sort_order', 'updated_at'];
            if ($task->isDirty('status')) {
                $allowed = [...$allowed, 'status', 'completed_at', 'sort_order'];
            }
            $blocked = array_diff(array_keys($task->getDirty()), $allowed);
            if ($blocked !== []) {
                throw ValidationException::withMessages(array_fill_keys($blocked, 'Сначала верните закрытую задачу в работу.'));
            }
        });
    }

    public function assertEditable(): void
    {
        if (in_array($this->status, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['task' => 'Сначала верните закрытую задачу в работу.']);
        }
    }

    public function scopeWithCommentSummary(Builder $query): Builder
    {
        return $query->withCount(['comments', 'comments as unanswered_questions_count' => fn (Builder $comments) => $comments->where('kind', 'question')->where('is_answered', false)]);
    }

    public function scopeIncludedInReports(Builder $query): Builder
    {
        return $query->where(fn (Builder $tasks) => $tasks->whereNull('project_id')->orWhereHas('project', fn (Builder $projects) => $projects->where('include_in_reports', true)));
    }

    public function planItems(): BelongsToMany
    {
        return $this->belongsToMany(PlanItem::class, 'plan_item_task');
    }

    public function monthlyPlans(): HasMany
    {
        return $this->hasMany(MonthlyTaskPlan::class);
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('sort_order');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(TaskBlocker::class)->latest('blocked_at');
    }

    public function plannerTails(): HasMany
    {
        return $this->hasMany(TaskPlannerTail::class);
    }

    public function keyAliases(): HasMany
    {
        return $this->hasMany(TaskKeyAlias::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id')->withTrashed();
    }

    public function delegatedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_agent_id')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime', 'approved_at' => 'datetime', 'is_pinned' => 'boolean', 'is_agent_delegatable' => 'boolean', 'meta' => 'array'];
    }
}
