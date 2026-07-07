<?php

namespace Database\Seeders;

use App\Models\PaymentTerminal;
use Illuminate\Database\Seeder;

/**
 * [owner 2026-07-08] TPE SIMULÉ à la caisse (« aucun TPE configuré » au paiement carte).
 *
 * Le terminal est EXTERNE (owner) → V1 = simulation pure : la caisse doit juste
 * pouvoir sélectionner un TPE pour encaisser en carte (comptage espèces vs carte
 * en gestion), sans intégration matérielle. Le front exige un terminal actif
 * (PaymentComponent.fetchPaymentTerminals status=1) ; s'il n'y en a AUCUN en base
 * → « Aucun TPE configuré » → carte impossible. Ce seeder garantit qu'un TPE
 * gateway=simulation, status=ACTIVE existe sur la branche 1.
 *
 * Idempotent (met à jour l'existant par nom/branche, sinon crée). N'affecte PAS
 * le fiscal : le paiement carte simulé est encaissé normalement (SplitPaymentService
 * / PaymentService gèrent pos.simulation_hardware). Rollback :
 *   UPDATE payment_terminals SET status=5 WHERE gateway_type='simulation';
 */
class SimulatedTpeTerminal20260708Seeder extends Seeder
{
    public function run(): void
    {
        $branchId = 1;
        $name = 'TPE Le Cayenne #1';

        $terminal = PaymentTerminal::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('gateway_type', 'simulation')
            ->first();

        if (! $terminal) {
            // Réutilise un terminal du même nom s'il existe (évite les doublons au reseed).
            $terminal = PaymentTerminal::withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->where('name', $name)
                ->first();
        }

        $attributes = [
            'branch_id'     => $branchId,
            'name'          => $name,
            'gateway_type'  => 'simulation',
            'serial_number' => 'SIM-CAYENNE-1',
            'status'        => PaymentTerminal::STATUS_ACTIVE, // 1
            'fee_fixed'     => 0,
            'fee_percent'   => 0,
        ];

        if ($terminal) {
            $terminal->forceFill($attributes)->save();
            $this->command?->info('  TPE simulé rafraîchi (#'.$terminal->id.', actif, branche 1).');

            return;
        }

        $created = PaymentTerminal::withoutGlobalScopes()->create($attributes);
        $this->command?->info('  TPE simulé créé (#'.$created->id.', actif, branche 1).');
    }
}
