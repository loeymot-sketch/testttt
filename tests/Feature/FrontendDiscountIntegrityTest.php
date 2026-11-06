<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderCoupon;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class FrontendDiscountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $orderUser;
    protected User $loyaltyCustomer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 100,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Discount Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '20 rue remise',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Menus',
            'slug' => 'menus',
            'status' => 5,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Discount Menu',
            'slug' => 'discount-menu',
            'price' => 20.00,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->orderUser = User::forceCreate([
            'name' => 'Frontend Order User',
            'email' => 'frontend-discount@test.local',
            'username' => 'frontend_discount',
            'phone' => '0600000010',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            // [Sprint H1 Z6-06 2026-05-17] Was `1`; canonical user-status
            // ACTIVE = 5 (App\Enums\Status). EnsureUserStatusActive rejects
            // any non-ACTIVE user — pre-existing test-fixture data bug.
            'status' => Status::ACTIVE,
        ]);

        $this->loyaltyCustomer = User::forceCreate([
            'name' => 'Loyalty Customer',
            'email' => 'loyalty-customer@test.local',
            'username' => 'loyalty_customer',
            'phone' => '0600000011',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            // [Sprint H1 Z6-06 2026-05-17] Was `1`; see comment above.
            'status' => Status::ACTIVE,
            'loyalty_code' => 'LOYAL100',
            'loyalty_points' => 500,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 20.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => null,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    public function test_invalid_coupon_id_rejects_frontend_order(): void
    {
        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => 999999,
            ]));

        $response->assertStatus(422);
        $this->assertSame(0, OrderCoupon::count());
    }

    public function test_valid_coupon_is_applied_through_centralized_validation(): void
    {
        // [GOAL-GOLIVE-VAT10] Subject = discount CALC/anti-forgery correctness, not
        // the V1 on/off policy → enable the discretionary-discount master flag so the
        // gated path runs (mirrors PosDiscountTest:46). Production default stays OFF;
        // the OFF behaviour is locked by test_discretionary_discount_disabled_..._v1().
        config(['pos.manual_discount_enabled' => true]);

        $coupon = Coupon::forceCreate([
            'name' => 'FRONT10',
            'description' => '10 percent',
            'code' => 'FRONT10',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 2,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id, 'coupon_code' => $coupon->code,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 2.00,
        ]);

        $this->assertDatabaseHas('order_coupons', [
            'order_id' => $orderId,
            'coupon_id' => $coupon->id,
            'discount' => 2.00,
        ]);
    }

    /**
     * [LOCK_COUPON_SURFACE_BRANCHE_AU_PAIEMENT_2026-11-05] Code promo limité à une SURFACE ou une BRANCHE : vérifié avec la
     * surface et la branche réelles de la commande. Avant : accepté par « Appliquer » (coupon-checking), refusé à la commande
     * (422) parce que le chemin SSOT validait avec isUsableNow(null, null). Prouvé mordant sur le code d'avant.
     *
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function restrictionsDuCode(): array
    {
        return [
            'limité au web'              => [['surfaces' => ['web']], 201],
            'limité à la borne'          => [['surfaces' => ['kiosk']], 422],
            'limité à la caisse'         => [['surfaces' => ['pos']], 422],
            'limité à SA branche'        => [['branch_scope' => ['__branche__']], 201],
            'limité à une autre branche' => [['branch_scope' => [999999]], 422],
            'sans restriction'           => [[], 201],
        ];
    }

    /** @dataProvider restrictionsDuCode */
    public function test_code_limite_a_une_surface_ou_une_branche_est_verifie_avec_celles_de_la_commande(array $restriction, int $attendu): void
    {
        config(['pos.manual_discount_enabled' => true]);
        if (isset($restriction['branch_scope'])) {
            $restriction['branch_scope'] = array_map(fn ($b) => $b === '__branche__' ? $this->branch->id : $b, $restriction['branch_scope']);
        }
        $coupon = Coupon::forceCreate(array_merge([
            'name' => 'SURF10', 'description' => '10 percent', 'code' => 'SURF10',
            'discount' => 10, 'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
            'minimum_order' => 10, 'maximum_discount' => 5, 'limit_per_user' => 2,
        ], $restriction));

        $response = $this->actingAs($this->orderUser, 'sanctum')->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload(['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code]));

        $response->assertStatus($attendu);
        if ($attendu === 201) {
            // Montant INCHANGÉ par le correctif : 10 % de 20,00 € = 2,00 €, identique au test du code sans restriction.
            $this->assertDatabaseHas('orders', ['id' => $response->json('data.id'), 'discount' => 2.00]);
        } else {
            $this->assertSame(0, OrderCoupon::count(), 'aucune remise enregistrée pour un code refusé');
        }
    }

    /**
     * [AUDIT ABUSIF 2026-11-06 · W3-P1-2] Un ticket promo NOMINATIF n'est protégé que par son code : la commande web qui
     * présente seulement le numéro (séquentiel) du code, ou le numéro avec un autre code, est refusée et ne consomme rien.
     */
    public function test_le_numero_du_code_promo_sans_son_code_ne_suffit_pas(): void
    {
        config(['pos.manual_discount_enabled' => true]);
        $coupon = Coupon::forceCreate([
            'name' => 'TICKET', 'description' => 'nominatif', 'code' => 'SARAH-7K2P',
            'discount' => 50, 'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
            'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 1,
        ]);
        $web = fn (array $extra) => $this->actingAs($this->orderUser, 'sanctum')->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload($extra));

        $web(['coupon_id' => $coupon->id])->assertStatus(422);
        $web(['coupon_id' => $coupon->id, 'coupon_code' => 'AUTRE-CODE'])->assertStatus(422);
        $web(['coupon_id' => $coupon->id, 'coupon_code' => ['SARAH-7K2P']])->assertStatus(422)->assertJsonPath('message', 'Ce code promo n\'est pas valable pour cette commande. Saisissez-le à nouveau.'); // tour 3 : tableau forgé
        $this->assertSame(0, OrderCoupon::count(), 'le ticket n\'est pas consommé');
        $web(['coupon_id' => $coupon->id, 'coupon_code' => ' sarah-7k2p '])->assertStatus(201);
    }

    /**
     * [AUDIT ABUSIF 2026-11-06 · W3-P1-1] Une commande WEB sans formule payée ne peut pas faire annoncer un menu en cuisine
     * par son instruction ; la note du client entre crochets reste intacte.
     */
    public function test_une_formule_non_payee_n_est_pas_annoncee_en_cuisine(): void
    {
        $instruction = "Boisson menu: Coca-Cola 33cl\nSauce frites : Mayonnaise\n+ Menu (Frites + Boisson)\nFormule : Menu complet (Coca-Cola 33cl)\nMENU : MAY\nViandes en plus : Poulet.\n[Note : sans oignon, allergie lait]";
        $r = $this->actingAs($this->orderUser, 'sanctum')->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'items' => json_encode([['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => [], 'instruction' => $instruction]]),
            ]));
        $r->assertStatus(201);
        $ligne = \App\Models\OrderItem::query()->withoutGlobalScopes()->where('order_id', $r->json('data.id'))->first();
        // [tour 6] « Viandes en plus » sans extra viande payé n'est plus annoncé en cuisine.
        $this->assertSame("[Note : sans oignon, allergie lait]", (string) $ligne->instruction);
        $f = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class);
        $this->assertSame('', $f->menuBadge(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos M', $ligne->instruction), 'aucun badge MENU');

        // [ADV2-001] Un addon « menu_full » SANS identifiant (jamais facturé) ne vaut pas une formule payée.
        $r2 = $this->actingAs($this->orderUser, 'sanctum')->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'items' => json_encode([['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => [], 'item_addons' => [['role' => 'menu_full']], 'instruction' => $instruction]]),
            ]));
        $r2->assertStatus(201);
        $ligne2 = \App\Models\OrderItem::query()->withoutGlobalScopes()->where('order_id', $r2->json('data.id'))->first();
        $this->assertStringNotContainsString('Boisson menu', (string) $ligne2->instruction);
        $this->assertStringNotContainsString('Menu (Frites', (string) $ligne2->instruction);
    }

    /**
     * [AUDIT ABUSIF 2026-11-06 · tour 2] Contournements de la liste noire : espace de largeur nulle, accents, espace insécable,
     * « + Drink », « Sauce frites : » DANS la note. Liste blanche : rien de cela n'annonce une formule non payée ; un vrai
     * menu (addon menu_* avec identifiant) garde ses lignes.
     */
    public function test_contournements_de_formule_neutralises_par_liste_blanche(): void
    {
        $f = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class);
        $forge = "+ Me\u{200B}nu (Frites + Boisson)\n+ Ménu (Frîtes + Boissön)\n\u{00A0}+ Menu complet\n+ Drink\nViandes en plus : Poulet.\n[Note commande : Sauce frites : Mayonnaise]";
        $propre = \App\Services\FrontendOrderService::instructionWebAssainie($forge, false);
        $this->assertSame("Viandes en plus : Poulet.\n[Note commande : Sauce frites - Mayonnaise]", $propre);
        $this->assertSame('', $f->menuBadge(['lines' => [], 'extras' => [], 'addons' => []], 'Tacos M', $propre));

        $vraiMenu = "Frites: Frites cheddar\nBoisson menu: Coca-Cola 33cl\nViandes en plus : Viande Hachée.\nSauce frites : Mayonnaise\n[Note : bien cuit]";
        $this->assertSame($vraiMenu, \App\Services\FrontendOrderService::instructionWebAssainie($vraiMenu, true), 'un menu payé est intact');
        $this->assertStringNotContainsString('Boisson menu', \App\Services\FrontendOrderService::instructionWebAssainie($vraiMenu, false));
    }

    /**
     * [AUDIT ABUSIF 2026-11-06 · tour 3 · R3-ADV-001] Formule PARTIELLE payée : « frites seules » ne fait pas servir de
     * boisson, « boisson seule » ne fait pas servir de frites. Chaîne réelle : assainissement → ticket cuisine.
     */
    public function test_formule_partielle_ne_fait_pas_servir_l_autre_moitie(): void
    {
        $f = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class);
        $this->boissonAuCatalogue('Coca-Cola 33cl');
        $passe = function (string $role, string $instruction): string {
            $ligne = (object) ['item_id' => $this->item->id, 'quantity' => 1, 'instruction' => $instruction,
                'item_addons' => [(object) ['id' => 5, 'quantity' => 1, 'role' => $role]]];

            return \App\Services\FrontendOrderService::sansFormuleNonPayee([$ligne])[0]->instruction;
        };

        $frites = $passe('menu_frites', "Boisson menu: Coca-Cola 33cl\nSauce frites : Mayonnaise");
        $this->assertSame("Sauce frites : Mayonnaise", $frites);
        $this->assertStringNotContainsString('Coca', $f->cleanInstruction($frites, 'Sandwich Classique'));

        $boisson = $passe('menu_boisson', "Boisson menu: Coca-Cola 33cl\nSauce frites : Mayonnaise");
        $this->assertSame('Boisson menu: Coca-Cola 33cl', $boisson);
        $this->assertStringNotContainsString('Frites', $f->cleanInstruction($boisson, 'Sandwich Classique'));

        // Témoin : la formule complète garde tout, allergie comprise.
        $complet = "Boisson menu: Coca-Cola 33cl\nSauce frites : Mayonnaise\n[Note commande : sans oignon, ALLERGIE arachide]";
        $this->assertSame($complet, $passe('menu_full', $complet));
    }

    private function boissonAuCatalogue(string $nom, int $status = 5, bool $dispo = true): Item
    {
        $cat = ItemCategory::query()->where('slug', 'boissons')->first() ?? ItemCategory::forceCreate(['name' => 'Boissons', 'slug' => 'boissons', 'status' => 5]);

        return Item::forceCreate(['name' => $nom, 'slug' => \Illuminate\Support\Str::slug($nom).'-'.uniqid(), 'price' => 2.00,
            'status' => $status, 'is_available' => $dispo, 'item_category_id' => $cat->id]);
    }

    private function extra(string $nom, float $prix): \App\Models\ItemExtra
    {
        return \App\Models\ItemExtra::forceCreate(['item_id' => $this->item->id, 'name' => $nom, 'price' => $prix, 'status' => 5,
            'group_label' => str_contains($nom, 'Sauce') ? 'sauce' : 'supplement']);
    }

    /**
     * [AUDIT ABUSIF 2026-11-06 · tour 6 · P1 sauces gratuites] La cuisine imprime les sauces et viandes nommées dans
     * l'instruction : jamais plus que les extras réellement facturés sur CET article. 1ʳᵉ sauce frites offerte.
     * Chaîne réelle : POST /api/frontend/order → instruction scellée → ticket cuisine.
     */
    public function test_sauces_et_viandes_annoncees_bornees_aux_extras_payes(): void
    {
        $sauce = $this->extra('Sauce supplémentaire', 0.50);
        $viande = $this->extra('Viande supplémentaire', 2.50);
        $poste = function (array $extras, string $instruction): string {
            $r = $this->actingAs($this->orderUser, 'sanctum')->withHeader('x-api-key', '123456')
                ->postJson('/api/frontend/order', $this->basePayload([
                    'items' => json_encode([['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => $extras, 'instruction' => $instruction]]),
                ]));
            $r->assertStatus(201);

            return (string) \App\Models\OrderItem::query()->withoutGlobalScopes()->where('order_id', $r->json('data.id'))->value('instruction');
        };

        // Commande 9926 : six sauces annoncées, AUCUNE payée → aucune imprimée.
        $this->assertSame('', $poste([], 'Sauces en plus : Samouraï, Curry, Blanche, Harissa, Barbecue, Andalouse.'));
        // Deux sauces payées, six annoncées → les deux premières seulement.
        $this->assertSame('Sauces en plus : Samouraï, Curry.', $poste([['id' => $sauce->id, 'quantity' => 2]], 'Sauces en plus : Samouraï, Curry, Blanche, Harissa, Barbecue, Andalouse.'));
        // Une viande payée, trois annoncées → une.
        $this->assertSame('Viandes en plus : Poulet.', $poste([['id' => $viande->id, 'quantity' => 1]], 'Viandes en plus : Poulet, Tenders, Kebab.'));
        // Quantité forgée (-5) : facturée 1 par PricingService → bornée à 1.
        $this->assertSame('Sauces en plus : Curry.', $poste([['id' => $sauce->id, 'quantity' => -5]], 'Sauces en plus : Curry, Blanche.'));

        // Commande 9925 : menu payé + cinq sauces frites, aucune en plus payée → la 1ʳᵉ (offerte) seulement.
        $passe = function (array $extras, string $instruction): string {
            $ligne = (object) ['item_id' => $this->item->id, 'quantity' => 1, 'instruction' => $instruction, 'item_extras' => $extras,
                'item_addons' => [(object) ['id' => 5, 'quantity' => 1, 'role' => 'menu_full']]];

            return \App\Services\FrontendOrderService::sansFormuleNonPayee([$ligne])[0]->instruction;
        };
        $this->assertSame('Sauce frites : Ketchup', $passe([], 'Sauce frites : Ketchup, Mayonnaise, Samouraï, Curry, Blanche'));
        // Parcours réel du site (api.js) : 2 sauces sandwich en plus + 2 sauces frites dont 1 offerte = 3 extras cumulés.
        $site = "Sauces en plus : Curry, Blanche.\nSauce frites : Ketchup, Mayonnaise";
        $this->assertSame($site, $passe([(object) ['id' => $sauce->id, 'quantity' => 3]], $site));
        // Extra d'un AUTRE article : ne paie rien ici.
        $autre = \App\Models\ItemExtra::forceCreate(['item_id' => $this->boissonAuCatalogue('Sprite 33cl')->id, 'name' => 'Sauce supplémentaire', 'price' => 0.5, 'status' => 5, 'group_label' => 'sauce']);
        $this->assertSame('', $passe([(object) ['id' => $autre->id, 'quantity' => 4]], 'Sauces en plus : Curry, Blanche.'));

        $f = app(\App\Services\Hardware\KitchenTicketSymbolicFormatter::class);
        $this->assertSame([], $f->extraSauceNames($poste([], 'Sauces en plus : Samouraï, Curry.')), 'le ticket cuisine ne nomme aucune sauce non payée');
    }

    /**
     * [AUDIT ABUSIF 2026-11-06 · tour 6 · P1 contenu de formule] « Boisson menu » = UNE boisson active du catalogue
     * (9949 : trois boissons ; 9948 : « Champagne Dom Perignon ») ; style de frites gratuit dans un menu (9949) retiré.
     */
    public function test_contenu_de_formule_borne_au_catalogue(): void
    {
        $this->boissonAuCatalogue('Fanta Orange 33cl');
        $this->boissonAuCatalogue('Oasis Tropical 33cl', 5, false);
        $formule = Item::forceCreate(['name' => 'Menu (Frites + Boisson)', 'slug' => 'menu-formule-tour6', 'price' => 2.50, 'status' => 5, 'item_category_id' => $this->item->item_category_id]);
        $pivot = \App\Models\ItemAddon::create(['item_id' => $this->item->id, 'addon_item_id' => $formule->id, 'role' => 'menu_component']);
        $poste = fn (string $instruction) => $this->actingAs($this->orderUser, 'sanctum')->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'items' => json_encode([['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => [],
                    'item_addons' => [['id' => $pivot->id, 'quantity' => 1, 'role' => 'menu_full']], 'instruction' => $instruction]]),
            ]));
        $passe = function (string $instruction): string {
            $ligne = (object) ['item_id' => $this->item->id, 'quantity' => 1, 'instruction' => $instruction,
                'item_addons' => [(object) ['id' => 5, 'quantity' => 1, 'role' => 'menu_full']]];

            return \App\Services\FrontendOrderService::sansFormuleNonPayee([$ligne])[0]->instruction;
        };

        $this->assertSame('Boisson menu: Fanta Orange 33cl', $passe('Boisson menu: fanta orange 33cl'), 'nom canonique du catalogue');
        // Grandes frites cheddar offertes dans un menu : retirées (le produit commandé n'est pas ces frites).
        $this->assertSame('Boisson menu: Fanta Orange 33cl', $passe("Frites: Grande Frites Cheddar + Oignons frits\nBoisson menu: Fanta Orange 33cl"));

        foreach (['Boisson menu: Fanta Orange 33cl, Sprite 33cl, Orangina 33cl', 'Boisson menu: Champagne Dom Perignon', 'Boisson menu: Discount Menu'] as $forge) {
            try {
                $passe($forge);
                $this->fail('accepté : '.$forge);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('pas reconnue', $e->getMessage());
            }
        }
        try {
            $passe('Boisson menu: Oasis Tropical 33cl');
            $this->fail('boisson en rupture acceptée');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('plus disponible', $e->getMessage());
        }
        // Chaîne HTTP réelle (commande 9948) : refus lisible, aucune commande créée.
        $avant = \App\Models\FrontendOrder::query()->withoutGlobalScopes()->count();
        $poste('Boisson menu: Champagne Dom Perignon')->assertStatus(422)
            ->assertJsonPath('message', 'La boisson de votre menu n\'est pas reconnue. Modifiez l\'article pour en choisir une autre.');
        $this->assertSame($avant, \App\Models\FrontendOrder::query()->withoutGlobalScopes()->count(), 'rien n\'est créé');
        // Témoin : la même commande avec une vraie boisson passe (montant serveur : 20 + 2,50).
        $ok = $poste('Boisson menu: Fanta Orange 33cl');
        $ok->assertStatus(201);
        $this->assertSame('Boisson menu: Fanta Orange 33cl', (string) \App\Models\OrderItem::query()->withoutGlobalScopes()->where('order_id', $ok->json('data.id'))->value('instruction'));
        // Formule NON payée : la ligne est retirée, sans refus (comportement du tour 2).
        $nonPayee = (object) ['item_id' => $this->item->id, 'quantity' => 1, 'instruction' => 'Boisson menu: Champagne Dom Perignon'];
        $this->assertSame('', \App\Services\FrontendOrderService::sansFormuleNonPayee([$nonPayee])[0]->instruction);
    }

    public function test_forged_frontend_totals_do_not_change_server_coupon_discount(): void
    {
        // [GOAL-GOLIVE-VAT10] Subject = anti-forgery (server ignores client totals),
        // not the V1 on/off policy → enable the master flag so the discount path runs.
        config(['pos.manual_discount_enabled' => true]);

        $coupon = Coupon::forceCreate([
            'name' => 'FRONT10_FORGED',
            'description' => '10 percent',
            'code' => 'FRONT10-FORGED',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 100,
            'limit_per_user' => 2,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id, 'coupon_code' => $coupon->code,
                'subtotal' => 999.00,
                'total' => 999.00,
                'discount' => 900.00,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 20.00,
            'discount' => 2.00,
            'total' => 18.00,
        ]);

        $this->assertDatabaseHas('order_coupons', [
            'order_id' => $orderId,
            'coupon_id' => $coupon->id,
            'discount' => 2.00,
        ]);
    }

    public function test_coupon_takes_priority_over_loyalty_discount_on_frontend_order(): void
    {
        // [GOAL-GOLIVE-VAT10] Subject = coupon-over-loyalty priority, not the V1
        // on/off policy → enable the master flag so both discount paths are live.
        config(['pos.manual_discount_enabled' => true]);

        $coupon = Coupon::forceCreate([
            'name' => 'FIXED5',
            'description' => '5 euros',
            'code' => 'FIXED5',
            'discount' => 5,
            'discount_type' => DiscountType::FIXED,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 1,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id, 'coupon_code' => $coupon->code,
                'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
                'discount' => 3.00,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 5.00,
        ]);

        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertFalse((bool) $response->json('loyalty_applied'));
    }

    public function test_loyalty_below_minimum_points_is_not_applied_server_side(): void
    {
        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
                'discount' => 0.50,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 0.00,
        ]);
        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertFalse((bool) $response->json('loyalty_applied'));
    }

    /**
     * [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Sentinel — les kill-switches côté
     * FrontendOrderService::assertDiscretionaryDiscountAllowed refusent chacun leur
     * famille de remise, avec rollback intégral de la transaction :
     *   - coupon (discrétionnaire) → manual_discount_enabled=false → 422 + rollback.
     *   - redeem fidélité → loyalty_enabled=false → 422 + rollback (aucun point brûlé).
     * Coupon et redeem sont mutuellement exclusifs sur ce chemin, donc les deux
     * flags gouvernent des sous-chemins disjoints. Le découplage (redeem AUTORISÉ
     * quand manual_discount_enabled=false mais loyalty_enabled=true) est prouvé par
     * LoyaltyDecoupledFromManualDiscountTest.
     */
    public function test_discretionary_discount_killswitch_engages_on_frontend_v1(): void
    {
        // Chaque famille est coupée par SON kill-switch dédié ; on vérifie le
        // rollback intégral (canal de sécurité si une famille doit être re-coupée).
        config(['pos.manual_discount_enabled' => false]);

        // --- Sub-path A: coupon (kill-switch remises manuelles) ---
        $coupon = Coupon::forceCreate([
            'name' => 'GATE10',
            'description' => '10 percent',
            'code' => 'GATE10',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 2,
        ]);

        $couponResponse = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id, 'coupon_code' => $coupon->code,
            ]));

        // The gate throws a ValidationException, but myOrderStore's catch(Exception)
        // re-wraps it as a generic 422 (the field-error structure is flattened) — so
        // we assert the status + the transaction-rollback effects, matching the
        // canonical ManualDiscountDisabledV1SentinelTest convention (status-only).
        $couponResponse->assertStatus(422);
        $this->assertSame(0, OrderCoupon::count(), 'Refused coupon order must not persist a coupon link.');
        $this->assertSame(0, \App\Models\FrontendOrder::count(), 'Refused coupon order must roll back entirely.');

        // --- Sub-path B: kiosk/web loyalty redeem (kill-switch DÉDIÉ fidélité) ---
        // [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Le redeem fidélité est gouverné par
        // loyalty_enabled (pas manual_discount_enabled) : on active donc SON
        // kill-switch pour prouver le refus + rollback (aucun point brûlé). The
        // FrontendOrderService kiosk-loyalty lookup matches status=1 (legacy active),
        // so use a status=1 redeemer holding enough points. 5.00 EUR == 500 pts
        // (rate 100) >= min 100, balance 500 → the redeem WOULD apply a 5.00 €
        // discount, which the loyalty kill-switch must refuse (422) + roll tx back.
        config(['pos.loyalty_enabled' => false]);
        $redeemer = User::forceCreate([
            'name' => 'Loyalty Redeemer',
            'email' => 'loyalty-redeemer@test.local',
            'username' => 'loyalty_redeemer',
            'phone' => '0600000012',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 1,
            'loyalty_code' => 'LEGACY500',
            'loyalty_points' => 500,
        ]);

        $loyaltyResponse = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'loyalty_code' => $redeemer->loyalty_code,
                'discount' => 5.00,
            ]));

        $loyaltyResponse->assertStatus(422);
        // Transaction rollback proof: no points burned, no ledger, no order.
        $this->assertSame(500, (int) $redeemer->fresh()->loyalty_points, 'Refused loyalty redeem must not burn points.');
        $this->assertSame(0, \App\Models\LoyaltyTransaction::where('type', 'redeem')->count(), 'Refused loyalty redeem must not write a ledger entry.');
        $this->assertSame(0, \App\Models\FrontendOrder::count(), 'Refused loyalty order must roll back entirely.');
    }
}
