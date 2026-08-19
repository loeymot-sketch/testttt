<?php

namespace Tests\Feature\Uber;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Le parcours COMPLET : photo du ticket → lecture → aperçu →
 * validation → commande réelle en cuisine.
 *
 * Ce que le test prouve, dans l'ordre où l'owner l'a demandé :
 *   · plusieurs photos composent UNE commande ;
 *   · rien ne part en cuisine avant validation humaine ;
 *   · la commande créée porte le canal Uber ET le nom du client ;
 *   · la composition est SYMBOLISÉE comme une commande maison (viande, sauce, crudités),
 *     les suppléments restant écrits en toutes lettres ;
 *   · les frites du menu comptent au bandeau de cuisson ;
 *   · deux envois de la même photo ne font pas deux commandes.
 *
 * Aucun réseau : le lecteur par défaut est la doublure locale, qui lit un fichier d'exemple.
 */
class UberPhotoCaptureFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Storage::fake('local');

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $categorie = ItemCategory::create([
            'name' => 'Sandwichs',
            'slug' => 'sandwichs-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);

        foreach (['Tacos M', 'Cayenne', 'Galette Cayenne', 'Cheese Burger', 'Grande Frites'] as $nom) {
            Item::forceCreate([
                'name' => $nom,
                'slug' => Str::slug($nom).'-'.Str::random(6),
                'item_category_id' => $categorie->id,
                'item_type' => 1,
                'price' => 8.5,
                'status' => Status::ACTIVE,
            ]);
        }
    }

    private function actingCaisse(): User
    {
        $user = User::factory()->create(['branch_id' => 1]);
        $user->givePermissionTo(['pos-orders']);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /** Le contenu d'une « photo » est en réalité le ticket d'exemple : la doublure sait le lire. */
    private function photo(string $nom = 'ticket-1.json', ?array $ticket = null): UploadedFile
    {
        $json = $ticket !== null
            ? json_encode($ticket, JSON_UNESCAPED_UNICODE)
            : (string) file_get_contents(base_path('tests/fixtures/uber/ticket-exemple.json'));

        return UploadedFile::fake()->createWithContent($nom, $json);
    }

    /** @test */
    public function la_lecture_rend_un_apercu_cuisine_et_ne_cree_AUCUNE_commande(): void
    {
        $this->actingCaisse();

        $res = $this->postJson('/api/admin/uber/photo/scan', [
            'photos' => [$this->photo('p1.json'), $this->photo('p2.json')],
        ]);

        $res->assertOk()
            ->assertJsonPath('status', 'extracted')
            ->assertJsonPath('client', 'Karim B.')
            ->assertJsonPath('photos', 2)
            ->assertJsonPath('order_id', null);

        // L'aperçu est SYMBOLIQUE : c'est ce que le cuisinier lira, pas le texte brut du ticket.
        $lignes = $res->json('apercu.lignes');
        $this->assertSame('G | TAC | P | ST | ALG', $lignes[0]['symbolique']);
        $this->assertSame('MENU', $lignes[0]['menu']);
        // Le supplément payant reste EN TOUTES LETTRES (règle owner explicite).
        $this->assertSame(['+ Supplément Cheddar'], $lignes[0]['supplements']);
        $this->assertSame(['1 Coca-Cola 33cl'], $lignes[0]['boissons']);
        // La note du client survit, même écrite en capitales.
        $this->assertStringContainsString('SANS OIGNONS SVP', $lignes[0]['note']);

        // Bandeau de cuisson : 1 poulet + 2×(2 hachées) + 1 frite de menu + 2 grandes frites.
        // [OWNER 2026-08-19] Le poulet se compte en PIÈCES de 100 g (2 par portion) : la même
        // portion de poulet s'écrit « 2P » au lieu de « 1P ». L'aperçu Uber doit montrer
        // EXACTEMENT ce que la cuisine verra — il suit donc le bandeau, sans règle à lui.
        $this->assertSame('4K 2P 3F', $res->json('apercu.cuisson'));

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count(), 'Rien ne doit partir en cuisine avant validation.');
    }

    /**
     * [RETRAIT 2026-08-12] Un REFUS ne doit jamais devenir un AJOUT.
     *
     * Nos canaux maison sont additifs : on ne coche pas « oignons », donc il n'y en a pas. Le
     * ticket Uber, lui, s'écrit en négatif — « Sans oignons ». La table des crudités cherchait
     * « oignon » dans le libellé et le rangeait en garniture : le ticket cuisine annonçait alors
     * des oignons à quelqu'un qui venait EXPRESSÉMENT de les refuser. Mesuré en production sur
     * une vraie photo avant correction : `CHEESE BURGER | O`.
     *
     * @test
     */
    public function un_refus_ne_devient_jamais_un_ajout(): void
    {
        $this->actingCaisse();

        $photo = $this->photo('retrait.json', [
            'customer_name' => 'Lucas P.',
            'display_id' => '#7B3C1',
            'order_type' => 'delivery',
            'total' => 12.0,
            'items' => [[
                'title' => 'Cayenne',
                'quantity' => 1,
                'options' => ['Sans oignons'],
                'note' => '',
            ]],
        ]);

        $ligne = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$photo]])
            ->assertOk()
            ->json('apercu.lignes.0');

        // Aucun groupe de crudités ne doit porter le O des oignons.
        $this->assertDoesNotMatchRegularExpression(
            '/\|\s*[STO]*O[STO]*\s*(\||$)/u',
            $ligne['symbolique'],
            'Le refus « Sans oignons » a été replié en garniture : la cuisine en mettrait. Symbolique lue : '.$ligne['symbolique']
        );

        // …et le refus reste LISIBLE : l'effacer serait aussi grave que l'inverser.
        $this->assertStringContainsString(
            'Sans oignons',
            $ligne['note'].' '.implode(' ', $ligne['supplements']),
            'Le refus a disparu de la vue du cuisinier.'
        );
    }

    /**
     * [CARTE UBER 2026-08-12] La carte Uber ne nomme pas les produits comme la nôtre.
     *
     * Mesuré sur une VRAIE commande (E63F5, Olivier H.) : Uber vend « Menu sandwich Cayenne »,
     * notre catalogue s'appelle « Cayenne ». Le résolveur ne testait que le titre ENTIER, donc
     * 2 lignes sur 3 tombaient sur l'article bouche-trou « Article Uber (non mappé) » — le ticket
     * cuisine imprimait « ART », le bandeau de cuisson ne comptait AUCUNE frite pour deux menus,
     * et aucun stock n'était décompté.
     *
     * Le piège à ne pas créer en corrigeant : « Menu galette Cayenne » ne doit PAS devenir
     * « Cayenne ». On essaie donc le nom le plus LONG d'abord, mot à mot.
     *
     * @test
     */
    public function la_carte_uber_prefixe_ses_noms_et_le_plus_long_gagne(): void
    {
        $this->actingCaisse();

        $photo = $this->photo('carte-uber.json', [
            'customer_name' => 'Olivier H.',
            'display_id' => 'E63F5',
            'order_type' => 'delivery',
            'total' => 25.8,
            'items' => [
                ['title' => 'Menu sandwich Cayenne', 'quantity' => 1, 'options' => ['1x Barbecue'], 'note' => ''],
                ['title' => 'Menu galette Cayenne', 'quantity' => 1, 'options' => [], 'note' => ''],
                ['title' => 'Cheese Burger', 'quantity' => 2, 'options' => [], 'note' => ''],
            ],
        ]);

        $res = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$photo]])->assertOk();

        $this->assertSame(0, $res->json('articles_non_reconnus'), 'Des produits de la carte réelle sont restés « non mappés ».');

        $lignes = $res->json('apercu.lignes');

        // « Menu sandwich Cayenne » → le sandwich Cayenne, et c'est bien un MENU (donc une frite).
        $this->assertStringNotContainsString('ART', $lignes[0]['symbolique']);
        $this->assertStringContainsString('CAY', $lignes[0]['symbolique']);
        $this->assertNotSame('', $lignes[0]['menu'], 'Un « Menu … » doit porter la formule, sinon la friteuse ne voit rien.');

        // Le plus LONG d'abord : « Menu galette Cayenne » est la GALETTE, pas le sandwich.
        $this->assertSame('Galette Cayenne', $lignes[1]['titre'], 'Servir un sandwich à qui a commandé une galette serait une erreur de plus.');

        // Le bandeau de cuisson compte enfin les frites des deux menus.
        $this->assertStringContainsString('F', $res->json('apercu.cuisson'), 'Aucune frite comptée pour deux menus.');
    }

    /**
     * [RÉIMPRESSION 2026-08-12 · owner « pour réimprimer »] Le papier se perd — il tombe, il
     * bourre, il part avec l'emballage. Sans ce bouton la seule issue était de rephotographier le
     * ticket Uber, ce qui créait une SECONDE commande donc un second plat.
     *
     * @test
     */
    public function reimprimer_repose_la_demande_que_le_pont_cuisine_viendra_chercher(): void
    {
        $this->actingCaisse();

        $capture = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]])->json('id');
        $orderId = $this->postJson("/api/admin/uber/photo/{$capture}/confirm")->json('order_id');

        $this->postJson("/api/admin/uber/photo/{$capture}/reprint")
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $claim = \Illuminate\Support\Facades\DB::table('kitchen_ticket_claims')
            ->where('order_id', $orderId)->where('destination', 'kitchen')->first();

        $this->assertNotNull($claim, 'Aucune demande posée : le pont ne viendra rien chercher.');
        $this->assertNotNull($claim->reprint_requested_at);
        $this->assertNull($claim->printed_at, 'Une demande de réimpression doit rouvrir la réclamation.');
    }

    /** @test Rien n'est parti en cuisine : on refuse clairement plutôt que de promettre un papier. */
    public function reimprimer_une_commande_jamais_envoyee_est_refuse(): void
    {
        $this->actingCaisse();

        $capture = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]])->json('id');

        $this->postJson("/api/admin/uber/photo/{$capture}/reprint")
            ->assertStatus(409)
            ->assertJsonPath('status', 'jamais_envoyee');
    }

    /**
     * [TICKET COUPÉ 2026-08-12 · owner « si trop longue commande en 2 photos je ferais comment »]
     *
     * TOUT ticket Uber se termine par un montant payé. N'en avoir lu aucun est le signe le plus
     * net que la photo s'est arrêtée avant la fin du papier : l'écran doit le dire et proposer de
     * photographier la suite, au lieu de laisser envoyer une commande amputée.
     *
     * @test
     */
    public function un_ticket_sans_montant_total_est_signale_comme_coupe(): void
    {
        $this->actingCaisse();

        $coupe = $this->photo('coupe.json', [
            'customer_name' => 'Olivier H.', 'display_id' => 'E63F5', 'order_type' => 'delivery',
            'items' => [['title' => 'Cayenne', 'quantity' => 1, 'options' => [], 'note' => '']],
            // Pas de `total` : la photo s'est arrêtée avant le bas du ticket.
        ]);

        $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$coupe]])
            ->assertOk()
            ->assertJsonPath('ticket_coupe', true);

        // …et un ticket COMPLET ne doit jamais déclencher l'alerte, sinon plus personne ne la lit.
        $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo('entier.json')]])
            ->assertOk()
            ->assertJsonPath('ticket_coupe', false);
    }

    /** @test */
    public function la_validation_cree_la_commande_avec_le_canal_uber_et_le_nom_du_client(): void
    {
        $this->actingCaisse();

        $capture = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]])->json('id');

        $res = $this->postJson("/api/admin/uber/photo/{$capture}/confirm");
        $res->assertOk()->assertJsonPath('status', 'ok');

        $order = Order::query()->withoutGlobalScopes()->findOrFail($res->json('order_id'));

        $this->assertSame('uber_eats', $order->source_surface, 'Sans cette surface, ni la vignette UBER du KDS ni la bannière du ticket ne sortent.');
        $this->assertSame('Karim B.', $order->pos_customer_name);
        $this->assertSame(\App\Enums\OrderStatus::ACCEPT, (int) $order->status, 'Une commande Uber est déjà payée : elle entre directement en cuisine.');
        $this->assertSame(\App\Enums\PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertSame(\App\Enums\OrderType::DELIVERY, (int) $order->order_type);
        $this->assertSame('UF7A2', $order->queue_number);
        $this->assertCount(3, $order->orderItems()->withoutGlobalScopes()->get());
    }

    /** @test */
    public function la_composition_scellee_est_celle_que_la_cuisine_sait_lire(): void
    {
        $this->actingCaisse();

        $capture = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]])->json('id');
        $orderId = $this->postJson("/api/admin/uber/photo/{$capture}/confirm")->json('order_id');

        $ligne = Order::query()->withoutGlobalScopes()->findOrFail($orderId)
            ->orderItems()->withoutGlobalScopes()->get()->first();
        $snap = $ligne->composition_snapshot;

        // Viande et sauce dans `lines` → ligne symbolique ; crudités GRATUITES dans `extras` →
        // repliées en « ST » ; supplément PAYANT dans `extras` → « + Cheddar ».
        $this->assertSame('Viande 1', $snap['lines'][0]['attribute_name']);
        $this->assertSame('Poulet mariné', $snap['lines'][0]['variation_name']);
        $this->assertSame('Sauce 1', $snap['lines'][1]['attribute_name']);
        $this->assertSame(0.0, (float) $snap['extras'][0]['unit_price'], 'Une crudité doit être gratuite, sinon elle sort en supplément au lieu de se replier dans « STO ».');
        $this->assertGreaterThan(0, (float) $snap['extras'][2]['unit_price']);
        $this->assertSame('menu_full', $snap['addons'][0]['role']);
        $this->assertSame('drink', $snap['addons'][1]['role']);
    }

    /** @test */
    public function la_meme_photo_envoyee_deux_fois_ne_fait_pas_deux_commandes(): void
    {
        $this->actingCaisse();

        $premier = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]]);
        $premier->assertOk()->assertJsonPath('deja_lue', false);

        $second = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]]);
        $second->assertOk()->assertJsonPath('deja_lue', true);
        $this->assertSame($premier->json('id'), $second->json('id'));

        $orderId = $this->postJson("/api/admin/uber/photo/{$premier->json('id')}/confirm")->json('order_id');
        // Double appui sur « envoyer » : la même commande revient, aucune seconde n'est créée.
        $rejeu = $this->postJson("/api/admin/uber/photo/{$premier->json('id')}/confirm");
        $rejeu->assertOk()->assertJsonPath('status', 'already_confirmed')->assertJsonPath('order_id', $orderId);

        $this->assertSame(1, Order::query()->withoutGlobalScopes()->count());
    }

    /** @test */
    public function une_correction_humaine_remplace_la_lecture_automatique(): void
    {
        $this->actingCaisse();

        $capture = $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]])->json('id');

        $orderId = $this->postJson("/api/admin/uber/photo/{$capture}/confirm", [
            'customer_name' => 'Sofia',
            'items' => [[
                'title' => 'Cayenne',
                'quantity' => 1,
                'options' => ['Viande : Viande Hachée', 'Sauce : Samouraï'],
                'note' => 'sans sauce dans le sac',
            ]],
        ])->assertOk()->json('order_id');

        $order = Order::query()->withoutGlobalScopes()->findOrFail($orderId);
        $this->assertSame('Sofia', $order->pos_customer_name);
        $this->assertCount(1, $order->orderItems()->withoutGlobalScopes()->get(), 'La correction humaine fait foi, pas la lecture automatique.');
    }

    /** @test */
    public function un_ticket_sans_aucune_ligne_lisible_est_refuse_plutot_qu_envoye_vide(): void
    {
        $this->actingCaisse();

        $capture = $this->postJson('/api/admin/uber/photo/scan', [
            'photos' => [$this->photo('vide.json', ['customer_name' => 'X', 'items' => []])],
        ]);
        $capture->assertOk()->assertJsonPath('status', 'failed');

        $this->postJson("/api/admin/uber/photo/{$capture->json('id')}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('status', 'empty_ticket');

        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());
    }

    /**
     * @test
     *
     * Un ticket dont le numéro n'est pas lisible (photo floue, coin coupé) ne doit pas produire
     * une carte de cuisine MUETTE : sans numéro, le cuisinier ne peut ni annoncer la commande ni
     * l'apparier au sac. On en dérive un de la capture.
     */
    public function un_ticket_sans_numero_lisible_recoit_quand_meme_un_numero_d_appel(): void
    {
        $this->actingCaisse();

        $res = $this->postJson('/api/admin/uber/photo/scan', [
            'photos' => [$this->photo('sansnum.json', [
                'customer_name' => 'Yanis',
                'display_id' => '',
                'items' => [['title' => 'Cayenne', 'quantity' => 1, 'options' => [], 'note' => '']],
            ])],
        ])->assertOk();

        $orderId = $this->postJson("/api/admin/uber/photo/{$res->json('id')}/confirm")->assertOk()->json('order_id');
        $order = Order::query()->withoutGlobalScopes()->findOrFail($orderId);

        $this->assertNotSame('', (string) $order->queue_number);
        $this->assertSame('UP'.$res->json('id'), $order->queue_number);
    }

    /** @test */
    public function un_produit_absent_du_catalogue_ne_fait_PAS_perdre_la_commande(): void
    {
        $this->actingCaisse();

        $res = $this->postJson('/api/admin/uber/photo/scan', [
            'photos' => [$this->photo('inconnu.json', [
                'customer_name' => 'Léa',
                'display_id' => '#8812',
                'items' => [['title' => 'Produit Qui N Existe Pas', 'quantity' => 1, 'options' => [], 'note' => '']],
            ])],
        ]);

        $res->assertOk()->assertJsonPath('articles_non_reconnus', 1);
        $this->assertTrue($res->json('apercu.lignes.0.non_mappe'), 'L\'écran doit SIGNALER l\'article non reconnu, pas le masquer.');

        $orderId = $this->postJson("/api/admin/uber/photo/{$res->json('id')}/confirm")->assertOk()->json('order_id');
        $ligne = Order::query()->withoutGlobalScopes()->findOrFail($orderId)
            ->orderItems()->withoutGlobalScopes()->get()->first();

        // La commande existe malgré tout, et le titre réel reste lisible en cuisine.
        $this->assertStringContainsString('Produit Qui N Existe Pas', (string) $ligne->instruction);
    }

    /**
     * @test
     *
     * LA GARDE LA PLUS IMPORTANTE DE CE CANAL. Sans lecteur configuré, la doublure locale ne doit
     * JAMAIS rendre le ticket d'exemple face à une vraie photo : le personnel verrait une commande
     * plausible — un client, des produits, un total — et pourrait l'envoyer en cuisine. Une
     * commande INVENTÉE partirait en préparation sans que personne ne puisse s'en apercevoir.
     */
    public function hors_test_une_vraie_photo_sans_lecteur_ne_produit_JAMAIS_le_ticket_d_exemple(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        $photo = tempnam(sys_get_temp_dir(), 'uber').'.jpg';
        file_put_contents($photo, "\xFF\xD8\xFF\xE0 fausse photo");

        $lecture = (new \App\Services\Uber\Vision\MockUberTicketVisionService)->readTicket([$photo]);

        @unlink($photo);

        $this->assertSame([], $lecture['items'], 'La doublure a INVENTÉ une commande à partir d\'une photo qu\'elle ne sait pas lire.');
        $this->assertSame('', $lecture['customer_name']);
    }

    /** @test */
    public function sans_la_permission_caisse_l_ecran_est_ferme(): void
    {
        $user = User::factory()->create(['branch_id' => 1]);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/admin/uber/photo/scan', ['photos' => [$this->photo()]])->assertStatus(403);
    }
}
