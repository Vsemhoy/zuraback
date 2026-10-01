<?php

namespace App\Services;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class EventRecurrenceService
{
    public function prepare(array $data, ?Event $event = null): array
    {
        $current = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : $event?->getAttribute($key);
        $start = $current('starts_at') ? CarbonImmutable::parse($current('starts_at')) : null;
        $end = $current('ends_at') ? CarbonImmutable::parse($current('ends_at')) : null;
        if ($start && $end && $end->lessThan($start)) {
            throw ValidationException::withMessages(['ends_at' => 'Окончание не может быть раньше начала.']);
        }
        if ($current('recurrence_frequency')) {
            if (! $start) {
                throw ValidationException::withMessages(['starts_at' => 'Укажите дату первого повторения.']);
            }
            $timezone = $current('recurrence_timezone') ?: 'UTC';
            if ($current('recurrence_until') && $current('recurrence_until') < $start->setTimezone($timezone)->toDateString()) {
                throw ValidationException::withMessages(['recurrence_until' => 'Конец повторений не может быть раньше первого события.']);
            }
        }
        foreach (['starts_at', 'ends_at', 'occurred_at'] as $key) {
            if (! empty($data[$key])) {
                $data[$key] = CarbonImmutable::parse($data[$key])->utc()->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /** @return array<int, Event> */
    public function occurrences(Event $event, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $anchor = CarbonImmutable::parse($event->starts_at ?? $event->occurred_at ?? $event->created_at)
            ->setTimezone($event->recurrence_timezone ?: 'UTC');
        if (! $event->recurrence_frequency) {
            return $anchor->betweenIncluded($from, $until) ? [$this->instance($event, $anchor)] : [];
        }
        if ($event->status === 'archived' || ! $event->starts_at) {
            return [];
        }
        $localFrom = $from->setTimezone($anchor->timezone);
        $months = ($localFrom->year - $anchor->year) * 12 + $localFrom->month - $anchor->month;
        $step = $event->recurrence_frequency === 'yearly' ? 12 : 1;
        $offset = max(0, (int) floor($months / $step) - 1);
        $rows = [];
        while (true) {
            // Anchor every occurrence to the original date so February cannot shift March's day.
            $date = $anchor->addMonthsNoOverflow($offset * $step);
            if ($date->greaterThan($until) || ($event->recurrence_until && $date->toDateString() > $event->recurrence_until)) {
                break;
            }
            if ($date->greaterThanOrEqualTo($from)) {
                $rows[] = $this->instance($event, $date);
            }
            $offset++;
        }

        return $rows;
    }

    private function instance(Event $event, CarbonImmutable $start): Event
    {
        $instance = clone $event;
        $instance->setAttribute('occurrence_id', $event->id.':'.$start->utc()->format('Y-m-d\TH:i:s'));
        $instance->setAttribute('occurrence_start', $start->utc()->toIso8601String());
        $duration = $event->starts_at && $event->ends_at ? $event->ends_at->timestamp - $event->starts_at->timestamp : null;
        $instance->setAttribute('occurrence_end', $duration === null ? null : $start->addSeconds($duration)->utc()->toIso8601String());

        return $instance;
    }
}
