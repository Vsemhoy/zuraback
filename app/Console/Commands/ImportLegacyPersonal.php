<?php

namespace App\Console\Commands;

use App\Models\Scope;
use App\Services\LegacyPersonalImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

#[Signature('legacy:import-personal {snapshot} {--scope=} {--commit} {--expect-sha256=}')]
#[Description('Dry-run or atomically import a private home.zuratax.com personal snapshot')]
class ImportLegacyPersonal extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LegacyPersonalImportService $importer): int
    {
        try {
            $file = (string) $this->argument('snapshot');
            if (! is_file($file) || is_link($file) || filesize($file) > 10 * 1024 * 1024
                || (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0077) !== 0)) {
                throw new LogicException('Snapshot must be a private regular file, at most 10 MiB.');
            }
            $bytes = file_get_contents($file);
            $hash = hash('sha256', $bytes);
            $expected = (string) $this->option('expect-sha256');
            if (($this->option('commit') && $expected === '') || ($expected !== '' && ! hash_equals($expected, $hash))) {
                throw new LogicException('Commit requires the SHA-256 confirmed by the dry run.');
            }
            $scopeName = (string) $this->option('scope');
            if ($scopeName === '') {
                throw new LogicException('An explicit target scope is required.');
            }
            $scope = Scope::query()->where(fn ($query) => $query->where('id', $scopeName)->orWhere('slug', $scopeName)->orWhere('name', $scopeName))->sole();
            $snapshot = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            $result = $importer->import($scope, $snapshot, (bool) $this->option('commit'));
            $this->line(json_encode([...$result, 'sha256' => $hash], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // SQL exceptions may include personal values; never print their messages.
            $this->error($exception instanceof LogicException ? $exception->getMessage() : 'Import stopped: '.class_basename($exception).'. No partial batch was committed.');

            return self::FAILURE;
        }
    }
}
