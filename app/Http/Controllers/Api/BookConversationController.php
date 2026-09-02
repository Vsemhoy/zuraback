<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTaskCommentRequest;
use App\Http\Resources\BookCommentResource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Comment;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BookConversationController extends Controller
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function recent(Request $request, Scope $scope): AnonymousResourceCollection
    {
        $actor = $this->context->actor($request);
        $bookIds = $this->access->constrainBooks($scope->books()->getQuery(), $actor, $scope)->pluck('id');
        $comments = Comment::query()
            ->where('scope_id', $scope->id)
            ->where('commentable_type', 'book_page')
            ->whereHasMorph('commentable', [BookPage::class], fn (Builder $pages) => $pages->whereIn('book_id', $bookIds))
            ->with(['creator:id,name', 'commentable.book:id,title'])
            ->latest('created_at')
            ->latest('id')
            ->limit(15)
            ->get();

        return BookCommentResource::collection($comments);
    }

    public function comments(Request $request, Scope $scope, Book $book, BookPage $bookPage): AnonymousResourceCollection
    {
        $this->assertPage($request, $scope, $book, $bookPage);

        return BookCommentResource::collection($bookPage->comments()->with(['creator:id,name', 'commentable.book:id,title'])->oldest()->get());
    }

    public function storeComment(StoreTaskCommentRequest $request, Scope $scope, Book $book, BookPage $bookPage): BookCommentResource
    {
        $this->assertPage($request, $scope, $book, $bookPage);
        abort_unless($book->comments_enabled, 403, 'Comments are disabled for this book.');
        $data = $request->validated();
        if (! empty($data['parent_id'])) {
            abort_unless($bookPage->comments()->whereKey($data['parent_id'])->exists(), 422, 'The parent comment must belong to this page.');
        }
        $comment = $bookPage->comments()->create([...$data, 'scope_id' => $scope->id, 'created_by' => $this->context->actor($request)->id]);

        return new BookCommentResource($comment->load(['creator:id,name', 'commentable.book:id,title']));
    }

    public function destroy(Request $request, Scope $scope, Book $book, BookPage $bookPage, Comment $comment): Response
    {
        $this->assertPage($request, $scope, $book, $bookPage);
        abort_unless($comment->commentable_type === 'book_page' && $comment->commentable_id === $bookPage->id, 404);
        $actor = $this->context->actor($request);
        abort_unless($comment->created_by === $actor->id || $book->created_by === $actor->id || $scope->owner_id === $actor->id, 403);
        $comment->delete();

        return response()->noContent();
    }

    private function assertPage(Request $request, Scope $scope, Book $book, BookPage $bookPage): void
    {
        abort_unless($book->scope_id === $scope->id && $bookPage->book_id === $book->id, 404);
        abort_unless($this->access->canAccessBook($this->context->actor($request), $scope, $book->loadMissing('project')), 403);
    }
}
