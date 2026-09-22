<?php

namespace Tests\Feature;

use App\Jobs\GenerateFilerPreview;
use App\Models\FilerFile;
use App\Models\Scope;
use App\Models\ScopeMember;
use App\Models\User;
use App\Services\FilerPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class FilerPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(string $name = 'guide.docx'): array
    {
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $file = FilerFile::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'name' => $name]);
        $this->actingAs($owner)->withHeader('X-App-Request', 'Zuratax');

        return [$file, $scope, "/api/scopes/{$scope->id}/files/{$file->id}"];
    }

    public function test_description_can_be_added_cleared_and_does_not_change_other_fields(): void
    {
        [$file, $scope, $url] = $this->prepare();
        $this->patchJson($url, ['description' => 'Инструкция для коллег', 'visibility' => 'scope', 'path' => 'bad'])
            ->assertOk()->assertJsonPath('data.description', 'Инструкция для коллег');
        $this->assertDatabaseHas('filer_files', ['id' => $file->id, 'description' => 'Инструкция для коллег', 'visibility' => 'private', 'path' => $file->path]);
        $this->getJson("/api/scopes/{$scope->id}/files")->assertJsonPath('data.0.description', 'Инструкция для коллег');
        $this->patchJson($url, ['description' => null])->assertOk();
        $this->assertNull($file->fresh()->description);
        $this->patchJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->patchJson($url, ['description' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->patchJson($url, ['description' => ['no']])->assertUnprocessable();
    }

    public function test_private_and_cross_scope_preview_and_description_are_hidden(): void
    {
        [$file, $scope, $url] = $this->prepare();
        $other = Scope::factory()->create(['owner_id' => $scope->owner_id]);
        $cross = "/api/scopes/{$other->id}/files/{$file->id}";
        $this->getJson($cross.'/preview')->assertNotFound();
        $this->postJson($cross.'/preview')->assertNotFound();
        $this->getJson($cross.'/preview/content')->assertNotFound();
        $this->patchJson($cross, ['description' => 'no'])->assertNotFound();
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'project_access_mode' => 'all']);
        $this->actingAs($colleague)->getJson($url.'/preview')->assertNotFound();
        $this->postJson($url.'/preview')->assertNotFound();
        $this->getJson($url.'/preview/content')->assertNotFound();
        $this->patchJson($url, ['description' => 'no'])->assertNotFound();
    }

    public function test_preview_request_is_deduplicated_and_queue_failure_is_reported(): void
    {
        [$file, , $url] = $this->prepare();
        Queue::fake([GenerateFilerPreview::class]);
        $this->partialMock(FilerPreviewService::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(true));
        $this->postJson($url.'/preview')->assertStatus(202)->assertJsonPath('data.status', 'pending');
        $this->postJson($url.'/preview')->assertStatus(202);
        Queue::assertPushed(GenerateFilerPreview::class, 1);
        Queue::assertPushed(GenerateFilerPreview::class, fn ($job) => $job->fileId === $file->id && $job->queue === 'filer-preview');
        $this->getJson($url.'/preview/content')->assertStatus(409);
        (new GenerateFilerPreview($file->id))->failed(new RuntimeException('test'));
        $this->getJson($url.'/preview')->assertJsonPath('data.status', 'failed');
    }

    public function test_missing_converter_and_unsupported_formats_do_not_enqueue(): void
    {
        [$file, , $url] = $this->prepare();
        Queue::fake();
        config(['filer.office_binary' => '/missing/soffice']);
        $this->postJson($url.'/preview')->assertOk()->assertJsonPath('data.status', 'unavailable');
        $file->update(['name' => 'archive.zip']);
        $this->postJson($url.'/preview')->assertOk()->assertJsonPath('data.status', 'unsupported');
        Queue::assertNothingPushed();
    }

    public function test_cached_preview_download_is_protected_and_deleted_with_original(): void
    {
        [$file, $scope, $url] = $this->prepare();
        $file->forceFill(['preview_status' => 'ready'])->save();
        $path = app(FilerPreviewService::class)->path($file);
        Storage::disk('filer')->put($file->path, 'original');
        Storage::disk('filer')->put($path, '%PDF-1.4 test');
        $this->getJson($url.'/preview')->assertJsonPath('data.status', 'ready');
        $this->get($url.'/preview/content')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('preview.pdf');
        $this->deleteJson($url)->assertNoContent();
        Storage::disk('filer')->assertMissing($path);
        Storage::disk('filer')->assertMissing($file->path);
    }

    public function test_failed_conversion_cleans_temporary_files_and_preserves_original(): void
    {
        [$file] = $this->prepare();
        $this->partialMock(FilerPreviewService::class, fn ($mock) => $mock->shouldReceive('available')->andReturn(true));
        Storage::disk('filer')->put($file->path, 'broken Office file');
        Process::fake(['*' => Process::result(errorOutput: 'failure', exitCode: 1)]);
        try {
            app(FilerPreviewService::class)->convert($file);
            $this->fail('Expected conversion failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Office conversion failed or exceeded preview limit.', $error->getMessage());
        }
        Process::assertRan(fn ($process) => is_array($process->command) && in_array('--headless', $process->command, true));
        $this->assertSame([], Storage::disk('filer')->allFiles('.preview-work'));
        Storage::disk('filer')->assertExists($file->path);
    }

    public function test_local_libreoffice_converts_word_and_excel_fixtures(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('filer.office_binary'))) {
            $this->markTestSkipped('Local LibreOffice integration requires Windows LibreOffice.');
        }
        [$file] = $this->prepare('example.docx');
        $disk = Storage::disk('filer');
        $disk->put($file->path, '');
        $word = new ZipArchive;
        $this->assertTrue($word->open($disk->path($file->path), ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $word->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $word->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $word->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Zuratax Word preview</w:t></w:r></w:p></w:body></w:document>');
        $word->close();
        app(FilerPreviewService::class)->convert($file);
        $this->assertSame('ready', $file->fresh()->preview_status);
        $disk->assertExists(app(FilerPreviewService::class)->path($file));

        $file->update(['name' => 'example.xlsx']);
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($disk->path($file->path), ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $archive->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $archive->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventory" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $archive->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Zuratax Excel preview</t></is></c><c r="B1"><v>42</v></c></row></sheetData></worksheet>');
        $archive->close();
        $disk->delete(app(FilerPreviewService::class)->path($file));
        app(FilerPreviewService::class)->convert($file);
        $disk->assertExists(app(FilerPreviewService::class)->path($file));
        $this->assertSame([], $disk->allFiles('.preview-work'));
    }

    public function test_agent_read_only_token_can_preview_but_cannot_edit_description(): void
    {
        [$file, $scope] = $this->prepare();
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $agent->id, 'role' => 'admin', 'project_access_mode' => 'all']);
        $file->update(['visibility' => 'scope', 'created_by' => $agent->id]);
        $file->forceFill(['preview_status' => 'ready'])->save();
        Storage::disk('filer')->put(app(FilerPreviewService::class)->path($file), '%PDF-1.4 test');
        auth()->forgetGuards();
        $this->withToken($agent->createToken('preview', ['task.view'])->plainTextToken);
        $url = "/api/agent/scopes/{$scope->id}/files/{$file->id}";
        $this->getJson($url.'/preview')->assertOk()->assertJsonPath('data.status', 'ready');
        $this->getJson($url.'/preview/content')->assertOk();
        $this->patchJson($url, ['description' => 'forbidden'])->assertForbidden();
        $this->assertNull($file->fresh()->description);

    }
}
