<?php

namespace App\Services;

use App\Jobs\GenerateFilerPreview;
use App\Models\FilerFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FilerPreviewService
{
    public function supported(FilerFile $file): bool
    {
        return in_array(strtolower(pathinfo($file->name, PATHINFO_EXTENSION)), ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'rtf'], true);
    }

    public function available(): bool
    {
        return is_file(config('filer.office_binary'))
            && (! app()->isProduction() || PHP_OS_FAMILY === 'Linux')
            && (PHP_OS_FAMILY !== 'Linux' || (is_file(config('filer.office_sandbox')) && is_file('/usr/bin/prlimit')));
    }

    public function path(FilerFile $file): string
    {
        return $file->scope_id.'/'.$file->id.'/preview.pdf';
    }

    public function status(FilerFile $file): string
    {
        if (! $this->supported($file)) {
            return 'unsupported';
        }
        if ($file->preview_status === 'ready' && Storage::disk($file->disk)->exists($this->path($file))) {
            return 'ready';
        }
        if (! $this->available()) {
            return 'unavailable';
        }
        if ($file->preview_status === 'pending' && $file->preview_requested_at?->lt(now()->subMinutes(5))) {
            return 'failed';
        }

        return $file->preview_status === 'ready' ? 'idle' : ($file->preview_status ?? 'idle');
    }

    public function request(FilerFile $file): string
    {
        return DB::transaction(function () use ($file): string {
            $locked = FilerFile::query()->lockForUpdate()->findOrFail($file->id);
            $status = $this->status($locked);
            if (! in_array($status, ['idle', 'failed'], true)) {
                return $status;
            }
            $locked->forceFill(['preview_status' => 'pending', 'preview_requested_at' => now()])->save();
            GenerateFilerPreview::dispatch($file->id)
                ->onConnection(config('filer.preview_connection'))->onQueue('filer-preview')->afterCommit();

            return 'pending';
        });
    }

    public function convert(FilerFile $file): void
    {
        if (! $this->available() || ! $this->supported($file)) {
            throw new RuntimeException('Office preview converter unavailable.');
        }
        $disk = Storage::disk($file->disk);
        $work = '.preview-work/'.Str::ulid();
        $disk->makeDirectory($work.'/profile/user');
        try {
            $space = disk_free_space($disk->path($work));
            if ($space === false || $space < config('filer.reserve_bytes') + config('filer.preview_max_bytes') + $file->size) {
                throw new RuntimeException('Insufficient preview storage.');
            }
            $extension = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
            if (! $disk->copy($file->path, $work.'/source.'.$extension)) {
                throw new RuntimeException('Preview source unavailable.');
            }
            $disk->put($work.'/profile/user/registrymodifications.xcu', '<?xml version="1.0" encoding="UTF-8"?><oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item><item oor:path="/org.openoffice.Office.Calc/Content/Update"><prop oor:name="Link" oor:op="fuse"><value>0</value></prop></item></oor:items>');
            $directory = str_replace('\\', '/', $disk->path($work));
            $prefix = [];
            if (PHP_OS_FAMILY === 'Linux') {
                $prefix = ['/usr/bin/prlimit', '--as=2147483648', '--fsize=67108864', '--cpu=50', '--',
                    config('filer.office_sandbox'), '--unshare-all', '--die-with-parent', '--new-session',
                    '--clearenv', '--setenv', 'HOME', '/work', '--setenv', 'PATH', '/usr/bin:/bin',
                    '--setenv', 'LANG', 'C.UTF-8', '--ro-bind', '/usr', '/usr'];
                foreach (['/lib', '/lib64', '/bin', '/etc/fonts', '/etc/ld.so.cache', '/etc/libreoffice'] as $path) {
                    if (file_exists($path)) {
                        array_push($prefix, '--ro-bind', $path, $path);
                    }
                }
                array_push($prefix, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--bind', $directory, '/work', '--chdir', '/work', '--');
                $directory = '/work';
            }
            $profileUri = 'file://'.(str_starts_with($directory, '/') ? '' : '/').str_replace(' ', '%20', $directory).'/profile';
            $filter = in_array($extension, ['xls', 'xlsx', 'ods'], true)
                ? 'pdf:calc_pdf_Export:{"SinglePageSheets":{"type":"boolean","value":"true"}}'
                : 'pdf:writer_pdf_Export';
            $command = [...$prefix, config('filer.office_binary'), '-env:UserInstallation='.$profileUri,
                '--headless', '--nologo', '--nodefault', '--norestore', '--convert-to', $filter,
                '--outdir', $directory, $directory.'/source.'.$extension];
            $result = Process::path($disk->path($work))->timeout(50)->run($command);
            $pdf = $work.'/source.pdf';
            if (! $result->successful() || ! $disk->exists($pdf) || $disk->size($pdf) > config('filer.preview_max_bytes')) {
                throw new RuntimeException('Office conversion failed or exceeded preview limit.');
            }
            $stream = $disk->readStream($pdf);
            $signature = is_resource($stream) ? fread($stream, 5) : '';
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($signature !== '%PDF-') {
                throw new RuntimeException('Invalid preview output.');
            }
            DB::transaction(function () use ($file, $disk, $pdf): void {
                $current = FilerFile::query()->lockForUpdate()->find($file->id);
                if ($current === null) {
                    return;
                }
                if (! $disk->move($pdf, $this->path($current))) {
                    throw new RuntimeException('Cannot save preview.');
                }
                $current->forceFill(['preview_status' => 'ready'])->save();
            });
        } finally {
            $disk->deleteDirectory($work);
        }
    }
}
