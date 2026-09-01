<?php

namespace App\Http\Middleware;

use App\Models\Book;
use App\Models\Scope;
use App\Services\ContractorAccessService;
use App\Services\ContractorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBookAccess
{
    public function __construct(private readonly ContractorAccessService $access, private readonly ContractorContext $context) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $scope = $request->route('scope');
        $book = $request->route('book');
        abort_unless($scope instanceof Scope, 404);
        $actor = $this->context->actor($request);
        abort_unless($this->access->allows($actor, $scope, $ability), 403, "The {$ability} capability is required.");
        if ($book instanceof Book) {
            abort_unless($this->access->canAccessBook($actor, $scope, $book->loadMissing('project')), 403, 'This book is outside the contractor access boundary.');
        }

        return $next($request);
    }
}
