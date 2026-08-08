<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use Illuminate\Console\Command;

/**
 * [TERRAIN-HEAL 2026-07-16 · FISCAL-NO-GAP-DETECTOR — READ-ONLY]
 *
 * L'audit terrain multi-agents a relevé un ANGLE MORT d'observabilité NF525 :
 *   - `fiscal:verify-chain`        re-marche la chaîne HMAC (audit_logs + z_reports) — prouve
 *                                  l'INTÉGRITÉ cryptographique, PAS la continuité des numéros.
 *   - `fiscal:verify-z-membership` prouve que chaque reçu numéroté est scellé dans un Z (ou en
 *                                  fenêtre ouverte) — couvre l'APPARTENANCE, PAS la continuité.
 *   AUCUNE des deux ne fait le scan de continuité 1..MAX sur `orders.fiscal_sequence_no`.
 *
 * Or l'invariant NF525 fondamental est « séquence fiscale monotone, SANS TROU, par branche ».
 * Un trou (ex. un hard-delete post-allocation) est invisible aux deux commandes ci-dessus.
 * Cette commande comble le trou d'observabilité : elle scanne, par branche, la continuité
 * des numéros fiscaux alloués et signale tout numéro manquant.
 *
 * READ-ONLY : aucune écriture, aucune signature, aucune frozen-zone touchée. Sûre en prod.
 * Population identique aux agrégateurs : withTrashed() + withoutGlobalScope(BranchScope) pour
 * voir exactement les numéros alloués (y compris orders soft-deleted post-allocation).
 *
 * Exit code : 0 si aucun trou sur toutes les branches scannées ; 1 si au moins un trou (utile
 * pour brancher un monitoring/CI). Un trou n'est PAS forcément une fraude (un test hard-deleté
 * en dev en crée) — la commande le SIGNALE honnêtement pour investigation, sans juger.
 */
class VerifyFiscalSequenceContinuityCommand extends Command
{
    protected $signature = 'fiscal:verify-sequence-continuity {--branch= : limiter à un seul branch_id}';

    protected $description = 'NF525 read-only : scanne la CONTINUITÉ de orders.fiscal_sequence_no par branche (min..max sans trou) — comble l\'angle mort que verify-chain (HMAC) et verify-z-membership (appartenance) ne couvrent pas.';

