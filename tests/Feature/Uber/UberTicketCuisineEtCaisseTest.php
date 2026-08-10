<?php

namespace Tests\Feature\Uber;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [UBER 2026-08-10 · owner « qui va l'imprimer direct avec symbole en cuisine …
 * et l'ajouter à la caisse »]
 *
 * Une commande Uber ne se prépare pas comme une commande de comptoir : elle part
 * dans un sac scellé, souvent avec un livreur qui attend déjà. Le cuisinier doit
 * le voir en un coup d'œil, pas le déduire d'un « livraison » perdu trois lignes
 * plus bas.
 *
 * Les pièces existent — bandeau dans le rendu, impression déclenchée par le
 * STATUT (donc valable pour toutes les surfaces), `source_surface = 'uber_eats'`
 * posé par l'ingesteur. Ce qui manquait, c'est le LIEN : rien n'obligeait ces
 * trois-là à parler de la même valeur.
 *
 * Le risque est précis. `OrderReceiptEscPosRenderer::isUberOrder()` compare
 * `source_surface` à `'uber_eats'`. Le jour où l'ingesteur écrit `'ubereats'`,
 * `'UBER'` ou `'uber-eats'`, le bandeau disparaît du ticket — **sans erreur,
 * sans alerte** : la commande s'imprime, elle a juste l'air d'une commande
 * normale. Ces tests transforment ce silence en échec.
 */
class UberTicketCuisineEtCaisseTest extends TestCase
{
    use RefreshDatabase;

    /** Rend le ticket cuisine d'une commande, sans base : on teste le rendu. */
    private function ticket(array $attrs = []): string
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'phone' => '+33365678291',
        ]);
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 7.40, 'composition_snapshot' => [], 'instruction' => '',
        ]);
        $oi->name = 'Cayenne';

        $order = (new Order)->forceFill(array_merge([
            'order_serial_no' => 'UBER-TEST-1',
            'queue_number' => 'U0001',
            'order_type' => \App\Enums\OrderType::DELIVERY,
            'subtotal' => 7.40, 'total' => 7.40,
            'order_datetime' => '2026-08-10 20:00:00',
        ], $attrs));
        $order->setRelation('orderItems', collect([$oi]));
        $order->setRelation('branch', $branch);

        return app(OrderReceiptEscPosRenderer::class)->renderKitchenTicket($order, ['width_chars' => 48]);
    }

    private function lisible(string $bytes): string
    {
        $s = preg_replace('/\x1B[aEtd!@].|\x1D![\x00-\xFF]|\x1B-.|\x1DV.|\x1B\x40/s', '', $bytes);
        $t = (string) iconv('CP858', 'UTF-8//IGNORE', (string) $s);

        return (string) preg_replace('/[\x00-\x09\x0B-\x1F]/', '', $t);
    }

    public function test_une_commande_uber_porte_son_bandeau_sur_le_ticket_cuisine(): void
    {
        $t = $this->lisible($this->ticket(['source_surface' => 'uber_eats']));

        $this->assertStringContainsString('UBER EATS', $t,
            "Le cuisinier doit voir l'origine Uber en un coup d'œil.\nTicket :\n{$t}");
    }

    public function test_le_bandeau_est_place_AVANT_le_detail_de_la_commande(): void
    {
        // Un bandeau imprimé en bas de ticket ne sert à rien : la décision
        // (sac scellé, livreur qui attend) se prend avant de commencer.
        $t = $this->lisible($this->ticket(['source_surface' => 'uber_eats']));
        $posBandeau = mb_strpos($t, 'UBER EATS');
        // On vise le séparateur qui ouvre le bloc des articles, et non le nom du
        // produit : la cuisine le lit en abrégé symbolique (« Cayenne » → « CAY »),
        // une abréviation qui peut évoluer alors que la structure, elle, tient.
        $posArticles = mb_strpos($t, '====');

        $this->assertNotFalse($posBandeau, "Bandeau absent.\nTicket :\n{$t}");
        $this->assertNotFalse($posArticles, "Bloc des articles introuvable.\nTicket :\n{$t}");
        $this->assertLessThan($posArticles, $posBandeau,
            "Le bandeau doit précéder le détail, sinon il est lu trop tard.\nTicket :\n{$t}");
    }

    public function test_une_commande_de_comptoir_ne_porte_PAS_le_bandeau(): void
    {
        // Le contraire du test précédent, et il compte autant : un bandeau qui
        // s'affiche partout ne distingue plus rien.
        foreach (['pos', 'kiosk', 'web', null] as $surface) {
            $t = $this->lisible($this->ticket(['source_surface' => $surface]));
            $this->assertStringNotContainsString('UBER EATS', $t,
                "Surface « ".var_export($surface, true)." » ne doit pas porter le bandeau Uber.");
        }
    }

    /**
     * @dataProvider variantesEcrites
     */
    public function test_les_variantes_d_ecriture_sont_reconnues(string $surface): void
    {
        // L'ingesteur écrit 'uber_eats' aujourd'hui. Si une autre voie d'entrée
        // écrit 'uber' ou 'ubereats' demain, le bandeau doit tenir quand même.
        $t = $this->lisible($this->ticket(['source_surface' => $surface]));
        $this->assertStringContainsString('UBER EATS', $t, "Variante « {$surface} » non reconnue.");
    }

    public static function variantesEcrites(): array
    {
        return [['uber_eats'], ['uber'], ['ubereats'], ['UBER_EATS'], ['  Uber_Eats  ']];
    }

    public function test_l_ingesteur_ecrit_EXACTEMENT_la_valeur_que_le_ticket_attend(): void
    {
        // LE test qui lie les deux moitiés. Sans lui, renommer d'un côté fait
        // disparaître le bandeau de l'autre, en silence.
        $source = file_get_contents(app_path('Services/Uber/UberOrderIngestor.php'));
        $this->assertMatchesRegularExpression(
            "/'source_surface'\s*=>\s*'uber_eats'/",
            (string) $source,
            "L'ingesteur Uber doit écrire 'uber_eats' — la valeur que le ticket cuisine reconnaît."
        );

        // Et la valeur écrite doit effectivement produire le bandeau.
        $this->assertStringContainsString('UBER EATS', $this->lisible($this->ticket(['source_surface' => 'uber_eats'])));
    }

    public function test_l_impression_automatique_se_declenche_sur_le_STATUT_donc_couvre_uber(): void
    {
        // L'ancien déclencheur filtrait par surface et oubliait la caisse. La
        // règle retenue — « dès que ça entre en cuisine » — couvre Uber sans
        // qu'on ait à la nommer. Ce test protège ce choix : si quelqu'un
        // rebranche l'impression sur une liste de surfaces, Uber peut être
        // oublié, et personne ne s'en apercevra avant un livreur qui attend.
        $listener = file_get_contents(app_path('Listeners/AutoPrintKitchenTicketOnKitchenEntry.php'));
        $this->assertStringContainsString('OrderStatusChanged', (string) $listener,
            "L'impression cuisine doit rester branchée sur le STATUT, pas sur une liste de surfaces.");

        $enregistre = file_get_contents(app_path('Providers/EventServiceProvider.php'));
        $this->assertStringContainsString('AutoPrintKitchenTicketOnKitchenEntry', (string) $enregistre,
            "Le déclencheur d'impression cuisine doit être enregistré.");
    }

    public function test_une_commande_uber_est_visible_en_caisse(): void
    {
        // « l'ajouter à la caisse » : la commande doit exister comme une autre,
        // pas dans un silo à part. On vérifie qu'elle est bien une Order
        // ordinaire, donc listée par les mêmes requêtes que le reste.
        $branch = Branch::factory()->create();
        $o = Order::factory()->create([
            'branch_id' => $branch->id,
            'source_surface' => 'uber_eats',
            'source' => \App\Enums\Source::WEB,
            'status' => OrderStatus::ACCEPT,
        ]);

        $vues = Order::withoutGlobalScopes()->where('branch_id', $branch->id)->pluck('id');
        $this->assertTrue($vues->contains($o->id),
            "Une commande Uber doit apparaître dans la liste des commandes de la branche, comme n'importe quelle autre.");
        $this->assertSame('uber_eats', $o->fresh()->source_surface);
    }
}
