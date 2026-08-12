<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Hardware\EscPosCommandBuilder;
use App\Services\Hardware\EscPosTicketBytesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [TIROIR 2026-08-13 · owner « le tiroir ne s'ouvre pas »] L'impulsion d'ouverture voyage avec le
 * ticket client.
 *
 * POURQUOI CE CHEMIN
 * ------------------
 * La commande d'ouverture existait déjà (`EscPosCommandBuilder::openDrawerCommand()`, exactement
 * 1B 70 00 19 FA) mais n'empruntait que `EscPosPrinterService::openDrawer()`, qui pousse du
 * SERVEUR vers l'imprimante en TCP. Topologie impossible — le serveur est chez l'hébergeur, le
 * tiroir est câblé sur l'imprimante au bout du réseau du restaurant — et de surcroît simulée sur
 * la machine réelle (`POS_SIMULATION_HARDWARE=true`). Le tiroir ne s'ouvrait donc jamais.
 *
 * Attachée aux octets du ticket, l'impulsion part avec le reçu, sans toucher au pont installé sur
 * le PC de la caisse.
 *
 * CE QUE CES TESTS VERROUILLENT
 * -----------------------------
 * Un tiroir qui s'ouvre est un geste de caisse. Chaque règle métier a son test, parce qu'un
 * tiroir qui claque au mauvais moment est aussi grave qu'un tiroir qui reste fermé.
 */
class DrawerOpensWithReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
    }

    private function vente(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'          => $this->branch->id,
            'source_surface'     => 'pos',
            'status'             => OrderStatus::PREPARED,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ], $overrides));
    }

    private function octets(Order $order, bool $duplicata = false, bool $borne = false): string
    {
        return (string) app(EscPosTicketBytesService::class)
            ->render((int) $this->branch->id, (int) $order->id, 'client', $duplicata, $borne);
    }

    private function contientImpulsion(string $octets): bool
    {
        return str_contains($octets, EscPosCommandBuilder::openDrawerCommand());
    }

    /** @test */
    public function une_vente_en_especes_ouvre_le_tiroir(): void
    {
        $this->assertTrue(
            $this->contientImpulsion($this->octets($this->vente())),
            'le ticket d\'une vente en espèces doit porter l\'impulsion d\'ouverture'
        );
    }

    /** @test Une vente carte ne doit PAS faire claquer le tiroir. */
    public function une_vente_par_carte_laisse_le_tiroir_ferme(): void
    {
        $this->assertFalse(
            $this->contientImpulsion($this->octets($this->vente(['pos_payment_method' => PosPaymentMethod::CARD])))
        );
    }

    /**
     * @test
     * Paiement MIXTE : dès qu'une ligne est en espèces, le tiroir s'ouvre.
     *
     * Le marqueur de la commande peut dire « carte » alors qu'une partie a été réglée en espèces —
     * ne lire que ce marqueur laisserait le caissier sans tiroir pour rendre la monnaie.
     */
    public function une_vente_mixte_dont_une_ligne_en_especes_ouvre_le_tiroir(): void
    {
        $order = $this->vente(['pos_payment_method' => PosPaymentMethod::CARD]);
        DB::table('order_payments')->insert([
            'order_id' => $order->id, 'branch_id' => $this->branch->id,
            'mode' => PosPaymentMethod::CARD, 'amount' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_payments')->insert([
            'order_id' => $order->id, 'branch_id' => $this->branch->id,
            'mode' => PosPaymentMethod::CASH, 'amount' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(
            $this->contientImpulsion($this->octets($order)),
            'une part réglée en espèces doit ouvrir le tiroir, même si le reste est en carte'
        );
    }

    /** @test Réimprimer un vieux reçu ne doit JAMAIS ouvrir le tiroir. */
    public function un_duplicata_n_ouvre_pas_le_tiroir(): void
    {
        $this->assertFalse(
            $this->contientImpulsion($this->octets($this->vente(), duplicata: true)),
            'un duplicata est une copie, pas un encaissement'
        );
    }

    /** @test La borne n'a pas de tiroir devant le client. */
    public function le_ticket_de_la_borne_n_ouvre_pas_le_tiroir(): void
    {
        $this->assertFalse(
            $this->contientImpulsion($this->octets($this->vente(), borne: true))
        );
    }

    /** @test L'interrupteur d'exploitation coupe l'ouverture sans déploiement. */
    public function l_interrupteur_coupe_l_ouverture(): void
    {
        Config::set('printing.drawer.open_with_receipt', false);

        $this->assertFalse($this->contientImpulsion($this->octets($this->vente())));
    }

    /** @test L'impulsion est EXACTEMENT celle attendue par les imprimantes Epson. */
    public function l_impulsion_est_la_sequence_standard(): void
    {
        $this->assertSame(
            "\x1B\x70\x00\x19\xFA",
            EscPosCommandBuilder::openDrawerCommand(),
            'séquence standard : ESC p 0 25 250 (pin 2, 25 ms / 250 ms)'
        );
    }
}
