<?php

namespace App\Providers;

use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Contact;
use App\Models\Darklog;
use App\Models\Diagram;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Fact;
use App\Models\Kpi;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Thing;
use App\Models\User;
use App\Services\PrivateRecordAccessService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PrivateRecordAccessService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::authenticateAccessTokensUsing(fn (PersonalAccessToken $token, bool $isValid): bool => $isValid && $token->revoked_at === null);
        Model::preventLazyLoading(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'scope' => Scope::class,
            'thing' => Thing::class,
            'diagram' => Diagram::class,
            'darklog' => Darklog::class,
            'book' => Book::class,
            'book_block_group' => BookBlockGroup::class,
            'book_page' => BookPage::class,
            'event' => Event::class,
            'event_section' => EventSection::class,
            'contact' => Contact::class,
            'fact' => Fact::class,
            'project' => Project::class,
            'kpi' => Kpi::class,
            'task' => Task::class,
            'task_checklist_item' => TaskChecklistItem::class,
            'user' => User::class,
        ]);

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('identity')).'|'.$request->ip());
        });
    }
}
