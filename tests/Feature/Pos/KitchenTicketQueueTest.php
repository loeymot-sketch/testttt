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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [WEB-PAYEE-MUETTE 2026-08-10 · P0 owner] La file des tickets cuisine réclamée par le PC caisse.
 *
 * CE QUE CETTE FILE RÉPARE
 * ------------------------
 * L'impression serveur→imprimante n'a JAMAIS fonctionné en production : le serveur est chez
 * l'hébergeur, l'imprimante au bout du réseau du restaurant. Constat du 2026-08-10 : table
 * `printers` vide, `printOnce()` sort en `no_printer`, zéro ligne de journal depuis l'origine.
 * La commande #440 (31,40 € encaissés) n'a produit aucun papier.
 *
 * LES DEUX FAÇONS DE SE TROMPER ICI, ET LEUR COÛT
 * ----------------------------------------------
 *  - Réclamer trop : le premier sondage vide l'historique entier sur le rouleau (toutes les
 *    commandes passées ont `kitchen_ticket_printed_at` à NULL). D'où la fenêtre bornée.
 *  - Réclamer puis perdre : une commande marquée « imprimée » dont aucun papier n'est sorti
 *    est le pire des deux mondes — la cuisine ne l'a pas et plus rien ne la lui donnera.
 *    D'où l'accusé de réception qui remet en file.
 */
