<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LightbookApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lightbook_has_one_versioned_commentable_page_and_is_separate_from_booker(): void
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);
        $base = "/api/scopes/{$scope->id}/lightbooks";

        $lightbook = $this->postJson($base, ['title' => 'Должностная инструкция', 'visibility' => 'scope'])
            ->assertCreated()->assertJsonPath('data.title', 'Должностная инструкция')->assertJsonPath('data.page.title', 'Должностная инструкция')->json('data');
        $this->assertDatabaseHas('books', ['id' => $lightbook['id'], 'kind' => 'lightbook', 'structure_mode' => 'flat']);
        $this->assertDatabaseCount('book_pages', 1);
        $this->getJson("/api/scopes/{$scope->id}/books")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.id', $lightbook['id']);

        $pageUrl = "$base/{$lightbook['id']}/pages/{$lightbook['page_id']}";
        $this->postJson($pageUrl.'/editing')->assertOk();
        $this->postJson($pageUrl.'/blocks', ['type' => 'markdown', 'content' => '# Обязанности'])->assertCreated();
        $this->patchJson($pageUrl, ['title' => 'Инструкция инженера'])->assertOk();
        $this->deleteJson($pageUrl.'/editing')->assertOk();
        $this->postJson($pageUrl.'/comments', ['content' => 'Нужно согласовать'])->assertCreated();
        $this->getJson($pageUrl.'/versions')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('books', ['id' => $lightbook['id'], 'title' => 'Инструкция инженера']);
        $this->assertDatabaseHas('comments', ['commentable_type' => 'book_page', 'commentable_id' => $lightbook['page_id'], 'content' => 'Нужно согласовать']);
    }

    public function test_agent_can_create_read_and_link_a_lightbook_in_the_document_graph(): void
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $agent = User::factory()->agent()->create(['created_by' => $owner->id]);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'observer', 'book_access_mode' => 'all', 'project_access_mode' => 'all',
            'permissions' => ['allow' => ['book.view', 'book.create', 'book.update'], 'deny' => []], 'joined_at' => now()]);
        Sanctum::actingAs($agent, ['book.view', 'book.create', 'book.update']);
        $base = "/api/agent/scopes/{$scope->id}";

        $lightbook = $this->postJson($base.'/lightbooks', ['title' => 'Регламент', 'visibility' => 'scope'])->assertCreated()->json('data');
        $node = $this->getJson($base."/content-nodes/lightbook/{$lightbook['id']}")
            ->assertOk()->assertJsonPath('data.type', 'lightbook')->assertJsonPath('data.document.page.title', 'Регламент')->json('data');
        $this->assertTrue($node['can_manage']);
        $this->getJson($base.'/content-nodes/targets?type=lightbook&q=Регламент')->assertOk()->assertJsonPath('data.0.id', $lightbook['id']);

        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'book']);
        $this->getJson($base."/content-nodes/lightbook/{$book->id}")->assertNotFound();
        $this->get('/api/agent/spec', ['Accept' => 'text/markdown'])->assertOk()->assertSee('## Lightbook API', false);
    }
}
