<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreEntityLinkRequest;
use App\Http\Resources\EntityLinkResource;
use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Contact;
use App\Models\EntityLink;
use App\Models\Event;
use App\Models\Fact;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use App\Services\DarklogLinkService;
use App\Services\PrivateRecordAccessService;
use App\Services\ScopeModuleService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class EntityLinkController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context, private readonly PrivateRecordAccessService $privateRecords, private readonly DarklogLinkService $targets) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $query = $scope->entityLinks()->where('source_type', '!=', 'thing')->where('target_type', '!=', 'thing')->with(['source', 'target'])->latest();
        $actor = $this->context->actor($request);
        foreach (['source', 'target'] as $side) {
            $query->where(function ($q) use ($side, $actor, $scope): void {
                $q->whereNotIn($side.'_type', ['fact', 'contact'])
                    ->orWhereHasMorph($side, [Fact::class, Contact::class], function ($q, $class) use ($actor, $scope): void {
                        $q->whereIn($q->getModel()->getTable().'.id', $this->privateRecords->query($class, $actor, $scope)->select('id'));
                    });
            });
        }
        $books = $this->access->constrainBooks(Book::where('scope_id', $scope->id), $actor, $scope)->select('books.id');
        $projects = $this->access->constrainProjects(Project::where('scope_id', $scope->id), $actor, $scope)->select('projects.id');
        $readable = [
            Task::class => $this->access->constrainTasks(Task::where('scope_id', $scope->id), $actor, $scope)->select('tasks.id'),
            Project::class => clone $projects,
            Book::class => clone $books,
            BookPage::class => BookPage::whereIn('book_id', clone $books)->select('id'),
            BookBlockGroup::class => BookBlockGroup::whereHas('page', fn ($q) => $q->whereIn('book_id', clone $books))->select('id'),
            Event::class => Event::where('scope_id', $scope->id)->where(fn ($q) => $q->whereIn('project_id', clone $projects)->when($this->access->canAccessUnprojected($actor, $scope), fn ($q) => $q->orWhereNull('project_id')))
                ->where(fn ($q) => $q->where('visibility', '!=', 'private')->orWhere('created_by', $actor->id)->when($scope->owner_id === $actor->id, fn ($q) => $q->orWhereRaw('1 = 1')))->select('id'),
        ];
        $types = array_map(fn ($class) => (new $class)->getMorphClass(), array_keys($readable));
        foreach (['source', 'target'] as $side) {
            $query->where(fn ($q) => $q->whereNotIn($side.'_type', $types)->orWhereHasMorph($side, array_keys($readable), fn ($q, $class) => $q->whereIn($q->getModel()->getTable().'.id', clone $readable[$class])));
        }
        $disabled = array_keys(array_filter(Relation::morphMap(), fn ($class, $type): bool => ($module = ScopeModuleService::entityModule($type)) !== null && ! $scope->moduleEnabled($module), ARRAY_FILTER_USE_BOTH));
        $bookTypes = ['book', 'book_page', 'book_block_group'];
        $disabled = array_values(array_diff($disabled, $bookTypes));
        $query->whereNotIn('source_type', $disabled)->whereNotIn('target_type', $disabled);
        foreach (['source', 'target'] as $side) {
            $query->where(function ($sideQuery) use ($side, $scope, $bookTypes): void {
                $sideQuery->whereNotIn($side.'_type', $bookTypes)
                    ->orWhereHasMorph($side, [Book::class, BookPage::class, BookBlockGroup::class], function ($books, $type) use ($scope): void {
                        $available = function ($query) use ($scope): void {
                            if (! $scope->moduleEnabled('booker')) {
                                $query->where('kind', 'lightbook');
                            }
                            if (! $scope->moduleEnabled('content')) {
                                $query->where('kind', '!=', 'lightbook');
                            }
                        };
                        if ($type === Book::class) {
                            $available($books);
                        } else {
                            $books->whereHas($type === BookPage::class ? 'book' : 'page.book', $available);
                        }
                    });
            });
        }
        if ($request->filled(['subject_type', 'subject_id'])) {
            $type = (string) $request->query('subject_type');
            $id = (string) $request->query('subject_id');
            $query->where(fn ($links) => $links
                ->where(fn ($side) => $side->where('source_type', $type)->where('source_id', $id))
                ->orWhere(fn ($side) => $side->where('target_type', $type)->where('target_id', $id)));
        }

        return EntityLinkResource::collection($query->paginate(100));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function candidates(Request $request, Scope $scope): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:fact,contact,task,project,event,book,book_page'], 'q' => ['nullable', 'string', 'max:200']]);

        return response()->json(['data' => $this->targets->candidates($this->context->actor($request), $scope, $data['type'], $data['q'] ?? '')]);
    }

    public function store(StoreEntityLinkRequest $request, Scope $scope): EntityLinkResource
    {
        $data = $request->validated();
        foreach (['source', 'target'] as $side) {

            $model = Relation::getMorphedModel($data["{$side}_type"]);
            abort_unless($model !== null && $model::query()->whereKey($data["{$side}_id"])->where('scope_id', $scope->id)->exists(), 422, "Invalid {$side} for this scope.");
            $entity = $model::query()->find($data["{$side}_id"]);
            abort_unless(ScopeModuleService::entityEnabled($scope, $entity), 403, 'Модуль отключён владельцем скоупа.');
            if ($entity instanceof Fact || $entity instanceof Contact) {
                $actor = $this->context->actor($request);
                abort_unless($this->privateRecords->read($actor, $scope, $entity), 404);
                if ($side === 'source') {
                    abort_unless($this->privateRecords->write($actor, $scope, $entity), 403);
                }
            }
            $book = $entity instanceof Book ? $entity : ($entity instanceof BookPage ? $entity->book : ($entity instanceof BookBlockGroup ? $entity->page?->book : null));
            if ($book !== null) {
                abort_unless($this->access->canAccessBook($this->context->actor($request), $scope, $book->loadMissing('project')), 403, 'This book is outside the contractor access boundary.');
            }
            $project = $entity instanceof Project ? $entity : ($entity instanceof Task ? $entity->project : null);
            if ($project !== null) {
                abort_unless($this->access->canAccessProject($this->context->actor($request), $scope, $project, 'task.view'), 403, 'This entity is outside the contractor project boundary.');
            }
            if ($entity instanceof Event) {
                abort_unless($this->targets->canView($this->context->actor($request), $scope, $entity), 404);
            }
            if ($entity instanceof Task && $entity->project_id === null) {
                abort_unless($this->access->canAccessUnprojected($this->context->actor($request), $scope), 403, 'Unprojected tasks are outside the contractor access boundary.');
            }
            if ($entity instanceof Task) {
                $entity->assertEditable();
            }
        }
        $link = $scope->entityLinks()->create([...$data, 'created_by' => $request->user()->id]);

        return new EntityLinkResource($link->load(['source', 'target']));
    }

    public function destroy(Request $request, Scope $scope, EntityLink $link): Response
    {
        abort_unless($link->scope_id === $scope->id && $link->source_type !== 'thing' && $link->target_type !== 'thing', 404);
        foreach (['source', 'target'] as $side) {
            $entity = $link->{$side};
            abort_unless($entity && ScopeModuleService::entityEnabled($scope, $entity), 403, 'Модуль отключён владельцем скоупа.');
            if ($entity instanceof Fact || $entity instanceof Contact) {
                $actor = $this->context->actor($request);
                abort_unless($this->privateRecords->read($actor, $scope, $entity), 404);
                if ($side === 'source') {
                    abort_unless($this->privateRecords->write($actor, $scope, $entity), 403);
                }
            } elseif (isset(DarklogLinkService::TARGETS[$link->{$side.'_type'}])) {
                abort_unless($this->targets->canView($this->context->actor($request), $scope, $entity), 404);
            }
            if ($entity instanceof BookBlockGroup) {
                abort_unless($entity->page?->book && $this->access->canAccessBook($this->context->actor($request), $scope, $entity->page->book->loadMissing('project')), 404);
            }
            if ($entity instanceof Task) {
                abort_unless($this->access->canAccessTask($this->context->actor($request), $scope, $entity, 'task.update'), 404);
                $entity->assertEditable();
            }
        }
        $link->delete();

        return response()->noContent();
    }

    /**
     * Display the specified resource.
     */
}
