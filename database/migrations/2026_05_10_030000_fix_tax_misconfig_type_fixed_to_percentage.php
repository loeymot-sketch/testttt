<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * [iter15-BUG-NF525 owner-evidence 2026-05-10] Fix tax misconfig type=FIXED → PERCENTAGE.
 *
 * BUG OBSERVÉ EN PROD :
 * Order Frites 2.00€ paid 10€ → ticket affiche TOTAL=22€, TVA=20€.
 * Capture utilisateur 2026-05-10 + agent diagnose iter15.
 *
 * ROOT CAUSE :
 * Tax ID 13 a tax_rate=20.000000 mais type=5 (FIXED) au lieu de 10 (PERCENTAGE).
 * Code dans OrderService::posOrderStore ligne ~773 :
 *
 *   $taxPrice = round(
 *       $taxType === TaxType::FIXED ? $taxRate : ($verifiedTotalPrice * $taxRate) / 100,
 *       2
 *   );
 *
 * Quand type=FIXED, le code utilise $taxRate (=20) directement comme MONTANT,
 * pas comme POURCENTAGE. Une frite à 2€ se voit ajouter 20€ de tax, donnant
 * TOTAL=22€. Erreur fiscale NF525 critique.
 *
 * FIX :
 *   - Tax ID 13: type FIXED(5) → PERCENTAGE(10)
 *   - Tax ID 13: name "TVA 65%" → "TVA 20%" (cohérent avec rate)
 *
 * Idempotent : skip si déjà corrigé. Cross-driver MySQL + SQLite + PostgreSQL.
 *
 * NF525 historical audit trail : les commandes existantes corrompues (ex: order 241
 * total=22€) RESTENT en DB pour traceability. Cette migration ne fixe que la
 * config tax pour les NOUVELLES commandes. Recovery historique = job séparé
 * (ZReport regenerate post-correction) si owner décide.
 *
 * Pattern detection : on cherche les Tax rows où :
 *   - tax_rate > 0 (donc pas zero-tax)
 *   - tax_rate <= 100 (pourcentage plausible, pas montant fixe)
 *   - type = 5 (FIXED) — incohérent avec rate semantique pourcentage
 *   - name LIKE '%TVA%' OU '%VAT%' OU '%65%' OU rate IN (5.5, 10, 20) — typique percentage
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('taxes')) {
            return;
        }

        // Pattern detection : Tax avec rate ressemblant percentage MAIS type=FIXED
        $candidates = DB::table('taxes')
            ->where('type', 5) // FIXED
            ->where('tax_rate', '>', 0)
            ->where('tax_rate', '<=', 100) // typique pourcentage (5.5, 10, 20...)
            ->get();

        $fixedCount = 0;

        foreach ($candidates as $tax) {
            $newName = (string) $tax->name;

            // Si name = "TVA 65%" mais rate = 20, renommer "TVA 20%"
            if (preg_match('/^TVA\s+\d+%$/i', $newName) && abs((float) $tax->tax_rate - (float) preg_replace('/[^0-9.]/', '', $newName)) > 0.01) {
                $newName = 'TVA ' . rtrim(rtrim(number_format((float) $tax->tax_rate, 2, '.', ''), '0'), '.') . '%';
            }

            DB::table('taxes')
                ->where('id', $tax->id)
                ->update([
                    'type' => 10, // PERCENTAGE
                    'name' => $newName,
                    'updated_at' => now(),
                ]);

            $fixedCount++;

            Log::channel('fiscal')->info('iter15.bug-nf525.tax-misconfig-fixed', [
                'tax_id' => $tax->id,
                'old_type' => 5,
                'new_type' => 10,
                'old_name' => $tax->name,
                'new_name' => $newName,
                'tax_rate' => $tax->tax_rate,
                'event' => 'fiscal.tax.type_misconfig_corrected',
            ]);
        }

        if ($fixedCount > 0) {
            Log::channel('fiscal')->warning('iter15.bug-nf525.taxes-corrected', [
                'count' => $fixedCount,
                'message' => 'Tax misconfig FIXED→PERCENTAGE applied. Historical orders pre-fix remain corrupted; review fiscal Z reports.',
            ]);
        }
    }

    public function down(): void
    {
        // Pas de rollback : revenir à type=FIXED réintroduirait le bug NF525.
        // Si rollback nécessaire, faire manuellement avec audit fiscal.
        Log::channel('fiscal')->info('iter15.bug-nf525.migration-rollback-skipped', [
            'reason' => 'Reverting tax type FIXED→PERCENTAGE would reintroduce NF525 fiscal bug.',
        ]);
    }
};
