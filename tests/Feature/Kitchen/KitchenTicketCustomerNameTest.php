<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Printer;
use App\Models\User;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\Kitchen\KitchenTicketAutoPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL CAISSE 2026-08-22] LE NOM DU CLIENT ARRIVE-T-IL JUSQU'AU PAPIER ?
 *
 * LE TROU QUE CE FICHIER COMBLE
 * Une cartographie du trajet complet a montré que personne ne couvrait le dernier tronçon.
 * D'un côté, `KitchenTicketAutoPrintTest` exerce le VRAI déclenchement (événement, claim
 * atomique, dédoublonnage, `printOnce` réel) mais ne porte JAMAIS de nom client. De l'autre,
 * les tests de rendu (`KitchenTicketHeaderAndDistinctionTest`, `TicketWidthSafeTest`) appellent
 * `renderKitchenTicket()` directement sur un `Order` construit en mémoire — ils court-circuitent
 * `claim()` et surtout `hydrate()`.
 *
 * Conséquence mesurable de ce trou : un `select()` en amont qui oublierait `pos_customer_name`,
 * un renommage de colonne, ou une régression dans `hydrate()` casserait le ticket cuisine —
 * et **aucun test ne rougirait**. Le personnel perdrait le nom qui sert à appeler la commande,
 * en silence, jusqu'à ce qu'un client réclame au comptoir.
 *
 * POURQUOI CE CHAMP MÉRITE UN TEST À LUI SEUL
 * Il a déjà été perdu une fois, autrement : le 2026-08-05, il existait mais restait
 * indécouvrable à l'écran, et le propriétaire écrivait le nom au stylo sur le ticket. Le
 * 2026-08-22, il est redevenu visible sans geste (`pos-v5.css`, plafond de l'en-tête du panier).
 * Ce qu'on protège ici, c'est l'autre bout de la même chaîne.
 *
 * MÉTHODE — on intercepte au niveau du TRANSPORT, pas du service : c'est le dernier maillon
 * avant le câble, donc ce qu'on lit est bien ce qui sortirait du rouleau. Idiome repris de
 * `KitchenTicketAutoPrintTest`, volontairement, pour que les deux fichiers vieillissent ensemble.
 */
class KitchenTicketCustomerNameTest extends TestCase
{
    use RefreshDatabase;

    /** @var object{captures: array<int, string>} */
    private $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new class implements PrinterTransportInterface
        {
            /** @var array<int, string> */
            public array $captures = [];

            public function send(string $bytes, array $config): bool
            {
                $this->captures[] = $bytes;

                return true;
            }

            public function lastError(): ?string
            {
                return null;
            }
        };
        $this->app->instance(PrinterTransportInterface::class, $this->transport);

        // Sans ça, le mode « bypass » du poste de développement court-circuiterait l'envoi et
        // ces cas passeraient au vert sans rien prouver.
        config(['printing.bypass.enabled' => false]);
    }

    private function branche(): Branch
    {
        return Branch::query()->find(1) ?? Branch::factory()->create(['id' => 1]);
    }

    private function imprimanteCuisine(): Printer
    {
        return Printer::create([
            'branch_id' => $this->branche()->id,
            'name' => 'Epson TM-m30 Cuisine',
            'type' => 'escpos_tcp',
            'host' => '192.168.192.168',
            'port' => 9100,
            'station' => 'kitchen_hot',
            'width_chars' => 48,
            'status' => Status::ACTIVE,
        ]);
    }

    private function commandeCaisse(array $attrs = []): Order
    {
        $branch = $this->branche();

        return Order::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'source_surface' => 'pos',
            'status' => OrderStatus::ACCEPT,
        ], $attrs));
    }

    /** Le ticket est transcodé en CP858 avant l'envoi : on compare dans la même page de code. */
    private function enPageDeCode(string $texte): string
    {
        $converti = @iconv('UTF-8', 'CP858//TRANSLIT', $texte);

        return $converti === false ? $texte : $converti;
    }

    private function papier(): string
    {
        return implode('', $this->transport->captures);
    }

    // ── Le tronçon qui n'était couvert par rien ───────────────────────────────

    public function test_le_nom_saisi_en_caisse_arrive_sur_le_ticket_cuisine(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commandeCaisse(['pos_customer_name' => 'Dupont']);

        app(KitchenTicketAutoPrinter::class)->printOnce($order, 'test');

        $this->assertNotSame('', $this->papier(), 'Aucun octet envoyé : le ticket n\'est pas parti.');
        $this->assertStringContainsString(
            'Client : Dupont',
            $this->papier(),
            'Le nom saisi au comptoir doit survivre à hydrate() et au rendu, jusqu\'aux octets.'
        );
    }

    /**
     * Un prénom accentué est le cas NORMAL en France, pas un cas limite. Le ticket est transcodé
     * une seule fois en CP858 ; si cette conversion se perdait, le nom deviendrait illisible sur
     * le rouleau sans qu'aucun autre test ne le voie.
     */
    public function test_un_prenom_accentue_reste_lisible_sur_le_rouleau(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commandeCaisse(['pos_customer_name' => 'Célestin Bénédicte']);

        app(KitchenTicketAutoPrinter::class)->printOnce($order, 'test');

        $this->assertStringContainsString(
            $this->enPageDeCode('Client : Célestin Bénédicte'),
            $this->papier(),
            'Le nom accentué doit apparaître dans la page de code de l\'imprimante (CP858).'
        );
    }

    /**
     * Sans nom, on n'imprime PAS une ligne vide ni le mot « null ». Une ligne « Client : » sans
     * rien derrière ferait chercher au personnel un nom qui n'existe pas.
     */
    public function test_sans_nom_saisi_aucune_ligne_client_nest_imprimee(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commandeCaisse(['pos_customer_name' => null]);

        app(KitchenTicketAutoPrinter::class)->printOnce($order, 'test');

        $papier = $this->papier();
        $this->assertNotSame('', $papier, 'Le ticket doit tout de même partir.');
        $this->assertStringNotContainsString('Client :', $papier);
        $this->assertStringNotContainsString('null', $papier);
    }

    /**
     * Une chaîne d'espaces n'est pas un nom. `trim()` la ramène à vide côté rendu — ce cas
     * verrouille ce comportement, parce qu'un `?? ''` mal placé le ferait sauter sans bruit.
     */
    public function test_un_nom_fait_uniquement_despaces_est_traite_comme_absent(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commandeCaisse(['pos_customer_name' => '   ']);

        app(KitchenTicketAutoPrinter::class)->printOnce($order, 'test');

        $this->assertStringNotContainsString('Client :', $this->papier());
    }
}
