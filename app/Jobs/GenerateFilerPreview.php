<?php

namespace App\Jobs;

use App\Models\FilerFile;
use App\Services\FilerPreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateFilerPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 65;

    public bool $failOnTimeout = true;

    public function __construct(public string $fileId) {}

    /**
     * Execute the job.
     */
    public function handle(FilerPreviewService $previews): void
    {
        $file = FilerFile::find($this->fileId);
        if ($file === null || $file->preview_status !== 'pending') {
            return;
        }
        $previews->convert($file);
    }

    public function failed(?Throwable $exception): void
    {
        FilerFile::query()->whereKey($this->fileId)->where('preview_status', 'pending')->update(['preview_status' => 'failed']);
    }
}
