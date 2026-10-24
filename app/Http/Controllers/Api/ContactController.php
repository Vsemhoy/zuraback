<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SaveContactNoteRequest;
use App\Http\Requests\Api\SaveContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Scope;
use App\Models\User;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\PrivateRecordAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function __construct(private readonly PrivateRecordAccessService $access, private readonly ContractorContext $context) {}

    public function options(Request $request, Scope $scope): JsonResponse
    {
        $actor = $this->context->actor($request);

        return response()->json(['data' => [
            'projects' => $this->access->projects($actor, $scope, 'contact.view')->orderBy('sort_order')->orderBy('title')->get(['id', 'title', 'key']),
            'can_create' => app(ContractorAccessService::class)->allows($actor, $scope, 'contact.write'),
            'users' => User::where('is_active', true)->where('status', 'active')->where(fn (Builder $q) => $q->where('id', $scope->owner_id)->orWhereHas('scopeMemberships', fn (Builder $q) => $q->where('scope_id', $scope->id)->where('is_active', true)))->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'kind' => ['nullable', 'in:person,organization'], 'project_id' => ['nullable', 'ulid'], 'visibility' => ['nullable', 'in:private,scope,project'], 'pinned' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean'], 'trash' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->access->query(Contact::class, $this->context->actor($request), $scope);
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
        $query->where('is_archived', $request->boolean('archived'));
        if ($request->boolean('pinned')) {
            $query->where('is_pinned', true);
        }
        foreach (['kind', 'project_id', 'visibility'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['q'])) {
            $query->where(function (Builder $q) use ($data): void {
                foreach (['name', 'nickname', 'company', 'role', 'phone', 'email'] as $field) {
                    $q->orWhere($field, 'like', '%'.$data['q'].'%');
                }
            });
        }

        return ContactResource::collection($query->orderByDesc('is_pinned')->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate($data['per_page'] ?? 50));
    }

    public function show(Request $request, Scope $scope, Contact $contact): ContactResource
    {
        abort_unless($this->access->read($this->context->actor($request), $scope, $contact), 404);

        return new ContactResource($contact);
    }

    public function store(SaveContactRequest $request, Scope $scope): ContactResource
    {
        $actor = $this->context->actor($request);
        $this->validateData($request, $scope);
        $contact = DB::transaction(function () use ($request, $scope, $actor): Contact {
            $contact = Contact::create([...$request->validated(), 'scope_id' => $scope->id, 'created_by' => $actor->id, 'revision' => 1]);
            $this->audit($request, $scope, $contact, 'created');

            return $contact;
        });

        return new ContactResource($contact);
    }

    public function update(SaveContactRequest $request, Scope $scope, Contact $contact): ContactResource
    {
        return DB::transaction(function () use ($request, $scope, $contact): ContactResource {
            $contact = Contact::whereKey($contact->id)->lockForUpdate()->firstOrFail();
            $actor = $this->context->actor($request);
            abort_unless($this->access->read($actor, $scope, $contact), 404);
            abort_unless($this->access->write($actor, $scope, $contact), 403);
            abort_unless($contact->revision === $request->integer('revision'), 409, 'Контакт уже изменён. Обновите запись.');
            $this->validateData($request, $scope, $contact);
            $contact->update([...$request->validated(), 'revision' => $contact->revision + 1]);
            $this->audit($request, $scope, $contact, 'updated');

            return new ContactResource($contact);
        });
    }

    private function validateData(SaveContactRequest $request, Scope $scope, ?Contact $contact = null): void
    {
        $data = $request->validated();
        $this->access->validate($this->context->actor($request), $scope, Contact::class, $data, $contact);
        if (! empty($data['user_id']) && ! User::whereKey($data['user_id'])->where('is_active', true)->where('status', 'active')->where(fn (Builder $q) => $q->where('id', $scope->owner_id)->orWhereHas('scopeMemberships', fn (Builder $q) => $q->where('scope_id', $scope->id)->where('is_active', true)))->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Учётка должна быть активным участником этого скоупа.']);
        }
    }

    public function destroy(Request $request, Scope $scope, Contact $contact): Response
    {
        $this->setDeleted($request, $scope, $contact->id, true);

        return response()->noContent();
    }

    public function restore(Request $request, Scope $scope, string $contact): ContactResource
    {
        return new ContactResource($this->setDeleted($request, $scope, $contact, false));
    }

    private function setDeleted(Request $request, Scope $scope, string $id, bool $deleted): Contact
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($request, $scope, $id, $deleted, $data): Contact {
            $contact = Contact::withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            $actor = $this->context->actor($request);
            abort_unless($this->access->read($actor, $scope, $contact), 404);
            abort_unless($this->access->manage($actor, $scope, $contact), 403);
            abort_unless($contact->revision === $data['revision'], 409, 'Контакт уже изменён.');
            $contact->update(['revision' => $contact->revision + 1]);
            if ($deleted) {
                $contact->delete();
            } else {
                $contact->restore();
            }
            $this->audit($request, $scope, $contact, $deleted ? 'deleted' : 'restored');

            return $contact;
        });
    }

    public function notes(Request $request, Scope $scope, Contact $contact): JsonResponse
    {
        abort_unless($this->access->read($this->context->actor($request), $scope, $contact), 404);

        return response()->json($contact->notes()->with('creator:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->paginate(30));
    }

    public function storeNote(SaveContactNoteRequest $request, Scope $scope, Contact $contact): JsonResponse
    {
        abort_unless($this->access->read($this->context->actor($request), $scope, $contact), 404);
        abort_unless($this->access->write($this->context->actor($request), $scope, $contact), 403);
        $note = DB::transaction(function () use ($request, $scope, $contact): ContactNote {
            $note = $contact->notes()->create([...$request->validated(), 'occurred_at' => $request->input('occurred_at', now()), 'created_by' => $this->context->actor($request)->id]);
            $this->audit($request, $scope, $contact, 'note_created');

            return $note;
        });

        return response()->json(['data' => $note->load('creator:id,name')], 201);
    }

    public function destroyNote(Request $request, Scope $scope, Contact $contact, ContactNote $note): Response
    {
        $actor = $this->context->actor($request);
        abort_unless($note->contact_id === $contact->id && $this->access->read($actor, $scope, $contact), 404);
        abort_unless($this->access->write($actor, $scope, $contact) && ($note->created_by === $actor->id || $this->access->manage($actor, $scope, $contact)), 403);
        DB::transaction(function () use ($request, $scope, $contact, $note): void {
            $note->delete();
            $this->audit($request, $scope, $contact, 'note_deleted');
        });

        return response()->noContent();
    }

    private function audit(Request $request, Scope $scope, Contact $contact, string $action): void
    {
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $this->context->actor($request)->id, 'subject_type' => 'contact', 'subject_id' => $contact->id, 'action' => 'contact.'.$action, 'after' => ['revision' => $contact->revision], 'context' => [...$this->context->auditMetadata($request), 'values_redacted' => true]]);
    }
}
