<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [LOCK M6-002 — TEST AUTO-ARMANT] Ventilation par-tranche du Z signé pour les paiements split.
 *
 * Défaut (P1 NF525, catalogue pre-cloud M6-002) : `applyOrderToTotals` verse le total INTÉGRAL
 * d'un split dans le bucket du tender DOMINANT → X-carte/X-espèces FAUX dans le Z signé.
 *
 * Ce test est SKIPPÉ tant que le patch du LOCK (plans/LOCK_ZREPORT_SPLIT_BUCKETING_M6-002)
 * n'est pas appliqué (ZReportService est FROZEN §7 — contresignature owner requise). Il
 * S'ARME AUTOMATIQUEMENT dès que le service lit les tranches `order_payments` : aucun
 * changement de test ne sera nécessaire au moment du LOCK. (§7 autorise explicitement
 * l'ajout de tests de régression sur les zones frozen — le service, lui, n'est PAS touché.)
 */
class ZReportSplitBucketingLockTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function le_z_signe_ventile_un_split_par_tranche_et_non_par_tender_dominant(): void
    {
        $source = (string) file_get_contents(app_path('Services/Fiscal/ZReportService.php'));
        if (! str_contains($source, 'order_payments') && ! str_contains($source, '->payments')) {
            $this->markTestSkipped(
                'LOCK M6-002 non contresigné — ZReportService ne lit pas encore les tranches '
                . 'order_payments (état attendu). Le test s\'armera automatiquement à l\'application du LOCK.'
            );
        }

        // ── Armé : le patch est appliqué, on verrouille le comportement corrigé. ──
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config()->set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($user);

        $svc = app(ZReportService::class);
        $svc->open($branch->id, $user);

        // Split RÉEL : 15 € CASH + 10 € CARD, pos_payment_method = CASH (dominant).
        $order = Order::factory()->create([
            'user_id'            => $user->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'total'              => 25.00,
            'subtotal'           => 25.00,
            'fiscal_sequence_no' => 1,
            'order_datetime'     => now(),
        ]);
        foreach ([[PosPaymentMethod::CASH, 15.00], [PosPaymentMethod::CARD, 10.00]] as [$mode, $amount]) {
            OrderPayment::query()->create([
                'order_id'  => $order->id,
                'branch_id' => $branch->id,
                'mode'      => $mode,
                'amount'    => $amount,
            ]);
        }

        $z = $svc->close($branch->id, $user);
        $byMethod = (array) $z->total_by_method;

        $this->assertEqualsWithDelta(15.00, (float) ($byMethod[(string) PosPaymentMethod::CASH] ?? 0), 0.001,
            'Z signé : la tranche ESPÈCES (15 €) doit être dans le bucket CASH, pas fondue dans le dominant.');
        $this->assertEqualsWithDelta(10.00, (float) ($byMethod[(string) PosPaymentMethod::CARD] ?? 0), 0.001,
            'Z signé : la tranche CARTE (10 €) doit être dans le bucket CARD.');
        $this->assertEqualsWithDelta(25.00, array_sum(array_map('floatval', $byMethod)), 0.001,
            'La somme de la ventilation reste égale au total (total_ttc inchangé).');
    }
}
