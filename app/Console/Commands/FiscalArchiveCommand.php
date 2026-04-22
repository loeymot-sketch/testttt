<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
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
                            {--to=   : End date   (YYYY-MM-DD), inclusive}
                            {--no-verify : Skip pre-archive verifyChain (ops recovery only)}';

    protected $description = 'Produce a NF525-compliant fiscal archive (zip) for a branch over a period.';

    /** Rows yielded per cursor fetch — bounds DB memory and Eloquent hydration cost. */
    private const CURSOR_CHUNK = 500;

    /*
     * [W9-AUDIT PROD-1] TOCTOU mitigation lock parameters.
     *
     * - LOCK_TTL: max time the lock is held if the process crashes mid-run
     *   without releasing. 600s = 10min covers worst-case archive of a very
     *   large branch (~500k orders, ~1M audit rows) on slow disk.
     * - LOCK_WAIT: how long we wait to acquire the lock if another writer
     *   (open/close/another archive) holds it. 30s tolerates an in-flight
     *   Z close (typically <2s) without falsely failing the run.
     */
    private const ARCHIVE_LOCK_TTL = 600;
    private const ARCHIVE_LOCK_WAIT = 30;

    public function handle(): int
    {
        $branchId = (int) $this->argument('branch_id');
        if ($branchId <= 0) {
            $this->error('branch_id must be a positive integer.');
            return self::FAILURE;
        }

        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'))->startOfDay() : null;
        /*
         * [W9-AUDIT FIX-4] When --to is provided, normalize to end-of-day for inclusive
         * day-grain semantics. When omitted, use end-of-day of "today" (instead of the
         * current instant) so manual runs without explicit bounds capture the whole day
         * coherently with the scheduled J-1 run (which uses subDay()->startOfDay() and
         * subDay()->endOfDay()). Without this, an op running at 14:32 would silently
         * exclude any Z report closed at 14:33+ that day from the archive, producing
         * partial bundles whose `to` border is implicit and irreproducible.
         */
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        /*
         * [W9.A / G2 finding] Defense-in-depth: verify the Z-chain integrity
         * BEFORE producing the bundle. NF525 archives are evidence; shipping
         * a bundle whose chain is broken would propagate corruption into a
         * tamper-evident long-term store.
         *
         * Behavior:
         * - --no-verify CLI flag → skip (ops recovery, marked unverified)
         * - config('fiscal.verify_chain_before_archive') = false → skip
         * - verify OK → record `z_chain_verified=true` in manifest
         * - verify KO → log CRITICAL on `fiscal` channel, ABORT (FAILURE)
         */
        $verifyEnabled = ! $this->option('no-verify')
            && (bool) Config::get('fiscal.verify_chain_before_archive', true);

        /*
         * [W9-AUDIT PROD-1] Eliminate TOCTOU between verifyChain (snapshot at T)
         * and the streaming export (T+k): hold the same Cache lock as
         * ZReportService::open()/close() ('z_report_b{n}') for the entire
         * verify+build window. This guarantees that no new Z report can be
         * opened or closed on this branch while we produce the bundle, so the
         * exported payload matches exactly the cryptographic snapshot we
         * verified.
         *
         * Defensive: if we cannot acquire the lock within ARCHIVE_LOCK_WAIT
         * (e.g. a long Z close in flight), we abort with a structured log so
         * ops can re-run later. This is preferable to producing a bundle whose
         * integrity guarantee is weaker than what the manifest claims.
         */
        $lockKey = sprintf('z_report_b%d', $branchId);
        $lock = Cache::lock($lockKey, self::ARCHIVE_LOCK_TTL);

        try {
            if (! $lock->block(self::ARCHIVE_LOCK_WAIT)) {
                Log::channel('fiscal')->error('NF525 fiscal:archive could not acquire branch lock', [
                    'event'      => 'fiscal.archive.lock_timeout',
                    'branch_id'  => $branchId,
                    'lock_key'   => $lockKey,
                    'wait_secs'  => self::ARCHIVE_LOCK_WAIT,
                ]);
                $this->error("FiscalArchive: branch {$branchId} is busy (Z open/close in flight). Re-run later.");
                return self::FAILURE;
            }

            $verifyResult = null;
            if ($verifyEnabled) {
                $verifyResult = $this->verifyZChainOrFail($branchId);
                if ($verifyResult === null) {
                    return self::FAILURE;
                }
            }

            $archivePath = $this->build($branchId, $from, $to, $verifyResult);
            $this->info("Fiscal archive written to: {$archivePath}");

            return self::SUCCESS;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Verify the Z-chain in non-strict mode (we want the structured result
     * regardless of strict env config — strict mode would `throw`).
     *
     * @return array<string,mixed>|null Verify result on success, null on failure (caller returns FAILURE).
     */
    private function verifyZChainOrFail(int $branchId): ?array
    {
        try {
            /** @var ZReportService $service */
            $service = app(ZReportService::class);
            $result = $service->verifyChain($branchId, false);
        } catch (\Throwable $e) {
            // verifyChain in non-strict mode should not throw on chain
            // anomalies (it returns valid=false). A throw here means an
            // unexpected runtime error (DB down, classloader issue, etc.).
            Log::channel('fiscal')->critical('NF525 fiscal:archive verifyChain crashed', [
                'event'     => 'fiscal.archive.verify_chain.crash',
                'branch_id' => $branchId,
                'message'   => $e->getMessage(),
            ]);
            $this->error("FiscalArchive: verifyChain crashed ({$e->getMessage()}). Use --no-verify only for ops recovery.");
            return null;
        }

        if (! ($result['valid'] ?? false)) {
            Log::channel('fiscal')->critical('NF525 fiscal:archive ABORTED — Z-chain integrity violated', [
                'event'     => 'fiscal.archive.verify_chain.failed',
                'branch_id' => $branchId,
                'errors'    => $result['errors'] ?? [],
                'first_z_id'=> $result['first_z_id'] ?? null,
                'last_z_id' => $result['last_z_id'] ?? null,
                'count'     => $result['count'] ?? 0,
            ]);
            $this->error("FiscalArchive: Z-chain INVALID for branch {$branchId} — bundle NOT produced. See log channel `fiscal`.");
            return null;
        }

        return $result;
    }

    /**
     * Core logic, exposed for tests. Returns the absolute filesystem
     * path of the produced bundle.
     *
     * @param  array<string,mixed>|null  $verifyResult  optional Z-chain
     *         verification result (W9.A) recorded in the manifest; null
     *         means verification was skipped or unavailable (legacy callers).
     */
    public function build(int $branchId, ?Carbon $from, Carbon $to, ?array $verifyResult = null): string
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
                'schema_version'   => 3, // [W9.A] +z_chain_verified block
                'layout'           => [
                    'z_reports.json'   => 'ZReport rows (closed, signed), sorted by sequence_no',
                    'orders.json'      => 'Order rows, sorted by id',
                    'audit_logs.json'  => 'AuditLog rows (INSERT-only, hash-chained), sorted by id',
                ],
                // [W9.A / G2] Defense-in-depth marker. null = verify skipped
                // (--no-verify CLI flag or config disabled). Object = result
                // of ZReportService::verifyChain at archive time.
                'z_chain_verified'    => $verifyResult !== null,
                'z_chain_verify_meta' => $verifyResult === null ? null : [
                    'count'      => (int) ($verifyResult['count'] ?? 0),
                    'first_z_id' => $verifyResult['first_z_id'] ?? null,
                    'last_z_id'  => $verifyResult['last_z_id'] ?? null,
                    'verified_at'=> Carbon::now()->toIso8601String(),
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
