<?php

namespace App\Console\Commands;

use App\Models\Scope;
use App\Services\LegacyLedgerImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use LogicException;
use Throwable;

#[Signature('legacy:import-ledger {snapshot} {--scope=} {--commit} {--expect-sha256=}')]
#[Description('Import private source Ledger operations, never legacy monthly totals; dry-run by default')]
class ImportLegacyLedger extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LegacyLedgerImportService $importer): int
    {
        try {
            $file = (string) $this->argument('snapshot');
            if (! is_file($file) || is_link($file) || filesize($file) > 16 * 1024 * 1024
                || (PHP_OS_FAMILY !== 'Windows' && (fileperms($file) & 0077) !== 0)) {
                throw new LogicException('Snapshot must be a private regular file, at most 16 MiB.');
            }
            $bytes = file_get_contents($file);
            $hash = hash('sha256', $bytes);
            $expected = (string) $this->option('expect-sha256');
            if (($this->option('commit') && $expected === '') || ($expected !== '' && ! hash_equals($expected, $hash))) {
                throw new LogicException('Commit requires the exact SHA-256 confirmed by the dry run.');
            }
            $scope = (string) $this->option('scope');
            if ($scope === '') {
                throw new LogicException('An explicit private target scope is required.');
            }
            $target = Scope::where(fn ($query) => $query->where('id', $scope)->orWhere('slug', $scope))->sole();
            $result = $importer->import($target, json_decode($bytes, true, 512, JSON_THROW_ON_ERROR), (bool) $this->option('commit'));
            unset($result['maps']);
            $this->line(json_encode([...$result, 'sha256' => $hash], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (QueryException $exception) {
            $this->error('Ledger import rolled back: SQLSTATE '.($exception->errorInfo[0] ?? 'unknown').', code '.($exception->errorInfo[1] ?? 'unknown').'.');
        } catch (Throwable $exception) {
            $this->error($exception instanceof LogicException ? $exception->getMessage() : 'Ledger import rolled back: '.class_basename($exception).'.');
        }

        return self::FAILURE;
    }
}
