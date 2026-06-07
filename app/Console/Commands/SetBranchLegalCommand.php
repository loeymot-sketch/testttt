<?php

namespace App\Console\Commands;

use App\Models\Branch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [HEAL-H5 2026-06-07] Set a branch's NF525 legal identity (SIRET, TVA intra,
 * register/caisse id, legal footer) idempotently.
 *
 * WHY: the operating DB had branches.siret / vat_intra / legal_footer /
 * register_id NULL → production fiscal tickets render NO fiscal header. No
 * mechanism existed to set them per device. This command is that mechanism.
 *
 * These fields feed the printed receipt via ReceiptDataService (the SSOT that
 * both the Vue ReceiptComponent and the ESC/POS renderer read). This command
 * touches the `branches` table ONLY — it never writes to the fiscal chain
 * (audit_logs / z_reports), never to orders, never to a frozen service.
 *
 * IDEMPOTENT: re-running with the same args yields a byte-identical branch row.
 * The default legal_footer is applied only when --legal-footer is omitted AND
 * the current footer is null/empty OR self-contradictory ("non applicable" /
 * "293B" — wrong for a VAT-registered business that bills VAT-10). A deliberate
 * good footer already in place is preserved.
 *
 * OWNER GATES:
 *   - G3: the FINAL official legal_footer wording is owner-validated. The
 *         default here is a SAFE placeholder, not the legally-signed text.
 *   - G4: applying the REAL legal values (SIRET / TVA / register) per physical
 *         device is an owner-driven operation (run this command per machine
 *         with the real, owner-supplied values).
 */
class SetBranchLegalCommand extends Command
{
    /**
     * Note: --no-interaction (-n) is a Symfony global option inherited for free.
     * Declaring it here would throw "option already exists" and break artisan list.
     */
    protected $signature = 'foodking:set-branch-legal
        {--branch=1 : Target branch id (default 1 = Le Cayenne single box)}
        {--siret= : SIRET — exactly 14 digits}
        {--vat-intra= : TVA intracommunautaire — "FR" + 11 chars (e.g. FR19104170501)}
        {--register-id= : Caisse / register identifier printed on the ticket}
        {--legal-footer= : Legal mentions footer. Omit to apply a VAT-registered-safe default. FINAL wording = owner gate G3}';

    protected $description = 'Set a branch NF525 legal identity (SIRET/TVA/register/footer) idempotently. Real per-device values = owner gate G4; final footer wording = owner gate G3.';

    /**
     * VAT-registered-safe default footer. NO "non applicable art.293B CGI"
     * (that is the franchise-en-base wording, self-contradictory for a business
     * that bills VAT-10). FINAL official wording is owner gate G3.
     */
    public const DEFAULT_LEGAL_FOOTER = 'TVA intracommunautaire - Merci de votre visite';

    public function handle(): int
    {
        $branchId = (int) $this->option('branch');

        // Branch is BranchScope-exempt (self-reference). withTrashed keeps the
        // lookup robust if a branch were soft-deleted; we never want a silent miss.
        $branch = Branch::withTrashed()->find($branchId);
        if (! $branch) {
            $this->error("Branch id={$branchId} not found.");
            return self::FAILURE;
        }

        // --- capture BEFORE state ---
        $before = $this->snapshot($branch);

        // --- resolve target values (only override what was provided) ---
        $siret      = $this->option('siret');
        $vatIntra   = $this->option('vat-intra');
        $registerId = $this->option('register-id');
        $footerOpt  = $this->option('legal-footer');

        // --- validate provided values; reject (no write) on bad format ---
        if ($siret !== null && $siret !== '' && ! preg_match('/^\d{14}$/', $siret)) {
            $this->error("Invalid SIRET '{$siret}': must be exactly 14 digits.");
            return self::FAILURE;
        }
        if ($vatIntra !== null && $vatIntra !== '' && ! preg_match('/^FR.{11}$/', $vatIntra)) {
            $this->error("Invalid TVA intracommunautaire '{$vatIntra}': must be 'FR' followed by 11 characters (e.g. FR19104170501).");
            return self::FAILURE;
        }

        // --- apply ---
        if ($siret !== null && $siret !== '') {
            $branch->siret = $siret;
        }
        if ($vatIntra !== null && $vatIntra !== '') {
            $branch->vat_intra = $vatIntra;
        }
        if ($registerId !== null && $registerId !== '') {
            $branch->register_id = $registerId;
        }

        // Footer rule (idempotent + fixes the wrong default):
        //  - if --legal-footer provided → use it verbatim
        //  - else apply DEFAULT only when current is null/empty OR self-contradictory
        //    (matches /non applicable|293B/i) — a deliberate good footer is kept.
        if ($footerOpt !== null && $footerOpt !== '') {
            $branch->legal_footer = $footerOpt;
        } else {
            $current = (string) ($branch->legal_footer ?? '');
            if ($current === '' || preg_match('/non applicable|293B/i', $current)) {
                $branch->legal_footer = self::DEFAULT_LEGAL_FOOTER;
                if ($current !== '') {
                    $this->warn("Replaced self-contradictory footer (was: \"{$current}\") with VAT-registered-safe default. FINAL wording = owner gate G3.");
                }
            }
        }

        $branch->save();
        $branch->refresh();

        // --- capture AFTER state + print before/after table ---
        $after = $this->snapshot($branch);

        $this->info("Branch #{$branchId} legal identity (DB: " . DB::connection()->getDatabaseName() . ")");
        $this->table(
            ['Field', 'Before', 'After'],
            collect($before)->map(fn ($v, $k) => [$k, $this->fmt($v), $this->fmt($after[$k])])->values()->all()
        );

        if ($before === $after) {
            $this->line('<comment>No change (idempotent re-run — state already at target).</comment>');
        }

        $this->line('<comment>NOTE: real per-device values = owner gate G4; final official footer wording = owner gate G3.</comment>');

        return self::SUCCESS;
    }

    /** @return array<string,?string> */
    private function snapshot(Branch $branch): array
    {
        return [
            'siret'        => $branch->siret,
            'vat_intra'    => $branch->vat_intra,
            'register_id'  => $branch->register_id,
            'legal_footer' => $branch->legal_footer,
        ];
    }

    private function fmt(?string $v): string
    {
        return ($v === null || $v === '') ? '(null)' : $v;
    }
}
