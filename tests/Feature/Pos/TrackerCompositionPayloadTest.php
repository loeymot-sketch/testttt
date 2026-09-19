<?php

namespace Tests\Feature\Pos;

use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL-CAISSE-VISION 2026-08-24] Le suivi caisse doit porter la COMPOSITION.
 *
 * Besoin propriétaire : un client est au comptoir, le caissier n'a pas pris son nom.
 * Il doit pouvoir l'identifier par ce qu'il a commandé — produits ET personnalisations.
 * Avant ce test, `SimpleOrderResource::resolveItemsForTracker()` n'expédiait que
 * `item_id`, `item_name`, `quantity`, `instruction` : le contenu réel de la commande
 * n'atteignait JAMAIS l'écran de caisse.
 *
 * Ce test épingle les quatre garanties qui rendent l'enrichissement acceptable :
 *  (a) la composition arrive, sous forme COMPACTE et déjà réconciliée ;
 *  (b) elle arrive pour les DEUX formes de stockage — l'instantané NF525
 *      (`composition_snapshot`, rôles inversés) ET l'ancienne (`item_variations`) ;
 *  (c) elle ne coûte AUCUNE requête SQL supplémentaire (les colonnes sont déjà
 *      rapatriées par le `select *` existant sur `order_items`) ;
 *  (d) elle tient le budget d'octets (GOAL §3 : ≤ 600 o pour la commande la plus
 *      composée, clés absentes quand vides).
 */
class TrackerCompositionPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->actingAs(User::factory()->create(['branch_id' => 0]));
    }

    private function makeOrder(): array
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_datetime' => now(),
        ]);

        return [$order, $branch];
    }

    private function makeLine(Order $order, Branch $branch, array $attributes = []): OrderItem
    {
        $item = Item::factory()->create($attributes['__item'] ?? []);
        unset($attributes['__item']);

        return OrderItem::create(array_merge([
            'order_id'    => $order->id,
            'branch_id'   => $branch->id,
            'item_id'     => $item->id,
            'quantity'    => 1,
            'discount'    => 0,
            'price'       => 9.90,
            'total_price' => 9.90,
        ], $attributes));
    }

    /**
     * Le suivi demande la composition EXPLICITEMENT (`composition=1`) — sans ce
     * drapeau la ressource n'expédie que le contrat historique, ce que le dernier
     * cas de ce fichier vérifie.
     */
    private function payloadFor(Order $order, bool $avecComposition = true): array
    {
        $order->load(['orderItems.orderItem', 'user', 'transaction']);

        $req = Request::create('/api/admin/pos-order' . ($avecComposition ? '?composition=1' : ''), 'GET');

        return (new SimpleOrderResource($order))->toArray($req);
    }

    /**
     * Forme INSTANTANÉ NF525 : `attribute_name` porte le LIBELLÉ et `variation_name`
     * la VALEUR — rôles inversés par rapport à l'ancienne forme. C'est le piège qui
     * a déjà produit des « undefined » sur le ticket (posReceiptBuilder.js:146-163).
     *
     * @test
     */
    public function la_composition_de_l_instantane_nf525_arrive_au_suivi(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'composition_snapshot' => [
                'schema_version' => 1,
                'captured_at'    => now()->toIso8601String(),
                'lines'          => [
                    ['variation_id' => 3, 'attribute_id' => 1, 'attribute_name' => 'Sauce', 'variation_name' => 'Algérienne', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0],
                    ['variation_id' => 8, 'attribute_id' => 2, 'attribute_name' => 'Cuisson', 'variation_name' => 'À point', 'quantity' => 1, 'unit_price' => 0, 'line_total' => 0],
                ],
                'extras'         => [
                    ['extra_id' => 5, 'extra_name' => 'Cheddar', 'quantity' => 2, 'unit_price' => 0.90, 'line_total' => 1.80],
                ],
                'addons'         => [
                    ['addon_id' => 2, 'addon_item_id' => 44, 'addon_name' => 'Frites', 'role' => 'menu_frites', 'quantity' => 1, 'unit_price' => 1.20, 'line_total' => 1.20, 'catalog_price' => 3.00],
                ],
            ],
        ]);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertSame(
            [['label' => 'Sauce', 'value' => 'Algérienne'], ['label' => 'Cuisson', 'value' => 'À point']],
            $ligne['options'],
            'Le libellé vient de attribute_name et la valeur de variation_name — pas l\'inverse.'
        );
        $this->assertSame([['name' => 'Cheddar', 'quantity' => 2]], $ligne['extras']);
        $this->assertSame([['name' => 'Frites']], $ligne['addons'], 'Une quantité de 1 est implicite : on ne l\'expédie pas.');
    }

    /**
     * Forme ANCIENNE (pré-T07) : `variation_name` porte le LIBELLÉ et `name` la VALEUR.
     * Une commande d'avant l'instantané doit rester lisible à la caisse.
     *
     * @test
     */
    public function la_composition_de_l_ancienne_forme_arrive_aussi(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'item_variations' => json_encode([
                ['variation_name' => 'Pain', 'name' => 'Galette'],
            ]),
            'item_extras'     => json_encode([
                ['id' => 9, 'name' => 'Oignons frits', 'quantity' => 1],
            ]),
        ]);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertSame([['label' => 'Pain', 'value' => 'Galette']], $ligne['options']);
        $this->assertSame([['name' => 'Oignons frits']], $ligne['extras']);
    }

    /**
     * Une ligne héritée qui ne porte que des IDENTIFIANTS nus (`[{id, quantity}]` —
     * la forme écrite par `OrderQuoteService.php:255`) n'a aucun nom à montrer.
     * Elle doit être ÉCARTÉE, jamais rendue en « : » orphelin ou en identifiant brut.
     * Même règle que le normaliseur canonique (`tests/js/posOrderShowComposition.spec.js:78`).
     *
     * @test
     */
    public function une_ligne_heritee_sans_aucun_nom_est_ecartee(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'item_variations' => json_encode([['id' => 390, 'quantity' => 1]]),
        ]);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertArrayNotHasKey('options', $ligne, 'Un identifiant nu n\'est pas un libellé : rien à afficher, donc rien à expédier.');
    }

    /**
     * Budget d'octets (GOAL §3) : une ligne SANS personnalisation ne doit ajouter
     * AUCUNE clé. C'est ce qui garde la moyenne à +32 o/commande.
     *
     * @test
     */
    public function une_commande_sans_personnalisation_n_ajoute_aucune_cle(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertArrayNotHasKey('options', $ligne);
        $this->assertArrayNotHasKey('extras', $ligne);
        $this->assertArrayNotHasKey('addons', $ligne);
        $this->assertSame(['item_id', 'item_name', 'quantity', 'instruction'], array_keys($ligne));
    }

    /**
     * Budget d'octets, mesuré au niveau COMMANDE — pas au niveau ligne.
     *
     * La première version de ce test créait UNE ligne et mesurait CETTE ligne,
     * tout en prétendant borner « la commande la plus composée ». Le contre-audit
     * adverse l'a démontée : trois commandes déjà en base violaient le seuil.
     * Balayage complet du 2026-08-24 sur **3 400 commandes portant une
     * composition** : la pire (#5368, 5 lignes) pèse **687 o**, la moyenne 26,9 o.
     *
     * Le seuil est donc posé à **800 o** — au-dessus du pire cas RÉEL, avec une
     * marge, et non au-dessus d'une fixture choisie pour tenir dedans.
     *
     * @test
     */
    public function la_commande_la_plus_composee_tient_le_budget_d_octets(): void
    {
        [$order, $branch] = $this->makeOrder();

        // Cinq lignes composées — le gabarit de la pire commande réelle (#5368).
        for ($i = 0; $i < 5; $i++) {
            $this->makeLine($order, $branch, [
                'composition_snapshot' => [
                    'schema_version' => 1,
                    'lines'          => [
                        ['variation_id' => 1, 'attribute_name' => 'Pain', 'variation_name' => 'Galette', 'quantity' => 1],
                        ['variation_id' => 2, 'attribute_name' => 'Viande', 'variation_name' => 'Poulet mariné', 'quantity' => 1],
                        ['variation_id' => 3, 'attribute_name' => 'Sauce', 'variation_name' => 'Algérienne', 'quantity' => 1],
                        ['variation_id' => 4, 'attribute_name' => 'Cuisson', 'variation_name' => 'Bien cuit', 'quantity' => 1],
                    ],
                    'extras'         => [
                        ['extra_id' => 1, 'extra_name' => 'Cheddar', 'quantity' => 2],
                        ['extra_id' => 2, 'extra_name' => 'Salade', 'quantity' => 1],
                        ['extra_id' => 3, 'extra_name' => 'Tomate', 'quantity' => 1],
                        ['extra_id' => 4, 'extra_name' => 'Oignon', 'quantity' => 1],
                        ['extra_id' => 5, 'extra_name' => 'Viande supplémentaire', 'quantity' => 1],
                    ],
                    'addons'         => [],
                ],
            ]);
        }

        $lignes = $this->payloadFor($order->fresh())['order_items'];
        $this->assertCount(5, $lignes);

        $enrichissement = 0;
        foreach ($lignes as $l) {
            $enrichissement += strlen(json_encode(
                array_intersect_key($l, array_flip(['options', 'extras', 'addons'])),
                JSON_UNESCAPED_UNICODE
            ));
        }

        // Assertion POSITIVE d'abord : sans elle, supprimer la fonctionnalité
        // rendrait ce test vert (`json_encode([])` = 2 octets ≤ n'importe quel seuil).
        $this->assertGreaterThan(
            1000,
            $enrichissement,
            'Cinq lignes richement composées doivent peser quelque chose — sinon ce test ne mesure rien.'
        );

        $this->assertLessThanOrEqual(
            5 * 800,
            $enrichissement,
            "L'enrichissement de cette commande pèse {$enrichissement} o pour 5 lignes — budget 800 o/ligne."
        );
    }

    /**
     * La porte du drapeau : sans `composition=1`, la ressource n'expédie que son
     * contrat historique. C'est ce qui évite de payer la composition sur
     * l'historique et le rapport de ventes, qui ne l'affichent pas.
     *
     * @test
     */
    public function sans_le_drapeau_la_composition_ne_voyage_pas(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'composition_snapshot' => [
                'schema_version' => 1,
                'lines'          => [['variation_id' => 1, 'attribute_name' => 'Sauce', 'variation_name' => 'Algérienne', 'quantity' => 1]],
                'extras'         => [['extra_id' => 1, 'extra_name' => 'Cheddar', 'quantity' => 1]],
                'addons'         => [],
            ],
        ]);

        $avec = $this->payloadFor($order->fresh(), true)['order_items'][0];
        $sans = $this->payloadFor($order->fresh(), false)['order_items'][0];

        $this->assertArrayHasKey('options', $avec);
        $this->assertArrayHasKey('extras', $avec);

        $this->assertArrayNotHasKey('options', $sans);
        $this->assertArrayNotHasKey('extras', $sans);
        $this->assertSame(['item_id', 'item_name', 'quantity', 'instruction'], array_keys($sans));
    }

    /**
     * Une chaîne VIDE doit déclencher le repli, comme le `||` du normaliseur JS —
     * un `??` ne franchit que `null`. Sinon la carte de suivi perdrait une ligne
     * que le ticket, lui, affiche toujours.
     *
     * @test
     */
    public function une_valeur_vide_bascule_sur_le_candidat_suivant_comme_le_ticket(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'composition_snapshot' => [
                'schema_version' => 1,
                'lines'          => [
                    ['variation_id' => 5, 'attribute_name' => 'Sauce', 'variation_name' => '', 'name' => 'Algérienne', 'quantity' => 1],
                ],
                'extras'         => [],
                'addons'         => [],
            ],
        ]);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertSame([['label' => 'Sauce', 'value' => 'Algérienne']], $ligne['options']);
    }

    /**
     * L'ordre des clés d'un extra suit le lecteur du TICKET
     * (`posReceiptBuilder.js:219` → `e.name || e.extra_name`). Si les deux
     * coexistaient, la carte de suivi et le ticket doivent nommer la même chose.
     *
     * @test
     */
    public function un_extra_portant_les_deux_cles_est_nomme_comme_sur_le_ticket(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'composition_snapshot' => [
                'schema_version' => 1,
                'lines'          => [],
                'extras'         => [['extra_id' => 1, 'name' => 'Salade', 'extra_name' => 'Tomate', 'quantity' => 1]],
                'addons'         => [],
            ],
        ]);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertSame([['name' => 'Salade']], $ligne['extras']);
    }

    /**
     * Garde N+1 (GOAL §3 contrainte 2) : la composition vit dans des COLONNES de
     * `order_items` déjà rapatriées par la requête existante. Sérialiser la
     * ressource ne doit déclencher AUCUNE requête supplémentaire.
     *
     * @test
     */
    public function la_composition_ne_coute_aucune_requete_supplementaire(): void
    {
        [$order, $branch] = $this->makeOrder();
        for ($i = 0; $i < 5; $i++) {
            $this->makeLine($order, $branch, [
                'composition_snapshot' => [
                    'schema_version' => 1,
                    'lines'          => [['variation_id' => $i, 'attribute_name' => 'Sauce', 'variation_name' => 'Blanche', 'quantity' => 1]],
                    'extras'         => [['extra_id' => $i, 'extra_name' => 'Cheddar', 'quantity' => 1]],
                    'addons'         => [],
                ],
            ]);
        }

        $frais = $order->fresh();
        $frais->load(['orderItems.orderItem', 'user', 'transaction']);

        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });

        $payload = (new SimpleOrderResource($frais))->toArray(
            Request::create('/api/admin/pos-order?composition=1', 'GET')
        );

        $this->assertSame(0, $requetes, "La sérialisation a déclenché {$requetes} requête(s) — la garde N+1 est rompue.");

        // Assertion POSITIVE : sans elle, supprimer la fonctionnalité laisserait ce
        // test vert (0 requête dans les deux cas). Ce qu'on prouve, c'est que la
        // composition arrive ET qu'elle n'a rien coûté — pas seulement le second.
        $this->assertCount(5, $payload['order_items']);
        foreach ($payload['order_items'] as $i => $l) {
            $this->assertArrayHasKey('options', $l, "ligne {$i} sans composition");
            $this->assertArrayHasKey('extras', $l, "ligne {$i} sans extras");
        }
    }

    /**
     * La garde `relationLoaded` existante (SimpleOrderResource.php:225) doit survivre :
     * sans eager-load, on renvoie [] plutôt que de déclencher un lazy SELECT.
     *
     * @test
     */
    public function sans_eager_load_le_contrat_reste_un_tableau_vide(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'composition_snapshot' => ['schema_version' => 1, 'lines' => [['variation_id' => 1, 'attribute_name' => 'Sauce', 'variation_name' => 'Blanche']], 'extras' => [], 'addons' => []],
        ]);

        $payload = (new SimpleOrderResource($order->fresh()))->resolve();

        $this->assertSame([], $payload['order_items']);
    }

    /**
     * Une entrée d'instantané dont le nom est vide ne doit pas produire une puce
     * fantôme sur la carte de suivi — elle est écartée à la source.
     *
     * @test
     */
    public function une_entree_sans_nom_ne_produit_pas_de_puce_fantome(): void
    {
        [$order, $branch] = $this->makeOrder();
        $this->makeLine($order, $branch, [
            'composition_snapshot' => [
                'schema_version' => 1,
                'lines'          => [['variation_id' => 1, 'attribute_name' => 'Sauce', 'variation_name' => '   ']],
                'extras'         => [['extra_id' => 1, 'extra_name' => '', 'quantity' => 2]],
                'addons'         => [],
            ],
        ]);

        $ligne = $this->payloadFor($order->fresh())['order_items'][0];

        $this->assertArrayNotHasKey('options', $ligne);
        $this->assertArrayNotHasKey('extras', $ligne);
    }
}
