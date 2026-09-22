<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreFilerFileRequest;
use App\Models\FilerFile;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\FilerAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FilerController extends Controller
{
    public function __construct(
        private readonly FilerAccessService $access,
        private readonly ContractorAccessService $contractors,
        private readonly ContractorContext $context,
    ) {}

    public function index(Request $request, Scope $scope): JsonResponse
    {
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(FilerFile::CATEGORIES)],
            'q' => ['nullable', 'string', 'max:120'],
            'subject_type' => ['nullable', Rule::in(array_keys(FilerAccessService::SUBJECTS)), 'required_with:subject_id'],
            'subject_id' => ['nullable', 'ulid', 'required_with:subject_type'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $actor = $this->context->actor($request);
        $query = FilerFile::query()->where('scope_id', $scope->id)->with(['creator', 'attachments.subject'])->latest('id');
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['q'])) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }
        if (! empty($filters['subject_id'])) {
            $query->whereHas('attachments', fn ($links) => $links->where('subject_type', $filters['subject_type'])->where('subject_id', $filters['subject_id']));
        }
        $page = (int) ($filters['page'] ?? 1);
        $visible = [];
        $skip = ($page - 1) * 30;
        foreach ($query->lazy(100) as $file) {
            if (! $this->access->file($actor, $scope, $file)) {
                continue;
            }
            if ($skip-- > 0) {
                continue;
            }
            $visible[] = $this->representation($request, $scope, $file);
            if (count($visible) === 31) {
                break;
            }
        }

        return response()->json(['data' => array_slice($visible, 0, 30), 'meta' => ['page' => $page, 'has_more' => count($visible) > 30]]);
    }

    public function targets(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(FilerAccessService::SUBJECTS))], 'q' => ['nullable', 'string', 'max:120']]);
        $actor = $this->context->actor($request);
        $class = FilerAccessService::SUBJECTS[$data['type']];
        $query = $class::query();
        if ($data['type'] === 'user') {
            $query->whereIn('id', $scope->members()->where('is_active', true)->select('user_id'));
        } else {
            $query->where('scope_id', $scope->id);
        }
        $column = $data['type'] === 'user' ? 'name' : 'title';
        if (! empty($data['q'])) {
            $query->where($column, 'like', '%'.$data['q'].'%');
        }
        $items = [];
        foreach ($query->orderBy($column)->lazy(100) as $subject) {
            if ($this->access->subject($actor, $scope, $subject, true)) {
                $items[] = ['id' => $subject->id, 'title' => $subject->{$column}];
            }
            if (count($items) >= 50) {
                break;
            }
        }

        return response()->json(['data' => $items]);
    }

    public function store(StoreFilerFileRequest $request, Scope $scope): JsonResponse
    {
        $data = $request->validated();
        $actor = $this->context->actor($request);
        $type = $data['subject_type'] ?? null;
        if (in_array($data['category'], array_keys(FilerAccessService::SUBJECTS), true)) {
            abort_unless($type === $data['category'] && ! empty($data['subject_id']), 422, 'Выберите объект для вложения.');
        }
        if ($type) {
            $class = FilerAccessService::SUBJECTS[$type];
            abort_unless($this->access->subject($actor, $scope, $class::find($data['subject_id']), true), 404);
        } else {
            abort_unless($this->contractors->allows($actor, $scope, 'task.update'), 403);
        }
        $upload = $request->file('file');
        $disk = Storage::disk('filer');
        $root = $disk->path('');
        if (config('filesystems.disks.filer.driver') === 'local') {
            if (! is_dir($root)) {
                abort_unless($disk->makeDirectory(''), 507, 'Не удалось подготовить хранилище.');
            }
            $available = @disk_free_space($root);
            abort_if($available === false || $available - $upload->getSize() < config('filer.reserve_bytes'), 507, 'На сервере недостаточно свободного места.');
        }
        $id = (string) Str::ulid();
        $path = $scope->id.'/'.$id.'/original';
        $name = Str::limit(preg_replace('/[\\x00-\\x1F\\x7F\\/\\\\\\\\]/u', '_', $upload->getClientOriginalName()), 240, '');
        abort_unless($upload->storeAs($scope->id.'/'.$id, 'original', 'filer') !== false, 507, 'Не удалось сохранить файл.');
        try {
            $file = DB::transaction(function () use ($scope, $actor, $request, $data, $upload, $id, $path, $name, $type): FilerFile {
                $file = new FilerFile([
                    'scope_id' => $scope->id, 'created_by' => $actor->id, 'uploaded_by' => $request->user()->id,
                    'name' => $name, 'category' => $data['category'], 'visibility' => $data['visibility'],
                    'disk' => 'filer', 'path' => $path, 'mime' => $upload->getMimeType() ?: 'application/octet-stream',
                    'size' => $upload->getSize(), 'sha256' => hash_file('sha256', $upload->getRealPath()),
                ]);
                $file->id = $id;
                $file->save();
                if ($type) {
                    $file->attachments()->create(['subject_type' => $type, 'subject_id' => $data['subject_id']]);
                }

                return $file;
            });
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->representation($request, $scope, $file->load(['creator', 'attachments.subject']))], 201);
    }

    public function attach(Request $request, Scope $scope, FilerFile $file): JsonResponse
    {
        $actor = $this->context->actor($request);
        abort_unless($this->access->file($actor, $scope, $file, true), 404);
        $data = $request->validate(['subject_type' => ['required', Rule::in(array_keys(FilerAccessService::SUBJECTS))], 'subject_id' => ['required', 'ulid']]);
        $class = FilerAccessService::SUBJECTS[$data['subject_type']];
        abort_unless($this->access->subject($actor, $scope, $class::find($data['subject_id']), true), 404);
        $file->attachments()->firstOrCreate($data);

        return response()->json(['data' => $this->representation($request, $scope, $file->load(['creator', 'attachments.subject']))]);
    }

    public function download(Request $request, Scope $scope, FilerFile $file): StreamedResponse
    {
        abort_unless($this->access->file($this->context->actor($request), $scope, $file), 404);
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404, 'Файл отсутствует в хранилище.');

        return $disk->download($file->path, $file->name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, Scope $scope, FilerFile $file): Response
    {
        abort_unless($this->access->file($this->context->actor($request), $scope, $file, true), 404);
        $disk = Storage::disk($file->disk);
        abort_unless(! $disk->exists($file->path) || $disk->delete($file->path), 503, 'Не удалось удалить файл.');
        $file->delete();

        return response()->noContent();
    }

    private function representation(Request $request, Scope $scope, FilerFile $file): array
    {
        return [
            'id' => $file->id, 'name' => $file->name, 'category' => $file->category,
            'visibility' => $file->visibility, 'size' => $file->size, 'mime' => $file->mime,
            'created_at' => $file->created_at, 'creator' => $file->creator?->only(['id', 'name']),
            'can_manage' => $this->access->file($this->context->actor($request), $scope, $file, true),
            'attachments' => $file->attachments->map(fn ($link): array => [
                'type' => $link->subject_type, 'id' => $link->subject_id,
                'title' => $link->subject?->title ?? $link->subject?->name ?? 'Удалённый объект',
            ])->all(),
        ];
    }
}
