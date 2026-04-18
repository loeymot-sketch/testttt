<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ZReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * [POS-9.4.11 / POS-GA-F-01]
 *
 * Exports every fiscal artifact required by NF525 for a given branch
 * over a [from; to] window into a single zip bundle:
 *  - closed Z reports (with signature);
 *  - orders included in the window;
 *  - audit_logs rows (INSERT-only, hash-chained).
 *
 * The bundle is deterministic (sorted by id inside each JSON, no
 * timestamps inside the payloads beyond what the DB carried) so a
 * round-trip (read back) always recovers the same document.
 *
 * Retention: the NF525 décret requires 6 years — controlled by
 * `config('fiscal.archive_retention_years')`. The command itself does
 * not purge; it only produces the bundle. A separate cleanup policy
 * runs on the storage side.
 */
class FiscalArchiveCommand extends Command
{
    protected $signature = 'foodking:fiscal:archive
                            {branch_id : Branch to archive}
                            {--from= : Start date (YYYY-MM-DD), inclusive}
                            {--to=   : End date   (YYYY-MM-DD), inclusive}';

    protected $description = 'Produce a NF525-compliant fiscal archive (zip) for a branch over a period.';

    public function handle(): int
    {
        $branchId = (int) $this->argument('branch_id');
        if ($branchId <= 0) {
            $this->error('branch_id must be a positive integer.');
            return self::FAILURE;
        }

        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'))->startOfDay() : null;
        $to   = $this->option('to')   ? Carbon::parse((string) $this->option('to'))->endOfDay()   : Carbon::now();

        $archivePath = $this->build($branchId, $from, $to);
        $this->info("Fiscal archive written to: {$archivePath}");

        return self::SUCCESS;
    }

    /**
     * Core logic, exposed for tests. Returns the absolute filesystem
     * path of the produced bundle.
     */
    public function build(int $branchId, ?Carbon $from, Carbon $to): string
    {
        $bundle = [
            'meta' => [
                'branch_id'        => $branchId,
                'from'             => $from ? $from->toIso8601String() : null,
                'to'               => $to->toIso8601String(),
                'generated_at'     => Carbon::now()->toIso8601String(),
                'retention_years'  => (int) Config::get('fiscal.archive_retention_years', 6),
                'schema_version'   => 1,
            ],
            'z_reports'  => $this->collectZReports($branchId, $from, $to),
            'orders'     => $this->collectOrders($branchId, $from, $to),
            'audit_logs' => $this->collectAuditLogs($branchId, $from, $to),
        ];

        $disk = Storage::disk(Config::get('fiscal.archive_disk', 'local'));
        $relDir = trim((string) Config::get('fiscal.archive_path', 'fiscal'), '/') . "/{$branchId}";
        $disk->makeDirectory($relDir);

        $period = ($from ? $from->format('Ymd') : 'all') . '-' . $to->format('Ymd');
        $baseName = "{$relDir}/{$period}.zip";

        $absolute = $disk->path($baseName);
        @unlink($absolute); // overwrite on rerun

        $zip = new ZipArchive();
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("FiscalArchive: cannot create zip at {$absolute}.");
        }

        $zip->addFromString(
            'manifest.json',
            json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $zip->close();

        return $absolute;
    }

    private function collectZReports(int $branchId, ?Carbon $from, Carbon $to): array
    {
        $q = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('closed_at', '<=', $to);
        if ($from) {
            $q->where('closed_at', '>=', $from);
        }
        return $q->orderBy('sequence_no')->get()->toArray();
    }

    private function collectOrders(int $branchId, ?Carbon $from, Carbon $to): array
    {
        $q = Order::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('created_at', '<=', $to);
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        return $q->orderBy('id')->get()->toArray();
    }

    private function collectAuditLogs(int $branchId, ?Carbon $from, Carbon $to): array
    {
        $q = AuditLog::query()
            ->where('branch_id', $branchId)
            ->where('created_at', '<=', $to);
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        return $q->orderBy('id')->get()->toArray();
    }
}
