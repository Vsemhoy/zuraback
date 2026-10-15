<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Darklog;
use App\Models\Scope;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DarklogService
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context, private readonly DarklogLinkService $links) {}

    public function visible(User $actor, Scope $scope): Builder
    {
        return $this->links->visibleDarklogs($actor, $scope);
    }

    public function authorize(User $actor, Scope $scope, Darklog $darklog, string $ability = 'task.view'): void
    {
        abort_unless($darklog->scope_id === $scope->id && $this->links->projectAllowed($actor, $scope, $darklog->project_id, $ability), 404);
    }

    public function member(Scope $scope, string $id, bool $executor = false): bool
    {
        return User::query()->whereKey($id)->where('is_active', true)->where('status', 'active')
            ->when($executor, fn (Builder $q) => $q->where('is_executor', true)->whereIn('type', ['real', 'virtual']))
            ->where(fn (Builder $q) => $q->whereKey($scope->owner_id)->orWhereHas('scopeMemberships', fn (Builder $m) => $m->where('scope_id', $scope->id)->where('is_active', true)))->exists();
    }

    public function save(Request $request, Scope $scope, Darklog $darklog, array $data): Darklog
    {
        $actor = $this->context->actor($request);

        return DB::transaction(function () use ($request, $scope, $actor, $darklog, $data): Darklog {
            if ($darklog->exists) {
                $this->authorize($actor, $scope, $darklog, 'task.update');
                $darklog = Darklog::query()->lockForUpdate()->findOrFail($darklog->id);
            }
            $projectId = array_key_exists('project_id', $data) ? $data['project_id'] : $darklog->project_id;
            abort_unless($this->links->projectAllowed($actor, $scope, $projectId, $darklog->exists ? 'task.update' : 'task.create'), 404);
            if (! empty($data['responsible_id']) && $data['responsible_id'] !== $darklog->responsible_id) {
                if (! $this->member($scope, $data['responsible_id'])) {
                    throw ValidationException::withMessages(['responsible_id' => 'Выберите активного участника этого скоупа.']);
                }
            }
            $before = $darklog->exists ? [...$darklog->attributesToArray(), 'periods' => $darklog->periods()->get()->toArray()] : null;
            $periods = $data['periods'] ?? ($darklog->exists ? $darklog->periods()->get()->toArray() : []);
            $periods = $this->validatePeriods($darklog, $periods);
            $status = $data['status'] ?? $darklog->status ?? 'new';
            if ($status === 'closed' && collect($periods)->contains(fn (array $period): bool => $period['ends_at'] === null)) {
                throw ValidationException::withMessages(['periods' => 'Перед закрытием завершите все периоды фактического влияния.']);
            }
            if (in_array($status, ['accepted', 'unconfirmed'], true) && trim($data['decision_reason'] ?? $darklog->decision_reason ?? '') === '') {
                throw ValidationException::withMessages(['decision_reason' => 'Укажите обоснование принятого решения.']);
            }
            $replacePeriods = array_key_exists('periods', $data);
            unset($data['periods']);
            $isNew = ! $darklog->exists;
            if (! empty($data['expected_end_at'])) {
                $data['expected_end_at'] = CarbonImmutable::parse($data['expected_end_at'])->utc()->format('Y-m-d H:i:s');
            }
            if ($isNew) {
                Scope::query()->whereKey($scope->id)->lockForUpdate()->firstOrFail();
                $darklog->number = ((int) Darklog::withTrashed()->where('scope_id', $scope->id)->max('number')) + 1;
                $darklog->scope_id = $scope->id;
                $darklog->created_by = $actor->id;
            }
            $darklog->fill($data)->save();
            if ($replacePeriods) {
                $retained = [];
                foreach ($periods as $period) {
                    $id = $period['id'] ?? null;
                    unset($period['id']);
                    if ($id) {
                        $existing = $darklog->periods()->findOrFail($id);
                        $existing->update($period);
                    } else {
                        $existing = $darklog->periods()->create($period);
                    }
                    $retained[] = $existing->id;
                }
                $darklog->periods()->whereNotIn('id', $retained)->delete();
            }
            $this->audit($request, $scope, $darklog, $isNew ? 'darklog.created' : 'darklog.updated', $before,
                [...$darklog->attributesToArray(), 'periods' => $darklog->periods()->get()->toArray()]);

            return $darklog;
        });
    }

    private function validatePeriods(Darklog $darklog, array $periods): array
    {
        $existing = $darklog->exists ? $darklog->periods()->pluck('id')->all() : [];
        $normalized = [];
        foreach ($periods as $index => $period) {
            if (! empty($period['id']) && ! in_array($period['id'], $existing, true)) {
                throw ValidationException::withMessages(["periods.$index.id" => 'Период не принадлежит этому дарклогу.']);
            }
            $from = CarbonImmutable::parse($period['starts_at'])->utc()->startOfSecond();
            $until = empty($period['ends_at']) ? null : CarbonImmutable::parse($period['ends_at'])->utc()->startOfSecond();
            if ($until !== null && $until->lessThanOrEqualTo($from)) {
                throw ValidationException::withMessages(["periods.$index.ends_at" => 'Окончание должно быть позже начала.']);
            }
            $normalized[] = [...(! empty($period['id']) ? ['id' => $period['id']] : []),
                'starts_at' => $from->format('Y-m-d H:i:s'), 'ends_at' => $until?->format('Y-m-d H:i:s'), 'note' => $period['note'] ?? null];
        }
        $sorted = collect($normalized)->sortBy('starts_at')->values();
        for ($i = 1; $i < $sorted->count(); $i++) {
            if ($sorted[$i - 1]['ends_at'] === null || $sorted[$i]['starts_at'] < $sorted[$i - 1]['ends_at']) {
                throw ValidationException::withMessages(['periods' => 'Периоды одного дарклога не должны пересекаться.']);
            }
        }

        return $normalized;
    }

    public function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->profile['avatar'] ?? null] : null;
    }

    public function present(Request $request, Scope $scope, Darklog $darklog, bool $full = false): array
    {
        $actor = $this->context->actor($request);
        $darklog->loadMissing(['project:id,title,key,color', 'department:id,name,color', 'responsible:id,name,profile', 'creator:id,name,profile', 'periods', 'links', 'comments.creator:id,name,profile']);
        $links = $darklog->links->map(fn ($link) => $this->links->present($actor, $scope, $link))->filter()->values();
        $markers = [];
        foreach ($links as $link) {
            $target = $link['target'];
            $markers[] = ['id' => 'link-'.$link['id'], 'kind' => 'link', 'module' => $target['type'], 'at' => $target['at'],
                'title' => trim(($target['code'] ?? '').' '.$target['title']), 'link' => $link];
        }
        $comments = $darklog->comments;
        $commentMarkers = $comments->map(fn ($comment): array => ['id' => 'comment-'.$comment->id, 'module' => 'comment',
            'at' => $comment->created_at, 'title' => mb_substr($comment->content, 0, 160)])->all();
        $row = [...$darklog->attributesToArray(), 'code' => 'DRK-'.$darklog->number,
            'project' => $darklog->project, 'department' => $darklog->department,
            'responsible' => $this->person($darklog->responsible), 'creator' => $this->person($darklog->creator),
            'periods' => $darklog->periods->toArray(), 'markers' => [...$markers, ...$commentMarkers],
            'can_update' => $this->links->projectAllowed($actor, $scope, $darklog->project_id, 'task.update'),
            'can_delete' => $this->links->projectAllowed($actor, $scope, $darklog->project_id, 'task.delete'),
            'can_review' => $this->access->allows($actor, $scope, 'contractor.manage')];
        if (! $full) {
            foreach (['description', 'impact', 'workaround', 'resolution', 'decision_reason'] as $field) {
                unset($row[$field]);
            }

            return $row;
        }
        $darklog->loadMissing(['kpiLinks.user:id,name,profile', 'kpiLinks.kpi:id,name,kind,points', 'kpiLinks.reviewer:id,name,profile']);
        $row['links'] = $links->all();
        $row['kpi_links'] = $darklog->kpiLinks->map(fn ($link): array => [
            ...$link->attributesToArray(), 'user' => $this->person($link->user), 'kpi' => $link->kpi, 'reviewer' => $this->person($link->reviewer),
        ])->all();
        $timeline = collect($markers);
        foreach ($darklog->periods as $period) {
            $timeline->push(['id' => 'period-'.$period->id, 'kind' => 'period_start', 'module' => 'darklog', 'at' => $period->starts_at, 'title' => 'Началось влияние', 'content' => $period->note]);
            if ($period->ends_at) {
                $timeline->push(['id' => 'period-end-'.$period->id, 'kind' => 'period_end', 'module' => 'darklog', 'at' => $period->ends_at, 'title' => 'Влияние завершено', 'content' => $period->note]);
            }
        }
        foreach ($comments as $comment) {
            $timeline->push(['id' => 'comment-'.$comment->id, 'comment_id' => $comment->id, 'parent_id' => $comment->parent_id,
                'kind' => 'comment', 'module' => 'comment', 'at' => $comment->created_at, 'title' => 'Комментарий', 'content' => $comment->content,
                'author' => $this->person($comment->creator), 'can_delete' => $comment->created_by === $actor->id || $row['can_review']]);
        }
        $logs = ActivityLog::query()->where('scope_id', $scope->id)->where('subject_type', 'darklog')->where('subject_id', $darklog->id)->with('actor:id,name,profile')->oldest()->get();
        foreach ($logs as $log) {
            $timeline->push(['id' => 'activity-'.$log->id, 'kind' => 'activity', 'module' => 'darklog', 'at' => $log->created_at,
                'title' => $log->action, 'author' => $this->person($log->actor),
                'changes' => $log->action === 'darklog.updated' ? array_values(array_filter(array_keys($log->after ?? []), fn (string $key): bool => ($log->before[$key] ?? null) !== ($log->after[$key] ?? null))) : [],
                'status' => $log->action === 'darklog.updated' ? ($log->after['status'] ?? null) : null]);
        }
        $row['timeline'] = $timeline->sortBy(fn (array $event): string => CarbonImmutable::parse($event['at'])->format('Y-m-d H:i:s.u').'|'.$event['id'])->values()->all();

        return $row;
    }

    public function audit(Request $request, Scope $scope, Darklog $darklog, string $action, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id,
            'subject_type' => 'darklog', 'subject_id' => $darklog->id, 'action' => $action, 'before' => $before, 'after' => $after,
            'context' => $this->context->auditMetadata($request), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
    }
}