class KitchenTicketQueueTest extends TestCase
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

    private function commandeWeb(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'                 => $this->branch->id,
            'source_surface'            => 'web',
            'order_type'                => OrderType::TAKEAWAY,
            'status'                    => OrderStatus::PREPARING,
            'payment_status'            => PaymentStatus::PAID,
            'payment_method'            => PaymentGateway::CARD,
            'kitchen_ticket_printed_at' => null,
        ], $overrides));
    }

    private function reclamer(?string $destination = null): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending',
                $destination === null ? [] : ['destination' => $destination]);
        $res->assertOk();

        return collect($res->json('orders'))->pluck('id')->all();
    }

    private function accuser(int $orderId, bool $success, ?string $error = null, ?string $destination = null): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/pos/kitchen-tickets/{$orderId}/ack", array_filter([
                'success'     => $success,
                'error'       => $error,
                'destination' => $destination,
            ], fn ($v) => $v !== null))->assertOk();
    }

    /** @test */
    public function une_commande_web_payee_est_reclamee_pour_impression(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer());
    }

    /** @test La garde « une seule fois » : deux onglets caisse ne sortent pas le même ticket. */
    public function une_commande_deja_reclamee_ne_ressort_pas_au_sondage_suivant(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer(), 'premier sondage : le ticket doit sortir');
        $this->assertNotContains($web->id, $this->reclamer(), 'second sondage : le ticket ne doit PAS sortir deux fois');

        $this->assertDatabaseHas('kitchen_ticket_claims', [
            'order_id' => $web->id, 'destination' => 'counter',
        ]);
    }

    /** @test Le filet : si le papier n'est pas sorti, la commande RETOURNE en file. */
    public function un_echec_d_impression_remet_la_commande_en_file(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer();

        $this->accuser($web->id, false, 'Pont d\'impression indisponible');

        $this->assertDatabaseMissing('kitchen_ticket_claims', [
            'order_id' => $web->id, 'destination' => 'counter',
        ]);
        $this->assertContains($web->id, $this->reclamer(), 'la commande doit être re-proposée après un échec');
    }

    /** @test */
    public function un_succes_laisse_la_commande_hors_de_la_file(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer();

        $this->accuser($web->id, true);

        $this->assertNotNull(
            DB::table('kitchen_ticket_claims')
                ->where('order_id', $web->id)->where('destination', 'counter')->value('printed_at'),
            'un succès doit horodater la sortie papier, pas seulement la réclamation'
        );
        $this->assertNotContains($web->id, $this->reclamer());
    }

    /**
     * @test
     * LE test de cette vague — l'owner veut un papier à la caisse ET un en cuisine.
     *
     * Avec l'ancienne garde (une seule colonne « déjà imprimé »), le premier poste à réclamer
     * privait l'autre : chacun n'aurait sorti qu'un ticket sur deux, en alternance, et personne
     * n'aurait compris pourquoi. C'est cette propriété-là qu'il faut verrouiller.
     */
    public function les_deux_postes_reclament_chacun_leur_papier(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer('counter'), 'la caisse doit avoir son papier');
        $this->assertContains($web->id, $this->reclamer('kitchen'), 'la cuisine doit avoir le sien — la caisse ne le lui vole pas');

        // …et aucun des deux ne ressort une seconde fois.
        $this->assertNotContains($web->id, $this->reclamer('counter'));
        $this->assertNotContains($web->id, $this->reclamer('kitchen'));

        $this->assertSame(2, DB::table('kitchen_ticket_claims')->where('order_id', $web->id)->count());
    }

    /** @test Un échec en cuisine ne doit pas faire ressortir le papier déjà sorti à la caisse. */
    public function un_echec_sur_un_poste_ne_touche_pas_le_papier_de_l_autre(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer('counter');
        $this->reclamer('kitchen');
        $this->accuser($web->id, true, null, 'counter');

        $this->accuser($web->id, false, 'papier épuisé', 'kitchen');

        $this->assertNotContains($web->id, $this->reclamer('counter'), 'le papier caisse est sorti, il ne doit pas ressortir');
        $this->assertContains($web->id, $this->reclamer('kitchen'), 'le papier cuisine a échoué, il doit être re-proposé');
    }

    /**
     * @test
     * LE TROU TROUVÉ EN ABUSANT DE MA PROPRE FILE (2026-08-12).
     *
     * Un poste réclame, puis meurt avant d'accuser : onglet fermé, PC redémarré, `ack` qui part
     * dans un réseau coupé. La ligne de réclamation reste, `printed_at` reste NULL — et la file
     * exclut TOUTE commande ayant une ligne pour cette destination, sans regarder `printed_at`.
     * Le ticket est perdu POUR TOUJOURS : aucune tâche planifiée, aucune commande, aucune
     * expiration ne le reprend. En cuisine, cela veut dire un plat oublié.
     *
     * Reproduit en une seule requête sur la base locale pendant l'audit : cinq tickets détruits.
     *
     * Le patron dont je m'étais inspiré — le ticket promo — porte précisément la garde que
     * j'avais laissée tomber (`PromoFlyer::CLAIM_TTL_SECONDS`, 90 s) : « pris par un écran qui
     * n'a pas confirmé » y est explicitement réclamable. J'en avais copié la forme, pas la
     * sûreté.
     *
     * La doctrine du dépôt tranche déjà l'arbitrage, dans KitchenTicketAutoPrinter :
     * « Mieux vaut un risque de doublon qu'un ticket perdu en cuisine. »
     */
    public function une_reclamation_jamais_accusee_est_reproposee_apres_expiration(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer(), 'premier sondage : le ticket sort');
        $this->assertNotContains($web->id, $this->reclamer(), 'tant que le poste est vivant, pas de doublon');

        // Le poste meurt ici : aucun accusé ne viendra jamais.
        $this->travel(config('kds.bridge_claim_ttl_seconds', 90) + 30)->seconds();

        $this->assertContains(
            $web->id,
            $this->reclamer(),
            'un ticket réclamé mais jamais accusé DOIT revenir en file — sinon il est perdu pour toujours'
        );
    }

    /** @test La reprise ne doit PAS ressusciter un ticket dont le papier est réellement sorti. */
    public function un_ticket_reellement_imprime_ne_revient_jamais_meme_tres_tard(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer();
        $this->accuser($web->id, true);

        $this->travel(config('kds.bridge_claim_ttl_seconds', 90) * 10)->seconds();

        $this->assertNotContains(
            $web->id,
            $this->reclamer(),
            'le papier est sorti : l\'expiration du verrou ne doit pas le refaire sortir'
        );
    }

    /** @test Un poste vivant qui met du temps à imprimer ne doit pas se faire voler son ticket. */
    public function une_reclamation_recente_reste_protegee(): void
    {
        $web = $this->commandeWeb();
        $this->reclamer();

        // Bien en deçà de l'expiration : le poste est encore en train d'imprimer.
        $this->travel(10)->seconds();

        $this->assertNotContains($web->id, $this->reclamer(), 'pas de vol de ticket sous le nez d\'un poste actif');
    }

    /** @test Un poste qui n'annonce pas sa destination est traité comme la caisse (ancien paquet en cache). */
    public function une_reclamation_sans_destination_vaut_pour_la_caisse(): void
    {
        $web = $this->commandeWeb();

        $this->assertContains($web->id, $this->reclamer(null));

        $this->assertDatabaseHas('kitchen_ticket_claims', [
            'order_id' => $web->id, 'destination' => 'counter',
        ]);
    }

    /** @test Une destination inventée est refusée — pas de file fantôme qui avale des tickets. */
    public function une_destination_inconnue_est_refusee(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending', ['destination' => 'bureau'])
            ->assertStatus(422);
    }

    /** @test Le garde-fou anti-rouleau : sans lui, la première mise en service vide l'historique. */
    public function une_commande_plus_vieille_que_la_fenetre_n_est_jamais_reclamee(): void
    {
        $vieille = $this->commandeWeb([
            'created_at' => now()->subMinutes((int) config('kds.bridge_print_window_minutes', 30) + 5),
        ]);

        $this->assertNotContains($vieille->id, $this->reclamer());
    }

    /** @test Caisse et téléphone impriment déjà leur comptoir au clic — les inclure doublerait ce papier-là. */
    public function les_ventes_caisse_et_telephone_ne_sont_pas_reclamees_au_comptoir(): void
    {
        $caisse = $this->commandeWeb(['source_surface' => 'pos']);
        $tel    = $this->commandeWeb(['source_surface' => 'phone']);

        $ids = $this->reclamer('counter');

        $this->assertNotContains($caisse->id, $ids);
        $this->assertNotContains($tel->id, $ids);
    }

    /**
     * @test
     * [OWNER 2026-08-13 « je veux tout imprime direct »] Le filet qui manquait : avant ce jour,
     * une vente caisse ou téléphone n'atteignait JAMAIS le poste cuisine — seul le comptoir en
     * avait un, et seulement si le caissier cliquait. Sans droit sur cette destination, un oubli
     * de clic = un plat jamais préparé.
     */
    public function les_ventes_caisse_et_telephone_sont_reclamees_en_cuisine(): void
    {
        $caisse = $this->commandeWeb(['source_surface' => 'pos']);
        $tel    = $this->commandeWeb(['source_surface' => 'phone']);

        $ids = $this->reclamer('kitchen');

        $this->assertContains($caisse->id, $ids);
        $this->assertContains($tel->id, $ids);
    }

    /** @test */
    public function une_commande_dont_le_paiement_est_en_vol_ne_produit_pas_de_papier(): void
    {
        $enVol = $this->commandeWeb([
            'status'         => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
        ]);

        $this->assertNotContains($enVol->id, $this->reclamer());
    }

    /** @test La borne, elle, doit continuer d'imprimer — c'est le comportement historique. */
    public function une_commande_borne_relachee_est_reclamee(): void
    {
        $borne = $this->commandeWeb([
            'source_surface' => 'kiosk',
            'order_type'     => OrderType::KIOSK,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'status'         => OrderStatus::ACCEPT,
        ]);

        $this->assertContains($borne->id, $this->reclamer());
    }

    /** @test */
    public function isolation_branche_un_poste_ne_reclame_que_ses_commandes(): void
    {
        $autre = $this->commandeWeb(['branch_id' => Branch::factory()->create()->id]);

        $this->assertNotContains($autre->id, $this->reclamer());
    }

    /** @test Un compte sans droit caisse ni cuisine ne doit pas pouvoir vider la file. */
    public function un_compte_sans_droit_est_refuse(): void
    {
        $quidam = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($quidam, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending')
            ->assertForbidden();
    }

    /**
     * [RÉIMPRESSION 2026-08-12] LE test qui justifie la colonne.
     *
     * On réimprime justement un ticket perdu, bourré ou taché — souvent bien après le coup de
     * feu. Une commande VIEILLE et déjà servie est hors de la fenêtre automatique de 30 minutes :
     * effacer sa réclamation n'aurait rien produit, et l'écran aurait promis un papier qui ne
     * serait jamais sorti. La demande explicite, elle, doit passer.
     *
     * @test
     */
    public function une_reimpression_demandee_ressort_meme_une_commande_hors_fenetre(): void
    {
        $vieille = $this->commandeWeb(['created_at' => now()->subHours(3), 'status' => OrderStatus::DELIVERED]);

        DB::table('kitchen_ticket_claims')->insert([
            'order_id' => $vieille->id, 'destination' => 'kitchen',
            'printed_at' => now()->subHours(3), 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);

        // Sans demande : la file automatique l'ignore, et c'est voulu.
        $this->assertNotContains($vieille->id, $this->reclamer('kitchen'));

        DB::table('kitchen_ticket_claims')
            ->where('order_id', $vieille->id)->where('destination', 'kitchen')
            ->update(['reprint_requested_at' => now()]);

        $this->assertContains($vieille->id, $this->reclamer('kitchen'), 'La réimpression demandée par un humain doit passer la fenêtre.');

        // La demande est CONSOMMÉE : un second sondage ne doit pas ressortir un troisième papier.
        $this->assertNull(
            DB::table('kitchen_ticket_claims')->where('order_id', $vieille->id)->where('destination', 'kitchen')->value('reprint_requested_at')
        );
        $this->assertNotContains($vieille->id, $this->reclamer('kitchen'), 'Une demande servie ne doit pas boucler.');
    }

    /** @test Une réimpression demandée pour la CUISINE ne doit pas sortir à la caisse. */
    public function une_reimpression_ne_sort_qu_a_la_destination_demandee(): void
    {
        $commande = $this->commandeWeb(['created_at' => now()->subHours(3), 'status' => OrderStatus::DELIVERED]);

        DB::table('kitchen_ticket_claims')->insert([
            'order_id' => $commande->id, 'destination' => 'kitchen',
            'printed_at' => now()->subHours(3), 'reprint_requested_at' => now(),
            'created_at' => now()->subHours(3), 'updated_at' => now(),
        ]);

        $this->assertNotContains($commande->id, $this->reclamer('counter'));
        $this->assertContains($commande->id, $this->reclamer('kitchen'));
    }
}
