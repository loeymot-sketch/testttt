<?php

namespace Tests\Feature\Pos;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [AUDIT-SUPERVISEUR 2026-08-25 · P0] Un encaissement ne doit JAMAIS disparaître
 * du total parce que sa commande n'existe plus.
 *
 * LE DÉFAUT — `CashOverviewController` filtrait par `whereHas('order', …)`, une
 * jointure INTERNE. Une transaction dont la commande a été effacée n'était pas
 * marquée, pas signalée, pas mise à zéro : elle n'apparaissait NULLE PART.
 *
 * Mesuré sur la base réelle avant correctif, période 23–24/08 :
 *   sans la jointure : 27 lignes / 247,70 €
 *   avec la jointure : 17 lignes / 222,70 €
 *   perdues          : 10 lignes /  25,00 €  (toutes `counter_cash`)
 * Et ces 25,00 € étaient exactement ce que le bandeau de réconciliation
 * continuait d'afficher — d'où la contradiction qu'on avait d'abord imputée au
 * bandeau, à tort. C'est la page qui perdait des lignes, pas le bandeau qui
 * mentait.
 *
 * LA RÈGLE QU'ON VERROUILLE ICI : sur un écran d'argent, une ligne peut être
 * anormale, elle ne peut pas être INVISIBLE. Les orphelins sont comptés dans le
 * total ET annoncés séparément, pour qu'un écart de rapprochement ait une
 * explication au lieu d'un trou.
 */
class CashOverviewOrphanPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /** Un administrateur global (branch_id = 0) voit toutes les branches. */
    private function actingAsGlobalAdmin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    private function paiement(?Order $order, float $montant, string $methode = 'counter_cash'): Transaction
    {
        return Transaction::create([
            'order_id'       => $order?->id ?? 999999,
            'sign'           => '+',
            'transaction_no' => 'COUNTER-' . ($order?->id ?? 999999) . '-' . now()->format('YmdHis') . '-' . uniqid(),
            'amount'         => $montant,
            'payment_method' => $methode,
            'type'           => 'payment',
        ]);
    }

    /**
     * Le cas exact mesuré en production : une transaction survit à sa commande.
     * Elle doit rester dans le total.
     *
     * @test
     */
    public function un_encaissement_dont_la_commande_a_disparu_reste_compte_dans_le_total(): void
    {
        $this->actingAsGlobalAdmin();
        $branch = Branch::factory()->create();

        $vivante = Order::factory()->create(['branch_id' => $branch->id]);
        $this->paiement($vivante, 10.00);

        // L'orpheline : la commande est créée puis effacée EN DUR, la transaction reste.
        $condamnee = Order::factory()->create(['branch_id' => $branch->id]);
        $this->paiement($condamnee, 2.50);
        $condamnee->forceDelete();

        $reponse = $this->getJson('/api/admin/cash-overview?from=' . now()->subDay()->toDateString()
            . '&to=' . now()->addDay()->toDateString());

        $reponse->assertOk();
        $data = $reponse->json();

        $total = (float) ($data['summary']['total'] ?? $data['summary']['grand_total'] ?? 0);

        $this->assertEqualsWithDelta(
            12.50,
            $total,
            0.001,
            'Le total doit valoir 10,00 + 2,50 : un encaissement orphelin est de l\'argent qui a bougé.'
        );
    }

    /**
     * Compté ne suffit pas : il doit aussi être VU. Un écart de rapprochement
     * sans explication est pire qu'un écart expliqué.
     *
     * @test
     */
    public function l_encaissement_orphelin_est_annonce_separement(): void
    {
        $this->actingAsGlobalAdmin();
        $branch = Branch::factory()->create();

        $condamnee = Order::factory()->create(['branch_id' => $branch->id]);
        $this->paiement($condamnee, 2.50);
        $idPerdu = $condamnee->id;
        $condamnee->forceDelete();

        $data = $this->getJson('/api/admin/cash-overview?from=' . now()->subDay()->toDateString()
            . '&to=' . now()->addDay()->toDateString())->assertOk()->json();

        $this->assertArrayHasKey('orphan_payments', $data, 'Le bloc des orphelins doit exister.');
        $this->assertSame(1, $data['orphan_payments']['count']);
        $this->assertEqualsWithDelta(2.50, (float) $data['orphan_payments']['total'], 0.001);
        $this->assertContains($idPerdu, $data['orphan_payments']['order_ids']);
    }

    /**
     * Le cas sain ne doit pas être bruyant : sans orphelin, le bloc annonce zéro
     * et n'invente aucune alerte.
     *
     * @test
     */
    public function sans_orphelin_le_bloc_annonce_zero_sans_crier(): void
    {
        $this->actingAsGlobalAdmin();
        $branch = Branch::factory()->create();
        $this->paiement(Order::factory()->create(['branch_id' => $branch->id]), 7.40);

        $data = $this->getJson('/api/admin/cash-overview?from=' . now()->subDay()->toDateString()
            . '&to=' . now()->addDay()->toDateString())->assertOk()->json();

        $this->assertSame(0, $data['orphan_payments']['count']);
        $this->assertSame([], $data['orphan_payments']['order_ids']);
    }

    /**
     * Le filtre de branche ne doit pas redevenir une trappe : un orphelin n'a
     * aucune branche à comparer, l'escamoter recréerait le défaut pour les
     * gestionnaires de branche.
     *
     * @test
     */
    public function le_filtre_de_branche_n_escamote_pas_les_orphelins(): void
    {
        $this->actingAsGlobalAdmin();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $this->paiement(Order::factory()->create(['branch_id' => $branchA->id]), 10.00);
        $this->paiement(Order::factory()->create(['branch_id' => $branchB->id]), 20.00);

        $condamnee = Order::factory()->create(['branch_id' => $branchA->id]);
        $this->paiement($condamnee, 2.50);
        $condamnee->forceDelete();

        $data = $this->getJson('/api/admin/cash-overview?branch_id=' . $branchA->id
            . '&from=' . now()->subDay()->toDateString()
            . '&to=' . now()->addDay()->toDateString())->assertOk()->json();

        $total = (float) ($data['summary']['total'] ?? $data['summary']['grand_total'] ?? 0);

        $this->assertEqualsWithDelta(
            12.50,
            $total,
            0.001,
            'Branche A (10,00) + l\'orphelin (2,50). La branche B ne doit pas entrer ; l\'orphelin ne doit pas sortir.'
        );
    }
}
