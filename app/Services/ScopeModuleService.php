<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Model;

class ScopeModuleService
{
    /** @return array<string, array<string, mixed>> */
    public static function catalog(): array
    {
        return [
            'home' => ['label' => 'Главная', 'path' => '/', 'required' => true],
            'tasker' => ['label' => 'Tasker', 'path' => '/tasks'],
            'planner' => ['label' => 'Planner', 'path' => '/plans', 'requires' => ['tasker']],
            'calendar' => ['label' => 'Календарь', 'path' => '/planner', 'requires' => ['tasker']],
            'projector' => ['label' => 'Projector', 'path' => '/projects', 'required' => true],
            'kpi' => ['label' => 'KPI', 'path' => '/kpi', 'requires' => ['tasker']],
            'reporter' => ['label' => 'Reporter', 'path' => '/reports', 'requires' => ['tasker']],
            'eventor' => ['label' => 'Eventor', 'path' => '/events'],
            'contactor' => ['label' => 'Contactor · Контакты', 'path' => '/contacts'],
            'factor' => ['label' => 'Factor', 'path' => '/factor'],
            'darklog' => ['label' => 'Darklog', 'path' => '/darklog'],
            'lore' => ['label' => 'Lore', 'path' => '/lore'],
            'booker' => ['label' => 'Booker', 'path' => '/books'],
            'content' => ['label' => 'Контент · схемы и Lightbook', 'path' => '/content'],
            'equip' => ['label' => 'Equip · Экипа', 'path' => '/equip'],
            'ledger' => ['label' => 'Ledger · Финансы', 'path' => '/ledger'],
            'exploiter' => ['label' => 'Exploiter · Эксплуатация', 'path' => '/exploiter', 'requires' => ['equip']],
            'contractors' => ['label' => 'Пользователи', 'path' => '/contractors', 'required' => true],
            'departments' => ['label' => 'Отделы', 'path' => '/departments', 'required' => true],
        ];
    }

    public static function enabled(Scope $scope, string $module): bool
    {
        $definition = self::catalog()[$module] ?? null;
        if ($definition === null) {
            return false;
        }
        if ($definition['required'] ?? false) {
            return true;
        }
        if (($scope->settings['modules']['enabled'][$module] ?? true) === false) {
            return false;
        }
        foreach ($definition['requires'] ?? [] as $dependency) {
            if (! self::enabled($scope, $dependency)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> */
    public static function sortable(): array
    {
        return array_values(array_diff(array_keys(self::catalog()), ['home', 'contractors', 'departments']));
    }

    /** @return array{enabled: array<string, bool>, order: array<int, string>} */
    public static function settings(Scope $scope): array
    {
        $enabled = [];
        foreach (self::catalog() as $id => $definition) {
            $enabled[$id] = self::enabled($scope, $id);
        }
        $saved = $scope->settings['modules']['order'] ?? [];
        $order = array_values(array_unique(array_merge(array_intersect(is_array($saved) ? $saved : [], self::sortable()), self::sortable())));

        return ['enabled' => $enabled, 'order' => $order];
    }

    public static function entityModule(string $type): ?string
    {
        return match ($type) {
            'task' => 'tasker', 'plan_item', 'monthly_task_plan' => 'planner',
            'kpi', 'kpi_profile' => 'kpi', 'monthly_report' => 'reporter',
            'event', 'event_section' => 'eventor', 'contact' => 'contactor', 'fact' => 'factor', 'darklog' => 'darklog',
            'lore', 'lore_entry', 'lore_revision' => 'lore',
            'book', 'book_page', 'book_block', 'book_block_group' => 'booker',
            'diagram', 'lightbook' => 'content', 'thing' => 'equip',
            default => null,
        };
    }

    public static function entityEnabled(Scope $scope, Model $record): bool
    {
        $book = match (class_basename($record)) {
            'Book' => $record,
            'BookPage' => $record->book,
            'BookBlockGroup' => $record->page?->book,
            'BookBlock' => $record->group?->page?->book,
            default => null,
        };
        if ($book instanceof Book) {
            return self::enabled($scope, $book->kind === 'lightbook' ? 'content' : 'booker');
        }
        $module = $record instanceof Book && $record->kind === 'lightbook'
            ? 'content' : self::entityModule(match (class_basename($record)) {
                'Task', 'TaskChecklistItem' => 'task', 'PlanItem' => 'plan_item', 'MonthlyReport' => 'monthly_report',
                'Kpi', 'KpiProfile' => 'kpi', 'Event', 'EventSection' => 'event', 'Contact' => 'contact', 'Fact' => 'fact',
                'Darklog' => 'darklog', 'LoreEntry', 'LoreRevision' => 'lore',
                'Book', 'BookPage', 'BookBlock', 'BookBlockGroup' => 'book',
                'Diagram' => 'diagram', 'Thing' => 'thing', default => '',
            });

        return $module === null || self::enabled($scope, $module);
    }
}
