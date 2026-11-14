<?php

namespace App\Services\ProInvoice;

use App\Models\Branch;
use App\Models\ProInvoice;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * [FACTURE PRO 2026-11-14 · propriétaire] Émission et annulation des factures pro.
 *
 * HORS CHAÎNE NF525 (décision du propriétaire, « Montant libre ») : ce service n'appelle ni
 * PricingService, ni la séquence fiscale, ni le Z, ni le journal d'audit, ni le tiroir-caisse.
 * Il ne lit et n'écrit QUE `pro_invoices` (et lit l'identité du vendeur dans les réglages).
 *
 * Numérotation `FP-AAAA-NNNN` : continue par restaurant et par année, sans trou. Le numéro est
 * calculé DANS la transaction, sous verrou de la dernière ligne ; l'index unique
 * (branch_id, number) est la dernière barrière, et une collision est rejouée.
 */
class ProInvoiceService
{
    private const MAX_ATTEMPTS = 3;

    /**
     * TTC → HT + TVA, au centime, de sorte que HT + TVA = TTC EXACTEMENT.
     * Calcul en centimes entiers (pas de flottant) : HT arrondi au plus proche, TVA = TTC − HT.
     * 18,00 € à 10 % → HT 16,36 + TVA 1,64.
     *
     * @return array{amount_ttc: string, amount_ht: string, vat_amount: string}
     */
    public static function computeAmounts(string|float|int $amountTtc, string|float|int $vatRate): array
    {
        $ttcCents = (int) round(((float) str_replace(',', '.', (string) $amountTtc)) * 100);
        $rateBp = (int) round(((float) str_replace(',', '.', (string) $vatRate)) * 100); // 10 % → 1000

        $num = $ttcCents * 10000;
        $den = 10000 + $rateBp;
        $htCents = intdiv(2 * $num + $den, 2 * $den); // arrondi au demi supérieur
        $vatCents = $ttcCents - $htCents;

        return [
            'amount_ttc' => self::cents($ttcCents),
            'amount_ht'  => self::cents($htCents),
            'vat_amount' => self::cents($vatCents),
        ];
    }

    /** Branche d'émission : l'admin (branche 0) opère sur la branche 1 en V1 mono-restaurant. */
    public static function branchFor(?User $user): int
    {
        $branchId = (int) ($user?->branch_id ?? 0);

        return $branchId > 0 ? $branchId : 1;
    }

    public function create(array $data, User $user): ProInvoice
    {
        $branchId = self::branchFor($user);
        $amounts = self::computeAmounts($data['amount_ttc'], $data['vat_rate']);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return DB::transaction(function () use ($data, $user, $branchId, $amounts) {
                    $number = $this->nextNumber($branchId);

                    $invoice = new ProInvoice();
                    $invoice->fill([
                        'branch_id'         => $branchId,
                        'number'            => $number,
                        'client_name'       => $data['client_name'],
                        'client_address'    => $data['client_address'] ?? null,
                        'client_siret'      => $data['client_siret'] ?? null,
                        'client_vat_number' => $data['client_vat_number'] ?? null,
                        'service_date'      => $data['service_date'],
                        'label'             => $data['label'],
                        'quantity'          => (int) $data['quantity'],
                        'amount_ttc'        => $amounts['amount_ttc'],
                        'vat_rate'          => number_format((float) $data['vat_rate'], 2, '.', ''),
                        'amount_ht'         => $amounts['amount_ht'],
                        'vat_amount'        => $amounts['vat_amount'],
                        'payment_method'    => $data['payment_method'],
                        'notes'             => $data['notes'] ?? null,
                        'created_by'        => $user->id,
                    ]);
                    $invoice->save();

                    return $invoice;
                });
            } catch (QueryException $e) {
                // Collision sur l'index unique (deux caisses au même instant) : on recalcule.
                $duplicate = in_array((string) ($e->errorInfo[0] ?? ''), ['23000', '23505'], true);
                if (! $duplicate || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    public function cancel(ProInvoice $invoice, User $user, string $reason): ProInvoice
    {
        return DB::transaction(function () use ($invoice, $user, $reason) {
            /** @var ProInvoice $locked */
            $locked = ProInvoice::withoutGlobalScope(BranchScope::class)
                ->whereKey($invoice->getKey())
                ->where('branch_id', $invoice->branch_id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($locked->isCancelled(), 422, 'Cette facture est déjà annulée.');

            $locked->cancelled_at = now();
            $locked->cancelled_by = $user->id;
            $locked->cancel_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Identité du vendeur, lue dans les réglages — jamais inventée. Un champ absent reste `null` :
     * le document affiche alors « à compléter dans les réglages ».
     *
     * Nom : Réglages > Entreprise (`company_name`), à défaut le nom de la branche.
     * Adresse, SIRET, TVA : la fiche de la branche (mêmes champs que le ticket de caisse,
     * cf. ReceiptDataService), l'adresse retombant sur Réglages > Entreprise.
     *
     * @return array{name: ?string, address: ?string, siret: ?string, vat_number: ?string, phone: ?string, email: ?string}
     */
    public function sellerIdentity(int $branchId): array
    {
        $company = rescue(
            static fn (): array => (array) \Smartisan\Settings\Facades\Settings::group('company')->all(),
            [],
            false
        );
        $branch = Branch::query()->find($branchId);

        $companyAddress = trim(implode(' ', array_filter([
            trim((string) ($company['company_address'] ?? '')),
            trim(trim((string) ($company['company_zip_code'] ?? '')).' '.trim((string) ($company['company_city'] ?? ''))),
        ])));

        return [
            'name'       => self::filled($company['company_name'] ?? null) ?? self::filled($branch?->name),
            'address'    => self::filled($branch?->address) ?? self::filled($companyAddress),
            'siret'      => self::filled($branch?->siret),
            'vat_number' => self::filled($branch?->vat_intra),
            'phone'      => self::filled($branch?->phone) ?? self::filled($company['company_phone'] ?? null),
            'email'      => self::filled($branch?->email) ?? self::filled($company['company_email'] ?? null),
        ];
    }

    private function nextNumber(int $branchId): string
    {
        $year = now()->format('Y');
        $prefix = "FP-{$year}-";

        $last = ProInvoice::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('number');

        $next = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private static function cents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function filled(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
