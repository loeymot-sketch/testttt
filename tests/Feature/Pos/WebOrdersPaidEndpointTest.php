<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] La commande web PAYÉE ne doit plus être muette
 * en caisse.
 *
 * L'INCIDENT QUE CE TEST VERROUILLE
 * ---------------------------------
 * Le 2026-08-10 à 20h31, la commande #440 (site, carte, 31,40 € encaissés, 4 articles) n'a
 * produit AUCUN signal en caisse. Deux gardes justes prises séparément laissaient un trou :
 * `web-orders/pending` exige `status = PENDING` ET exclut `CARD + UNPAID`. Pendant sa fenêtre
 * PENDING la commande est carte+non-payée (exclue), et dès que le paiement tombe elle est
 * promue ACCEPT→PREPARING (exclue). Elle n'entrait donc dans ce panneau à AUCUN instant de sa
 * vie. Seul l'écran KDS la montrait ; personne ne l'a vue, le client a attendu.
 *
 * Les deux premiers tests sont volontairement une PAIRE : l'un prouve que le nouveau panneau
 * la voit, l'autre prouve que l'ancien ne la voyait pas. Sans le second, rien ne dit que ce
 * panneau ne fait pas doublon — et rien ne détecterait une régression qui le refermerait.
 */
class WebOrdersPaidEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function paidIds(): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/web-orders/paid');
        $res->assertOk();

        return collect($res->json('data'))->pluck('id')->all();
    }

    private function pendingIds(): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos/web-orders/pending');
        $res->assertOk();

        return collect($res->json('data'))->pluck('id')->all();
    }

    /** Réplique fidèle de la commande #440 telle qu'elle existe en production. */
    private function commande440(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'       => $this->branch->id,
            'source_surface'  => 'web',
            'order_type'      => OrderType::TAKEAWAY,
            'status'          => OrderStatus::PREPARING,
            'payment_status'  => PaymentStatus::PAID,
            'payment_method'  => PaymentGateway::CARD,
            'order_datetime'  => now(),
        ], $overrides));
    }

    /** @test */
    public function la_commande_web_payee_partie_en_cuisine_est_visible_en_caisse(): void
    {
        $web = $this->commande440();

        $this->assertContains($web->id, $this->paidIds());
    }

    /** @test Le jumeau : la MÊME commande était invisible dans l'ancien panneau — c'est le trou. */
    public function la_meme_commande_reste_absente_de_l_ancienne_file_a_accepter(): void
    {
        $web = $this->commande440();

        $this->assertNotContains(
            $web->id,
            $this->pendingIds(),
            'une commande déjà payée ne doit PAS être proposée à l\'acceptation — sinon le caissier rejoue une transition déjà faite'
        );
    }

    /** @test */
    public function une_commande_encore_en_vol_de_paiement_n_apparait_pas(): void
    {
        // Carte + non payée = paiement en ligne en cours. Elle n'est pas partie en cuisine :
        // en faire sonner la caisse ferait bipper à chaque panier abandonné.
        $enVol = $this->commande440([
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        $this->assertNotContains($enVol->id, $this->paidIds());
    }

    /** @test */
    public function une_commande_deja_preparee_sort_du_panneau(): void
    {
        // PREPARED = la cuisine a fini ; elle remonte dans « Prêt à livrer », pas ici.
        $finie = $this->commande440(['status' => OrderStatus::PREPARED]);

        $this->assertNotContains($finie->id, $this->paidIds());
    }

    /** @test */
    public function une_commande_trop_ancienne_ne_squatte_pas_le_panneau(): void
    {
        // Il en existe en production (#333, payée le 2026-08-03, jamais bumpée). Sans borne
        // basse elle resterait à vie dans le panneau et le bip deviendrait du bruit ignoré.
        $vieille = $this->commande440([
            'order_datetime' => now()->subHours((int) config('oss.stale_window_hours', 8) + 1),
        ]);

        $this->assertNotContains($vieille->id, $this->paidIds());
    }

    /** @test */
    public function exclut_les_autres_surfaces_et_les_autres_branches(): void
    {
        $caisse = $this->commande440(['source_surface' => 'pos']);
        $borne  = $this->commande440(['source_surface' => 'kiosk']);
        $autreBranche = $this->commande440([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $ids = $this->paidIds();

        $this->assertNotContains($caisse->id, $ids, 'une vente caisse est déjà sous les yeux du caissier');
        $this->assertNotContains($borne->id, $ids, 'la borne a son propre panneau « à encaisser »');
        $this->assertNotContains($autreBranche->id, $ids, 'isolation branche : pas de fuite cross-branch');
    }

    /** @test Une livraison site porte source_surface='delivery' — même équivalence que la file PENDING. */
    public function une_livraison_site_payee_est_aussi_visible(): void
    {
        $livraison = $this->commande440([
            'source_surface' => 'delivery',
            'order_type'     => OrderType::DELIVERY,
        ]);

        $this->assertContains($livraison->id, $this->paidIds());
    }
}
