<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringApiTest extends TestCase
{
    public function test_guest_receives_401_without_contacting_service(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->withHeaders(['X-App-Request' => 'Zuratax'])->getJson('/api/monitoring/storage')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_active_user_receives_metrics_without_internal_fields(): void
    {
        config(['monitoring.token' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:9187/v1/storage' => Http::response([
            'host' => 'main', 'checked_at' => '2026-09-22T12:00:00Z',
            'volumes' => [['name' => 'data', 'total_bytes' => 100, 'used_bytes' => 30,
                'available_bytes' => 65, 'reserved_bytes' => 5, 'used_percent' => 30,
                'inodes_available_percent' => 90, 'status' => 'ok', 'secret_path' => '/private']],
        ])]);
        $this->actingAs(User::factory()->make(['id' => 'test-user', 'status' => 'active', 'is_active' => true]))
            ->withHeaders(['X-App-Request' => 'Zuratax'])->getJson('/api/monitoring/storage')
            ->assertOk()->assertJsonPath('data.volumes.0.available_bytes', 65)
            ->assertJsonMissingPath('data.volumes.0.secret_path');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_unavailable_service_returns_503(): void
    {
        config(['monitoring.token' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:9187/v1/storage' => Http::failedConnection()]);
        $this->actingAs(User::factory()->make(['id' => 'test-user', 'status' => 'active', 'is_active' => true]))
            ->withHeaders(['X-App-Request' => 'Zuratax'])->getJson('/api/monitoring/storage')
            ->assertStatus(503)->assertJsonPath('message', 'Служба мониторинга недоступна.');
        Http::assertSentCount(1);
    }

    public function test_invalid_payload_returns_503(): void
    {
        config(['monitoring.token' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:9187/v1/storage' => Http::response(['volumes' => 'broken'])]);
        $this->actingAs(User::factory()->make(['id' => 'test-user', 'status' => 'active', 'is_active' => true]))
            ->withHeaders(['X-App-Request' => 'Zuratax'])->getJson('/api/monitoring/storage')
            ->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_unconfigured_service_returns_503_without_http_call(): void
    {
        config(['monitoring.token' => null, 'monitoring.token_file' => '/missing/metrics-token']);
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->make(['id' => 'test-user', 'status' => 'active', 'is_active' => true]))
            ->withHeaders(['X-App-Request' => 'Zuratax'])->getJson('/api/monitoring/storage')
            ->assertStatus(503)->assertJsonPath('message', 'Служба мониторинга ещё не настроена.');
        Http::assertNothingSent();
    }
}
