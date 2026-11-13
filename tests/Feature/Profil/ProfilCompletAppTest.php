<?php

namespace Tests\Feature\Profil;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [PROFIL APP 2026-11-12] Profil complet de l'application (et du site) — ce que le serveur garantit :
 *   · carnet d'adresses : un client ne voit / modifie / supprime / désigne QUE ses adresses ; une seule par défaut ;
 *     renommer ne rappelle pas le géocodeur ; messages en français ; compte Apple (numéro déclaré) accepté ;
 *   · « Recommander » : composition exacte d'une commande DU CLIENT, prix d'aujourd'hui calculés par le serveur,
 *     produits retirés / épuisés signalés et sans composition rejouable, note de commande non recopiée ;
 *   · consentement « offres par e-mail » : effet réel sur la liste des abonnés, retiré à la suppression du compte ;
 *   · prénom / nom modifiables par le client sans renvoyer son contact (compte Apple sans téléphone compris),
 *     changement de contact toujours refusé.
 *
 * @group profil
 */
class ProfilCompletAppTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key', 'delivery.geocoder.enabled' => true]);
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
    }

    // ------------------------------------------------------------------ outils

    private function client(array $attrs = []): User
    {
        $u = User::factory()->create(array_merge([
            'branch_id' => 0,
            'phone'     => '06' . random_int(10000000, 99999999),
            'email'     => 'client' . random_int(1000, 999999) . '@example.test',
            'is_guest'  => Ask::YES,
            'status'    => \App\Enums\Status::ACTIVE,
            'email_verified_at' => now()->timestamp,
        ], $attrs));
        $u->assignRole('Customer');

        return $u;
    }

    private function api(User $u)
    {
        return $this->actingAs($u, 'sanctum')->withHeader('x-api-key', 'test-api-key');
    }

    /** Faux géocodeur (Base Adresse Nationale) : deux adresses connues, deux points distincts. */
    private function fauxGeocodeur(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => function ($requete) {
            $jaures = str_contains(mb_strtolower(urldecode((string) $requete->url())), 'jaures');
            $f = $jaures
                ? ['geometry' => ['coordinates' => [2.96, 50.43]], 'properties' => ['label' => '1 Place Jean Jaurès 62110 Hénin-Beaumont', 'postcode' => '62110', 'city' => 'Hénin-Beaumont', 'score' => 0.95]]
                : ['geometry' => ['coordinates' => [2.9549, 50.4215]], 'properties' => ['label' => '437 Rue Élie Gruyelle 62110 Hénin-Beaumont', 'postcode' => '62110', 'city' => 'Hénin-Beaumont', 'score' => 0.97, 'housenumber' => '437', 'street' => 'Rue Élie Gruyelle']];

            return Http::response(['features' => [$f]]);
        }]);
    }

    private function adresse(User $u, string $label, bool $parDefaut = false): Address
    {
        return Address::create([
            'user_id' => $u->id, 'label' => $label, 'address' => $label . ' — 1 rue de test 62110 Hénin-Beaumont',
            'latitude' => '50.42', 'longitude' => '2.95', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
            'geocode_status' => 'OK', 'is_default' => $parDefaut,
        ]);
    }

    // ------------------------------------------------------------------ carnet d'adresses

    public function test_la_premiere_adresse_devient_l_adresse_par_defaut_et_la_liste_la_met_en_tete(): void
    {
        $this->fauxGeocodeur();
        $u = $this->client();

        $r1 = $this->api($u)->postJson('/api/frontend/address', ['label' => 'Maison', 'address' => '437 rue elie gruyelle henin']);
        $r1->assertSuccessful();
        $this->assertTrue($r1->json('data.is_default'), 'La première adresse du client est son adresse par défaut.');

        $r2 = $this->api($u)->postJson('/api/frontend/address', ['label' => 'Travail', 'address' => '1 place jean jaures henin']);
        $r2->assertSuccessful();
        $this->assertFalse($r2->json('data.is_default'), 'Une deuxième adresse ne vole pas le « par défaut ».');

        $this->api($u)->postJson('/api/frontend/address/' . $r2->json('data.id') . '/default')->assertSuccessful()->assertJsonPath('data.is_default', true);

        $liste = $this->api($u)->getJson('/api/frontend/address')->assertOk()->json('data');
        $this->assertSame('Travail', $liste[0]['label'], 'L\'adresse par défaut vient en tête du carnet.');
        $this->assertSame(1, Address::where('user_id', $u->id)->where('is_default', true)->count(), 'Une seule adresse par défaut à la fois.');
    }

    public function test_un_client_ne_voit_ne_modifie_ne_supprime_ni_ne_designe_que_ses_adresses(): void
    {
        $a = $this->client();
        $b = $this->client();
        $adresseA = $this->adresse($a, 'Maison', true);
        $adresseB = $this->adresse($b, 'Chez B', true);

        $liste = $this->api($a)->getJson('/api/frontend/address')->assertOk()->json('data');
        $this->assertSame([$adresseA->id], array_column($liste, 'id'), 'La liste ne contient QUE les adresses du client.');

        $this->api($a)->getJson('/api/frontend/address/' . $adresseB->id)->assertForbidden();
        $this->api($a)->putJson('/api/frontend/address/' . $adresseB->id, ['label' => 'Volée', 'address' => $adresseB->address])->assertForbidden();
        $this->api($a)->postJson('/api/frontend/address/' . $adresseB->id . '/default')->assertForbidden();
        $this->api($a)->deleteJson('/api/frontend/address/' . $adresseB->id)->assertForbidden();

        $adresseB->refresh();
        $this->assertSame('Chez B', $adresseB->label);
        $this->assertTrue($adresseB->is_default, 'L\'adresse par défaut de B est intacte.');
        $this->assertTrue($adresseA->fresh()->is_default, 'Et celle de A aussi.');
    }

    public function test_renommer_ne_rappelle_pas_le_geocodeur_et_ignore_les_coordonnees_envoyees(): void
    {
        Http::fake(); // tout appel sortant serait enregistré
        $u = $this->client();
        $adr = $this->adresse($u, 'Livraison 3f2a1b', true);

        $this->api($u)->putJson('/api/frontend/address/' . $adr->id, [
            'label' => 'Maison', 'address' => $adr->address, 'latitude' => '48.85', 'longitude' => '2.35',
        ])->assertSuccessful()->assertJsonPath('data.label', 'Maison');

        Http::assertNothingSent();
        $adr->refresh();
        $this->assertSame('Maison', $adr->label);
        $this->assertSame('50.42', $adr->latitude, 'La position facturée reste celle du géocodeur, jamais celle du client.');
    }

    public function test_supprimer_l_adresse_par_defaut_en_designe_une_autre(): void
    {
        $u = $this->client();
        $vieille = $this->adresse($u, 'Travail');
        $defaut = $this->adresse($u, 'Maison', true);

        $this->api($u)->deleteJson('/api/frontend/address/' . $defaut->id)->assertStatus(202);
        $this->assertTrue($vieille->fresh()->is_default, 'L\'adresse restante prend le relais.');
    }

    /**
     * [test-e2e stores P15-001 · 2026-11-13] Deux appareils : l'un supprime « Travail », l'autre la désigne par défaut au même
     * instant. La requête « par défaut » a déjà chargé l'adresse (liaison de route) quand la suppression passe : elle doit
     * répondre « supprimée », sans laisser le carnet sans adresse par défaut ni renvoyer « No query results… ».
     */
    public function test_designer_par_defaut_une_adresse_supprimee_entre_temps(): void
    {
        $u = $this->client();
        $maison = $this->adresse($u, 'Maison', true);
        $travail = $this->adresse($u, 'Travail');
        $chargee = Address::find($travail->id);          // ce que la requête « par défaut » a lu avant le verrou
        $travail->delete();                               // l'autre appareil supprime pendant ce temps

        try {
            app(\App\Services\AddressService::class)->setDefault($chargee);
            $this->fail('Une adresse supprimée ne peut pas devenir l\'adresse par défaut.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // attendu : le contrôleur en fait un 404 « supprimée depuis un autre appareil »
        }
        $this->assertTrue($maison->fresh()->is_default, 'Le carnet garde son adresse par défaut.');
        $this->assertSame(1, Address::where('user_id', $u->id)->where('is_default', true)->count());
    }

    /** [P15-001] Suppression avec un état « par défaut » périmé : c'est l'état relu sous le verrou qui décide de la relève. */
    public function test_supprimer_avec_un_etat_par_defaut_perime_garde_une_adresse_par_defaut(): void
    {
        $u = $this->client();
        $maison = $this->adresse($u, 'Maison', true);
        $travail = $this->adresse($u, 'Travail');
        $bureau = $this->adresse($u, 'Bureau');          // la plus récente : celle qu'une relève À TORT désignerait
        $chargeeAvant = Address::find($maison->id);      // lue « par défaut »…
        // … puis un autre appareil fait de « Travail » l'adresse par défaut.
        app(\App\Services\AddressService::class)->setDefault($travail);

        app(\App\Services\AddressService::class)->destroy($chargeeAvant);
        $this->assertTrue($travail->fresh()->is_default, '« Travail » reste l\'adresse par défaut.');
        $this->assertFalse($bureau->fresh()->is_default, 'Pas de 2e adresse par défaut (« Bureau »).');
        $this->assertSame(1, Address::where('user_id', $u->id)->where('is_default', true)->count());

        // Supprimer une adresse déjà supprimée ailleurs ne lève rien.
        $deja = Address::find($bureau->id);
        $bureau->delete();
        app(\App\Services\AddressService::class)->destroy($deja);
        $this->assertSame(1, Address::where('user_id', $u->id)->count());
    }

    /** [supervision 2026-11-13] Un ancien client (adresses, aucune par défaut) : une nouvelle adresse ne devient pas « par défaut » d'office. */
    public function test_une_nouvelle_adresse_ne_devient_par_defaut_que_si_c_est_la_premiere(): void
    {
        $this->fauxGeocodeur();
        $u = $this->client();
        $this->adresse($u, 'Ancienne');
        $r = $this->api($u)->postJson('/api/frontend/address', ['label' => 'Livraison 123', 'address' => '1 Place Jean Jaurès, 62110 Hénin-Beaumont']);
        $r->assertSuccessful();
        $this->assertFalse((bool) $r->json('data.is_default'), 'Pas choisie par le client : pas par défaut.');
    }

    /** [supervision 2026-11-13] Suppression depuis l'admin/la caisse : la plus récente reprend le « par défaut ». */
    public function test_suppression_admin_d_une_adresse_par_defaut_designe_une_autre(): void
    {
        $u = $this->client();
        $vieille = $this->adresse($u, 'Travail');
        $defaut = $this->adresse($u, 'Maison', true);
        app(\App\Services\UserAddressService::class)->destroy($u, $defaut);
        $this->assertTrue($vieille->fresh()->is_default);
    }

        public function test_messages_du_carnet_en_francais(): void
    {
        $u = $this->client();
        $adr = $this->adresse($u, 'Maison', true);
        $this->adresse($u, 'Travail');

        $r = $this->api($u)->putJson('/api/frontend/address/' . $adr->id, ['label' => 'Travail', 'address' => $adr->address]);
        $r->assertStatus(422);
        $this->assertSame('Vous avez déjà une adresse portant ce nom. Choisissez-en un autre.', $r->json('errors.label.0'));

        $this->api($u)->postJson('/api/frontend/address', ['label' => '', 'address' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.label.0', 'Donnez un nom à cette adresse (par exemple Maison ou Travail).');
    }

    public function test_un_compte_apple_avec_numero_declare_peut_enregistrer_une_adresse(): void
    {
        $this->fauxGeocodeur();
        $u = $this->client(['phone' => 'PENDING_APPLE_abc123', 'contact_phone' => '0612345678']);

        $this->api($u)->postJson('/api/frontend/address', ['label' => 'Maison', 'address' => '437 rue elie gruyelle henin'])
            ->assertSuccessful();

        $sansNumero = $this->client(['phone' => 'PENDING_APPLE_def456', 'contact_phone' => null]);
        $this->api($sansNumero)->postJson('/api/frontend/address', ['label' => 'Maison', 'address' => '437 rue elie gruyelle henin'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }

    // ------------------------------------------------------------------ recommander

    /** @return array{0: Item, 1: ItemVariation, 2: ItemExtra} */
    private function produitCompose(string $nom = 'Tacos test', float $prix = 8.0): array
    {
        $cat = ItemCategory::firstOrCreate(['slug' => 'cat-profil'], ['name' => 'Cat profil', 'status' => 5]);
        $item = Item::forceCreate(['name' => $nom, 'slug' => \Illuminate\Support\Str::slug($nom) . '-' . random_int(1, 99999), 'price' => $prix, 'status' => 5, 'item_category_id' => $cat->id]);
        $attr = ItemAttribute::create(['name' => 'Viande 1', 'status' => 5, 'min_select' => 0, 'max_select' => 1]);
        $var = ItemVariation::create(['item_id' => $item->id, 'item_attribute_id' => $attr->id, 'name' => 'Poulet', 'price' => 1.0, 'status' => 5]);
        $ext = ItemExtra::create(['item_id' => $item->id, 'name' => 'Cheddar', 'price' => 0.5, 'status' => 5]);

        return [$item, $var, $ext];
    }

    private function commande(User $u, array $lignes): Order
    {
        $o = Order::create([
            'order_serial_no' => 'PROFIL-' . random_int(1000, 999999), 'branch_id' => $this->branch->id, 'user_id' => $u->id,
            'status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID, 'subtotal' => 10, 'total' => 10,
            'order_type' => OrderType::TAKEAWAY, 'order_datetime' => now(), 'is_advance_order' => Ask::NO, 'source' => 5,
        ]);
        foreach ($lignes as $l) {
            OrderItem::create(array_merge([
                'order_id' => $o->id, 'branch_id' => $this->branch->id, 'quantity' => 1, 'price' => 1, 'discount' => 0,
                'item_variation_total' => 0, 'item_extra_total' => 0, 'total_price' => 1, 'tax_amount' => 0,
            ], $l));
        }

        return $o;
    }

    public function test_recommander_rend_la_meme_composition_au_prix_d_aujourd_hui(): void
    {
        [$item, $var, $ext] = $this->produitCompose('Tacos test', 8.0);
        $u = $this->client();
        $o = $this->commande($u, [[
            'item_id' => $item->id, 'quantity' => 2, 'price' => 6.0, 'total_price' => 15.0,
            'item_variations' => json_encode([['id' => $var->id, 'quantity' => 1]]),
            'item_extras' => json_encode([['id' => $ext->id, 'quantity' => 1, 'name' => 'Cheddar']]),
            'instruction' => "Sauce frites : Ketchup\n[Note : sans oignon]\n[Note commande : sonnez deux fois]",
            'composition_snapshot' => json_encode(['lines' => [['variation_id' => $var->id, 'variation_name' => 'Poulet', 'attribute_name' => 'Viande 1']], 'extras' => [['extra_id' => $ext->id, 'extra_name' => 'Cheddar', 'quantity' => 1]], 'addons' => []]),
        ]]);

        $r = $this->api($u)->getJson('/api/frontend/order/' . $o->id . '/reorder')->assertOk();
        $ligne = $r->json('data.lines.0');

        $this->assertTrue($ligne['available']);
        $this->assertSame(2, $ligne['quantity'], 'La quantité de l\'ancienne commande est reprise.');
        $this->assertEqualsWithDelta(9.5, $ligne['unit_price'], 0.001, 'Prix d\'AUJOURD\'HUI, calculé par le serveur (8 + 1 + 0,50), pas l\'ancien (6 €).');
        $this->assertSame([['id' => $var->id, 'quantity' => 1]], $ligne['payload']['item_variations']);
        $this->assertSame($ext->id, $ligne['payload']['item_extras'][0]['id']);
        $this->assertStringContainsString('[Note : sans oignon]', $ligne['payload']['instruction'], 'La note de la LIGNE suit le plat.');
        $this->assertStringNotContainsString('Note commande', $ligne['payload']['instruction'], 'La note de l\'ancienne COMMANDE ne suit pas.');
        $this->assertStringContainsString('Poulet', $ligne['summary']);
        $this->assertSame(1, $r->json('data.available_count'));
    }

    public function test_recommander_signale_retire_et_epuise_sans_composition_rejouable(): void
    {
        [$retire] = $this->produitCompose('Burger retiré', 7.0);
        [$epuise] = $this->produitCompose('Wrap épuisé', 6.0);
        $retire->forceFill(['status' => 10])->save();
        ItemBranchAvailability::forceCreate(['item_id' => $epuise->id, 'branch_id' => $this->branch->id, 'is_available' => false, 'daily_reset_at' => now()]);
        $u = $this->client();
        $o = $this->commande($u, [['item_id' => $retire->id], ['item_id' => $epuise->id]]);

        $lignes = $this->api($u)->getJson('/api/frontend/order/' . $o->id . '/reorder')->assertOk()->json('data.lines');

        $this->assertFalse($lignes[0]['available']);
        $this->assertSame('retire', $lignes[0]['reason']);
        $this->assertSame('« Burger retiré » n\'est plus à la carte.', $lignes[0]['message']);
        $this->assertNull($lignes[0]['payload'], 'Un produit retiré n\'est jamais remis au panier.');
        $this->assertFalse($lignes[1]['available']);
        $this->assertSame('epuise', $lignes[1]['reason']);
        $this->assertSame('« Wrap épuisé » est épuisé pour le moment.', $lignes[1]['message']);
        $this->assertNull($lignes[1]['unit_price']);
    }

    public function test_recommander_la_commande_d_un_autre_client_est_refuse(): void
    {
        [$item] = $this->produitCompose();
        $a = $this->client();
        $b = $this->client();
        $oB = $this->commande($b, [['item_id' => $item->id]]);

        $r = $this->api($a)->getJson('/api/frontend/order/' . $oB->id . '/reorder');
        $r->assertForbidden();
        $this->assertArrayNotHasKey('data', $r->json(), 'Rien de la commande d\'autrui n\'est révélé.');
    }

    // ------------------------------------------------------------------ offres par e-mail

    public function test_consentement_offres_par_email_a_un_effet_reel_et_part_avec_le_compte(): void
    {
        $u = $this->client(['email' => 'Jean.Dupont@Example.test']);

        $this->api($u)->getJson('/api/frontend/newsletter-consent')->assertOk()
            ->assertJsonPath('data.has_email', true)->assertJsonPath('data.subscribed', false);

        $this->api($u)->putJson('/api/frontend/newsletter-consent', ['subscribed' => true])->assertOk()->assertJsonPath('data.subscribed', true);
        $this->assertSame(1, Subscriber::count(), 'L\'adresse du compte est dans la liste que l\'admin utilise pour écrire aux abonnés.');
        $this->api($u)->putJson('/api/frontend/newsletter-consent', ['subscribed' => true])->assertOk();
        $this->assertSame(1, Subscriber::count(), 'Jamais deux fois.');

        $this->api($u)->putJson('/api/frontend/newsletter-consent', ['subscribed' => false])->assertOk()->assertJsonPath('data.subscribed', false);
        $this->assertSame(0, Subscriber::count(), 'Désactiver retire réellement l\'adresse.');

        $this->api($u)->putJson('/api/frontend/newsletter-consent', ['subscribed' => true])->assertOk();
        $sup = $this->api($u)->postJson('/api/auth/delete-account');
        $this->assertSame(200, $sup->status(), (string) $sup->getContent());
        $this->assertSame(0, Subscriber::count(), 'Un compte supprimé ne reçoit plus les e-mails du restaurant.');
    }

    public function test_sans_email_aucun_abonnement_possible(): void
    {
        $u = $this->client(['email' => null]);
        $this->api($u)->getJson('/api/frontend/newsletter-consent')->assertOk()->assertJsonPath('data.has_email', false);
        $this->api($u)->putJson('/api/frontend/newsletter-consent', ['subscribed' => true])
            ->assertStatus(422)->assertJsonPath('code', 'EMAIL_REQUIRED');
        $this->assertSame(0, Subscriber::count());
    }

    // ------------------------------------------------------------------ prénom / nom

    public function test_un_client_corrige_son_nom_sans_renvoyer_son_contact_meme_sans_telephone(): void
    {
        $apple = $this->client(['phone' => 'PENDING_APPLE_xyz', 'email' => null, 'name' => 'Ancien Nom']);
        $this->api($apple)->putJson('/api/profile', ['first_name' => 'Léa', 'last_name' => 'Martin'])
            ->assertOk()->assertJsonPath('data.name', 'Léa Martin');
        $this->assertSame('PENDING_APPLE_xyz', $apple->fresh()->phone, 'Le contact n\'est jamais touché.');

        $u = $this->client(['email' => 'moi@example.test']);
        $this->api($u)->putJson('/api/profile', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'autre@example.test'])
            ->assertStatus(422);
        $this->assertSame('moi@example.test', $u->fresh()->email, 'Changer d\'e-mail reste refusé à un client.');
    }
}
