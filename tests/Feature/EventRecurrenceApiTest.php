<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EventRecurrenceApiTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $user = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $user->id]);
        $this->actingAs($user)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$user, $scope, "/api/scopes/{$scope->id}/events"];
    }

    private function recurring(User $user, Scope $scope, array $attributes = []): Event
    {
        return Event::factory()->create([...['scope_id' => $scope->id, 'created_by' => $user->id, 'visibility' => 'scope', 'status' => 'published', 'starts_at' => '2026-01-31 09:00:00', 'recurrence_frequency' => 'monthly'], ...$attributes]);
    }

    public function test_monthly_series_has_one_record_and_clamps_short_month_without_drift(): void
    {
        [, , $url] = $this->workspace();
        $id = $this->postJson($url, ['title' => 'Internet payment', 'starts_at' => '2026-01-31T09:00:00+03:00', 'ends_at' => '2026-01-31T10:00:00+03:00',
            'recurrence_frequency' => 'monthly', 'recurrence_timezone' => 'Europe/Moscow', 'recurrence_until' => '2026-03-31'])->assertCreated()->json('data.id');
        $data = $this->getJson($url.'/calendar?from=2026-01-01&until=2026-03-31&timezone=Europe%2FMoscow')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame(['2026-01-31T06:00:00+00:00', '2026-02-28T06:00:00+00:00', '2026-03-31T06:00:00+00:00'], array_column($data, 'occurrence_start'));
        $this->assertSame('2026-02-28T07:00:00+00:00', $data[1]['occurrence_end']);
        $this->assertSame([$id, $id, $id], array_column($data, 'id'));
        $this->assertCount(3, array_unique(array_column($data, 'occurrence_id')));
        $this->getJson($url.'/calendar?from=2026-04-01&until=2026-04-30')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('events', 1);
    }

    public function test_yearly_leap_day_and_timezone_boundary_are_preserved(): void
    {
        [$user, $scope, $url] = $this->workspace();
        $this->recurring($user, $scope, ['starts_at' => '2024-02-28 21:30:00', 'recurrence_timezone' => 'Europe/Moscow', 'recurrence_frequency' => 'yearly']);
        $this->getJson($url.'/calendar?from=2027-02-28&until=2027-02-28&timezone=Europe%2FMoscow')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.occurrence_start', '2027-02-27T21:30:00+00:00');
        $this->getJson($url.'/calendar?from=2028-02-29&until=2028-02-29&timezone=Europe%2FMoscow')->assertOk()
            ->assertJsonPath('data.0.occurrence_start', '2028-02-28T21:30:00+00:00');
    }

    public function test_dst_keeps_series_local_wall_time(): void
    {
        [$user, $scope, $url] = $this->workspace();
        $this->recurring($user, $scope, ['starts_at' => '2026-01-15 08:00:00', 'recurrence_timezone' => 'Europe/Berlin']);
        $this->getJson($url.'/calendar?from=2026-03-01&until=2026-04-30')->assertOk()
            ->assertJsonPath('data.0.occurrence_start', '2026-03-15T08:00:00+00:00')
            ->assertJsonPath('data.1.occurrence_start', '2026-04-15T07:00:00+00:00');
    }

    public function test_calendar_does_not_show_before_start_or_archived_series_and_includes_last_day(): void
    {
        [$user, $scope, $url] = $this->workspace();
        $this->recurring($user, $scope, ['starts_at' => '2026-11-01 09:00:00']);
        $this->recurring($user, $scope, ['status' => 'archived']);
        $single = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'starts_at' => '2026-10-31 23:59:00']);
        $this->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $single->id);
    }

    public function test_personal_and_foreign_project_series_are_not_exposed(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'is_active' => true, 'project_access_mode' => 'all']);
        $this->recurring($owner, $scope, ['visibility' => 'private']);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $this->recurring($owner, $scope, ['project_id' => $project->id]);
        $foreign = Scope::factory()->create();
        $this->recurring($owner, $foreign);
        $shared = $this->recurring($owner, $scope);
        $this->actingAs($member)->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared->id);
        $this->getJson("/api/scopes/{$foreign->id}/events/calendar?from=2026-10-01&until=2026-10-31")->assertForbidden();
    }

    public function test_soft_deleting_employee_stops_linked_series_but_preserves_record(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $person = User::factory()->create();
        $scope->members()->create(['user_id' => $person->id, 'role' => 'member', 'is_active' => true, 'project_access_mode' => 'all']);
        $id = $this->postJson($url, ['title' => 'Birthday', 'starts_at' => '2026-10-02T09:00:00Z', 'recurrence_frequency' => 'yearly', 'recurrence_user_id' => $person->id])->assertCreated()->json('data.id');
        $this->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/scopes/{$scope->id}/contractors/{$person->id}")->assertNoContent();
        $this->assertSoftDeleted($person);
        $this->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('events', ['id' => $id, 'recurrence_user_id' => $person->id, 'deleted_at' => null]);
        $this->patchJson("{$url}/{$id}", ['title' => 'Historical birthday'])->assertOk();
    }

    public function test_inactive_scope_membership_stops_linked_series(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $person = User::factory()->create();
        $scope->members()->create(['user_id' => $person->id, 'role' => 'member', 'is_active' => false]);
        $this->recurring($owner, $scope, ['recurrence_user_id' => $person->id]);
        $this->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_partial_patch_validates_existing_anchor_and_preserves_schedule(): void
    {
        [$user, $scope, $url] = $this->workspace();
        $event = $this->recurring($user, $scope, ['recurrence_until' => '2026-12-31']);
        $this->patchJson("{$url}/{$event->id}", ['content' => 'Updated'])->assertOk()->assertJsonPath('data.recurrence_frequency', 'monthly');
        $this->patchJson("{$url}/{$event->id}", ['recurrence_until' => '2025-12-31'])->assertUnprocessable()->assertJsonValidationErrors('recurrence_until');
        $this->patchJson("{$url}/{$event->id}", ['starts_at' => null])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->assertDatabaseHas('events', ['id' => $event->id, 'recurrence_until' => '2026-12-31']);
        $this->patchJson("{$url}/{$event->id}", ['recurrence_frequency' => null])->assertOk();
        $this->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
    }

    public static function invalidSchedules(): array
    {
        return [
            'missing anchor' => [['recurrence_frequency' => 'monthly'], 'starts_at'],
            'unsupported frequency' => [['recurrence_frequency' => 'daily'], 'recurrence_frequency'],
            'invalid timezone' => [['recurrence_timezone' => 'Not/AZone'], 'recurrence_timezone'],
            'ends before start' => [['starts_at' => '2026-10-01', 'ends_at' => '2026-09-01'], 'ends_at'],
        ];
    }

    #[DataProvider('invalidSchedules')]
    public function test_invalid_schedule_returns_422(array $payload, string $field): void
    {
        [, , $url] = $this->workspace();
        $this->postJson($url, ['title' => 'Invalid', ...$payload])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_foreign_employee_and_excessive_calendar_range_return_422(): void
    {
        [, , $url] = $this->workspace();
        $foreign = User::factory()->create();
        $this->postJson($url, ['title' => 'Invalid', 'starts_at' => '2026-10-01', 'recurrence_frequency' => 'yearly', 'recurrence_user_id' => $foreign->id])->assertUnprocessable();
        $this->getJson($url.'/calendar?from=2026-01-01&until=2026-12-31')->assertUnprocessable()->assertJsonValidationErrors('until');
        $this->getJson($url.'/calendar')->assertUnprocessable()->assertJsonValidationErrors(['from', 'until']);
    }

    public function test_calendar_paginates_series_without_duplicate_or_missing_occurrences(): void
    {
        [$user, $scope, $url] = $this->workspace();
        $first = $this->recurring($user, $scope);
        $second = $this->recurring($user, $scope);
        $query = '/calendar?from=2026-10-01&until=2026-10-31&per_page=1';
        $this->getJson($url.$query)->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $first->id);
        $this->getJson($url.$query.'&page=2')->assertOk()->assertJsonPath('data.0.id', $second->id);
    }

    public function test_no_type_filter_includes_null_and_legacy_none_type(): void
    {
        [$user, $scope, $url] = $this->workspace();
        $none = $scope->eventTypes()->create(['code' => 'none', 'name' => 'Без типа']);
        $other = $scope->eventTypes()->create(['code' => 'event', 'name' => 'Событие']);
        $this->recurring($user, $scope, ['type_id' => null]);
        $this->recurring($user, $scope, ['type_id' => $none->id]);
        $this->recurring($user, $scope, ['type_id' => $other->id]);
        $this->getJson($url.'?type_id='.$none->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31&type_id='.$none->id)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_restricted_member_does_not_see_unprojected_calendar_events(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'is_active' => true, 'project_access_mode' => 'restricted']);
        $this->recurring($owner, $scope);
        $this->actingAs($member)->getJson($url.'/calendar?from=2026-10-01&until=2026-10-31')->assertOk()->assertJsonCount(0, 'data');
    }
}
