<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ZReport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * [POS-9.4.11 / POS-GA-F-01] [POS-9-H.3.3 / F-C8]
 *
 * Exports every fiscal artifact required by NF525 for a given branch
 * over a [from; to] window into a single zip bundle:
 *  - closed Z reports (with signature);
 *  - orders included in the window;
 *  - audit_logs rows (INSERT-only, hash-chained).
 *
 * Memory model (H.3.3 hardening):
 *   The previous implementation called `->get()->toArray()` on each
 *   Eloquent relation, assembling a single in-memory `$bundle` array
 *   and then `json_encode`-ing it whole to pass to
 *   `ZipArchive::addFromString()`. On a 6-year archive that can be
 *   100k+ orders + 500k+ audit_log rows, this was blowing past the
 *   PHP memory_limit (128M default on production).
 *
 *   This version streams each dataset with `->lazy()` (yields one
 *   row at a time from a cursor, bounded by `cursor_chunk_size`) to
 *   a temp file on disk, one JSON row per line (JSONL). Each file is
 *   then added to the zip via `ZipArchive::addFile()` (no in-memory
 *   copy). Peak RSS stays O(single-row) regardless of the window.
 *
 * The bundle is deterministic (sorted by id inside each JSON file, no
 * timestamps inside payloads beyond DB values) so a round-trip always
 * recovers the same document.
 *
 * Retention: 6 years per `config('fiscal.archive_retention_years')`.
 */
class FiscalArchiveCommand extends Command
{
    protected $signature = 'foodking:fiscal:archive
                            {branch_id : Branch to archive}
                            {--from= : Start date (YYYY-MM-DD), inclusive}
                            {--to=   : End date   (YYYY-MM-DD), inclusive}';

    protected $description = 'Produce a NF525-compliant fiscal archive (zip) for a branch over a period.';

    /** Rows yielded per cursor fetch — bounds DB memory and Eloquent hydration cost. */
    private const CURSOR_CHUNK = 500;

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
        $disk   = Storage::disk(Config::get('fiscal.archive_disk', 'local'));
        $relDir = trim((string) Config::get('fiscal.archive_path', 'fiscal'), '/') . "/{$branchId}";
        $disk->makeDirectory($relDir);

        $period   = ($from ? $from->format('Ymd') : 'all') . '-' . $to->format('Ymd');
        $baseName = "{$relDir}/{$period}.zip";
        $absolute = $disk->path($baseName);
        @unlink($absolute);

        // We stream each dataset to a temp JSON file, then addFile()
        // into the zip. The temp files live alongside the zip so that
        // the same (potentially network-mounted) storage driver is
        // exercised for both reads and writes.
        $tmpDir = $disk->path($relDir);
        $tmpZReports = $tmpDir . '/.tmp.zreports.json';
        $tmpOrders   = $tmpDir . '/.tmp.orders.json';
        $tmpAudit    = $tmpDir . '/.tmp.auditlogs.json';
        $tmpManifest = $tmpDir . '/.tmp.manifest.json';

        try {
            $this->streamToJson($tmpZReports, $this->zReportQuery($branchId, $from, $to));
            $this->streamToJson($tmpOrders,   $this->orderQuery($branchId, $from, $to));
            $this->streamToJson($tmpAudit,    $this->auditLogQuery($branchId, $from, $to));

            $manifest = [
                'branch_id'        => $branchId,
                'from'             => $from ? $from->toIso8601String() : null,
                'to'               => $to->toIso8601String(),
                'generated_at'     => Carbon::now()->toIso8601String(),
                'retention_years'  => (int) Config::get('fiscal.archive_retention_years', 6),
                'schema_version'   => 2, // bumped — layout is now one file per collection
                'layout'           => [
                    'z_reports.json'   => 'ZReport rows (closed, signed), sorted by sequence_no',
                    'orders.json'      => 'Order rows, sorted by id',
                    'audit_logs.json'  => 'AuditLog rows (INSERT-only, hash-chained), sorted by id',
                ],
            ];
            file_put_contents(
                $tmpManifest,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );

            $zip = new ZipArchive();
            if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("FiscalArchive: cannot create zip at {$absolute}.");
            }

            // addFile() does NOT load the file in memory at this step;
            // ZipArchive::close() is the one that streams it through
            // zlib — which itself uses a bounded buffer, so the peak
            // footprint stays around a few MB even on very large
            // datasets.
            $zip->addFile($tmpManifest, 'manifest.json');
            $zip->addFile($tmpZReports, 'z_reports.json');
            $zip->addFile($tmpOrders,   'orders.json');
            $zip->addFile($tmpAudit,    'audit_logs.json');

            if (!$zip->close()) {
                throw new RuntimeException("FiscalArchive: zip close failed at {$absolute}.");
            }
        } finally {
            @unlink($tmpManifest);
            @unlink($tmpZReports);
            @unlink($tmpOrders);
            @unlink($tmpAudit);
        }

        return $absolute;
    }

    /**
     * Pull rows from an Eloquent builder lazily and write them as a
     * compact JSON ARRAY to $path. Memory footprint is O(single row).
     *
     * Uses a cursor (`->lazy()`) so the DB driver streams rows instead
     * of buffering the full resultset; only `CURSOR_CHUNK` rows are
     * hydrated at once.
     */
    private function streamToJson(string $path, Builder $query): void
    {
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new RuntimeException("FiscalArchive: cannot open temp file {$path}.");
        }

        try {
            fwrite($fh, '[');
            $first = true;
            foreach ($query->lazy(self::CURSOR_CHUNK) as $row) {
                $json = json_encode(
                    $row->toArray(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                if ($json === false) {
                    throw new RuntimeException('FiscalArchive: json_encode failed on row '.$row->getKey());
                }
                fwrite($fh, ($first ? '' : ',') . "\n  " . $json);
                $first = false;
            }
            fwrite($fh, ($first ? '' : "\n") . ']');
        } finally {
            fclose($fh);
        }
    }

    private function zReportQuery(int $branchId, ?Carbon $from, Carbon $to): Builder
    {
        $q = ZReport::query()
            ->where('branch_id', $branchId)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('closed_at', '<=', $to);
        if ($from) {
            $q->where('closed_at', '>=', $from);
        }
        return $q->orderBy('sequence_no');
    }

    private function orderQuery(int $branchId, ?Carbon $from, Carbon $to): Builder
    {
        $q = Order::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('created_at', '<=', $to);
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        return $q->orderBy('id');
    }

    private function auditLogQuery(int $branchId, ?Carbon $from, Carbon $to): Builder
    {
        $q = AuditLog::query()
            ->where('branch_id', $branchId)
            ->where('created_at', '<=', $to);
        if ($from) {
            $q->where('created_at', '>=', $from);
        }
        return $q->orderBy('id');
    }
}
