<?php

namespace App\Providers;

use App\Models\Book;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Fact;
use App\Models\Project;
use App\Models\ResponsibilityArea;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Relation::enforceMorphMap([
            'book' => Book::class,
            'book_block_group' => BookBlockGroup::class,
            'book_page' => BookPage::class,
            'event' => Event::class,
            'event_section' => EventSection::class,
            'fact' => Fact::class,
            'project' => Project::class,
            'responsibility_area' => ResponsibilityArea::class,
            'task' => Task::class,
            'task_checklist_item' => TaskChecklistItem::class,
            'user' => User::class,
        ]);

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('identity')).'|'.$request->ip());
        });
    }
}