    public function handle(): int
    {
        $branchFilter = $this->option('branch');

        $branchIds = Branch::query()
            ->withoutGlobalScope(BranchScope::class)
            ->when($branchFilter !== null, fn ($q) => $q->where('id', (int) $branchFilter))
            ->orderBy('id')
            ->pluck('id');

        if ($branchIds->isEmpty()) {
            $this->warn('Aucune branche à scanner.');

            return self::SUCCESS;
        }

        $anyGap = false;

        foreach ($branchIds as $branchId) {
            $seqs = Order::query()
                ->withTrashed()
                ->withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->whereNotNull('fiscal_sequence_no')
                ->orderBy('fiscal_sequence_no')
                ->pluck('fiscal_sequence_no')
                ->map(fn ($v) => (int) $v);

            if ($seqs->isEmpty()) {
                $this->line("Branche {$branchId} : aucun numéro fiscal alloué — RAS.");

                continue;
            }

            $min = $seqs->first();
            $max = $seqs->last();
            $present = $seqs->flip(); // valeur => index, pour un lookup O(1)
            $expectedCount = $max - $min + 1;
            $distinctCount = $seqs->unique()->count();

            // Doublons (jamais permis — Cache::lock + FOR UPDATE le garantissent, mais on vérifie).
            $duplicates = $seqs->duplicates()->unique()->values();

            $missing = [];
            for ($n = $min; $n <= $max; $n++) {
                if (! $present->has($n)) {
                    $missing[] = $n;
                }
            }

            if (empty($missing) && $duplicates->isEmpty()) {
                $this->info("Branche {$branchId} : OK — {$distinctCount} numéros, {$min}..{$max}, GAP-FREE, 0 doublon.");

                continue;
            }

            $anyGap = true;
            $this->error("Branche {$branchId} : TROU(S) DÉTECTÉ(S) — attendu {$expectedCount} numéros sur {$min}..{$max}, présents {$distinctCount}.");

            if (! empty($missing)) {
                // Compacte en plages pour la lisibilité (ex. 2506-2508).
                $ranges = $this->compactRanges($missing);
                $this->line('   Numéros manquants : ' . implode(', ', $ranges));

                // Bornes voisines pour l'investigation (quel order précède/suit le trou).
                foreach ($missing as $m) {
                    $before = Order::query()->withTrashed()->withoutGlobalScope(BranchScope::class)
                        ->where('branch_id', $branchId)->where('fiscal_sequence_no', $m - 1)->first(['id', 'created_at']);
                    $after = Order::query()->withTrashed()->withoutGlobalScope(BranchScope::class)
                        ->where('branch_id', $branchId)->where('fiscal_sequence_no', $m + 1)->first(['id', 'created_at']);
                    if ($before || $after) {
                        $this->line(sprintf(
                            '     seq %d manquant — bordé par order#%s (%s) et order#%s (%s)',
                            $m,
                            $before?->id ?? '?',
                            $before?->created_at ?? '?',
                            $after?->id ?? '?',
                            $after?->created_at ?? '?'
                        ));
                    }
                }
            }

            if ($duplicates->isNotEmpty()) {
                $this->error('   DOUBLONS (anomalie grave) : ' . $duplicates->implode(', '));
            }
        }

        // [ANGLE MORT 2026-08-08] Ce scan ne regardait QUE les commandes qui PORTENT un numéro
        // (`whereNotNull('fiscal_sequence_no')`, plus haut). Une vente PAYÉE dont le numéro est
        // resté NULL lui était donc structurellement invisible : la séquence est parfaitement
        // contiguë, la commande annonçait « GAP-FREE, OK », et 13 ventes payées ne portaient
        // aucun numéro — dont la commande #333 (1,90 € encaissés le 2026-08-03), restée cinq
        // jours inaperçue. Une vente sans numéro n'est pas un trou DANS la séquence : c'est une
        // vente HORS séquence, ce que ce contrôle ne savait pas dire.
        //
        // On REND COMPTE, on ne RÉPARE PAS. Allouer un numéro ici serait improviser un chemin
        // fiscal que le webhook Mollie refuse explicitement de prendre (point d'activation
        // propriétaire G-W5) ; l'attribution reste une décision humaine. Cette commande reste
        // donc strictement en lecture seule — mais elle sort désormais en ÉCHEC, pour qu'un cron
        // ou une porte de livraison le remarque au lieu de lire « OK ».
        $sansNumero = Order::query()
            ->withTrashed()
            ->withoutGlobalScope(BranchScope::class)
            ->where('payment_status', PaymentStatus::PAID)
            ->whereNull('fiscal_sequence_no')
            ->when($branchFilter !== null, fn ($q) => $q->where('branch_id', (int) $branchFilter))
            ->orderBy('id')
            ->get(['id', 'branch_id', 'total', 'source_surface', 'status', 'created_at']);

        if ($sansNumero->isNotEmpty()) {
            $anyGap = true;
            $this->newLine();
            $this->error('VENTES PAYÉES SANS NUMÉRO FISCAL : ' . $sansNumero->count()
                . ' commande(s), ' . number_format((float) $sansNumero->sum('total'), 2, ',', ' ') . ' EUR');

            foreach ($sansNumero->groupBy(fn ($o) => $o->source_surface ?: '(non renseignée)') as $surface => $lot) {
                $this->line('   ' . $surface . ' : ' . $lot->count() . ' cmd, '
                    . number_format((float) $lot->sum('total'), 2, ',', ' ') . ' EUR'
                    . ' — #' . $lot->take(10)->pluck('id')->implode(', #')
                    . ($lot->count() > 10 ? ', …' : ''));
            }

            // Le pire cas mérite sa propre ligne : encaissée ET jamais promue, donc jamais
            // servie. Le client a payé et n'a rien reçu.
            $jamaisServies = $sansNumero->where('status', OrderStatus::PENDING);
            if ($jamaisServies->isNotEmpty()) {
                $this->error('   dont PAYÉES ET JAMAIS PROMUES (client débité, jamais servi) : #'
                    . $jamaisServies->pluck('id')->implode(', #'));
            }
        }

        if ($anyGap) {
            $this->newLine();
            $this->warn('⚠ Au moins un trou/doublon de séquence fiscale détecté. Un trou en DEV peut venir d\'un hard-delete de test ; en PROD il exige une investigation NF525 (le numérotage doit rester gap-free).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Toutes les branches scannées : séquence fiscale GAP-FREE, sans doublon.');

        return self::SUCCESS;
    }

    /**
     * Compacte une liste triée d'entiers en plages lisibles : [2506,2507,2508,2600] → ['2506-2508','2600'].
     *
     * @param  array<int>  $nums
     * @return array<string>
     */
    private function compactRanges(array $nums): array
    {
        $ranges = [];
        $start = $prev = $nums[0];
        for ($i = 1; $i < count($nums); $i++) {
            if ($nums[$i] === $prev + 1) {
                $prev = $nums[$i];

                continue;
            }
            $ranges[] = $start === $prev ? (string) $start : "{$start}-{$prev}";
            $start = $prev = $nums[$i];
        }
        $ranges[] = $start === $prev ? (string) $start : "{$start}-{$prev}";

        return $ranges;
    }
}
