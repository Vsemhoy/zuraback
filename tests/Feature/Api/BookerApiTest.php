<?php
namespace Tests\Feature\Api;
use App\Models\Scope; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class BookerApiTest extends TestCase {
 use RefreshDatabase; private const HEADERS=['Accept'=>'application/json','Content-Type'=>'application/json','X-App-Request'=>'Zuratax'];
 public function test_booker_tree_and_block_versions_work(): void {
  $user=User::factory()->create(); $scope=Scope::query()->create(['owner_id'=>$user->id,'name'=>'Work','slug'=>'work']); $scope->members()->create(['user_id'=>$user->id,'role'=>'owner','joined_at'=>now()]);
  $space=$this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/book-spaces",['title'=>'Knowledge'])->assertCreated()->json('data');
  $book=$this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books",['title'=>'Runbooks','space_id'=>$space['id']])->assertCreated()->json('data');
  $page=$this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages",['title'=>'1C recovery'])->assertCreated()->json('data');
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/editing")
   ->assertOk()->assertJsonPath('data.editing_by',$user->id)->assertJsonPath('data.versions_count',1);
  $versions=$this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/versions")
   ->assertOk()->assertJsonCount(1,'data')->assertJsonMissingPath('data.0.snapshot')->json('data');
  $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/versions/{$versions[0]['id']}")
   ->assertOk()->assertJsonPath('data.version_number',1)->assertJsonPath('data.snapshot.format','booker-page')->assertJsonPath('data.snapshot.page.title','1C recovery');
  $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}",['title'=>'Changed during editing'])->assertOk();
  $group=$this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/blocks",['type'=>'markdown','content'=>'# Version one'])->assertCreated()->json('data');
  $divider=$this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/blocks",['type'=>'divider','sort_order'=>2])->assertCreated()->json('data');
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/blocks/reorder",['items'=>[['id'=>$divider['id'],'sort_order'=>1],['id'=>$group['id'],'sort_order'=>2]]])->assertOk();
  $this->assertDatabaseHas('book_block_groups',['id'=>$divider['id'],'sort_order'=>1]);
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/blocks/{$group['id']}/versions",['content'=>'# Version two','status'=>'published'])
   ->assertCreated()->assertJsonPath('data.master_block.version_number',2)->assertJsonPath('data.master_block.content','# Version two');
  $this->assertDatabaseCount('book_blocks',3);
  $this->assertDatabaseCount('book_page_versions',1);
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book['id']}/pages/{$page['id']}/editing/cancel",[])
   ->assertOk()->assertJsonPath('data.title','1C recovery')->assertJsonPath('data.editing_by',null)->assertJsonPath('data.versions_count',0)->assertJsonCount(0,'data.groups');
  $this->assertDatabaseCount('book_page_versions',0);
 }

 public function test_page_editing_is_locked_for_another_user(): void {
  $owner=User::factory()->create(); $other=User::factory()->create(); $scope=Scope::query()->create(['owner_id'=>$owner->id,'name'=>'Work','slug'=>'work']);
  $scope->members()->create(['user_id'=>$owner->id,'role'=>'owner','joined_at'=>now()]); $scope->members()->create(['user_id'=>$other->id,'role'=>'member','joined_at'=>now()]);
  $book=$scope->books()->create(['created_by'=>$owner->id,'title'=>'Locked']); $page=$book->pages()->create(['created_by'=>$owner->id,'title'=>'Page']);
  $this->actingAs($owner)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/editing")->assertOk();
  $this->actingAs($other)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/editing")
   ->assertStatus(423)->assertJsonPath('editor.name',$owner->name);
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/blocks",['type'=>'markdown','content'=>'intrusion'])
   ->assertStatus(423);
 }

 public function test_page_version_can_be_restored_and_the_previous_state_is_preserved(): void {
  $user=User::factory()->create(); $scope=Scope::query()->create(['owner_id'=>$user->id,'name'=>'Work','slug'=>'work']); $scope->members()->create(['user_id'=>$user->id,'role'=>'owner','joined_at'=>now()]);
  $book=$scope->books()->create(['created_by'=>$user->id,'title'=>'Runbooks','version_depth'=>25]); $page=$book->pages()->create(['created_by'=>$user->id,'title'=>'Original']);
  $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/editing")->assertOk();
  $versionId=$this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/versions")->assertOk()->json('data.0.id');
  $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}",['title'=>'Changed'])->assertOk();
  $group=$this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/blocks",['type'=>'markdown','content'=>'New content'])->assertCreated()->json('data');
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/versions/{$versionId}/restore")
   ->assertOk()->assertJsonPath('data.title','Original')->assertJsonPath('data.editing_by',null)->assertJsonPath('data.versions_count',2)->assertJsonCount(0,'data.groups');
  $safetyVersionId=$this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/versions")->assertOk()->json('data.0.id');
  $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/books/{$book->id}/pages/{$page->id}/versions/{$safetyVersionId}/restore")
   ->assertOk()->assertJsonPath('data.title','Changed')->assertJsonPath('data.groups.0.id',$group['id'])->assertJsonPath('data.groups.0.master_block.content','New content')->assertJsonPath('data.versions_count',3);
 }
}
