<?php

namespace Tests\Feature\RawMaterials;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RawMaterial;
use App\Models\RawMaterialRecipeLine;
use App\Models\User;
use App\Services\RawMaterials\RawMaterialConsumptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [STOCK-VIANDE 2026-08-06 owner] La consommation matière suit désormais le CHOIX RÉEL du
 * client, plus la fiche produit.
 *
 * LE DÉFAUT QUE CE TEST VERROUILLE — constaté sur des commandes réelles avant correction :
 *   order_item #5729  Cayenne « Mixte (hachée + poulet) » → Poulet −200 g, hachée 0 g
 *   order_item #5849  Méga « Tenders + Cordon Bleu »      → AUCUNE viande décrémentée
 * Sur 30 jours : viande hachée −25 %, frites −96 %, cordon bleu −70 %.
 *
 * Cause : aucune ligne de recette par variation, donc la viande consommée était toujours celle
 * de la fiche produit. La cuisine cuisait 2 steaks pendant que le stock retirait 200 g de poulet.
 *
 * Ces tests APPELLENT le service et lisent le stock réel — ils ne relisent pas le source.
 */
class MeatDrivenConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = ItemCategory::create([
            'name' => 'Sandwichs',
            'slug' => 'sandwichs-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function matiere(string $nom, string $unit = 'piece', ?float $poids = null): RawMaterial
    {
        return RawMaterial::create([
            'branch_id' => 1, 'name' => $nom, 'unit' => $unit,
            'piece_weight_g' => $poids, 'is_active' => true,
        ]);
    }

    private function item(string $nom): Item
    {
        return Item::forceCreate([
            'name' => $nom, 'slug' => Str::slug($nom).'-'.Str::random(6),
            'item_category_id' => $this->category->id, 'item_type' => 1,
            'price' => 10, 'status' => Status::ACTIVE,
        ]);
    }

    private function commande(): Order
    {
        $branch = Branch::factory()->create();

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
        ]);
    }

    private function ligne(Order $order, Item $item, int $qte, array $snapshot = [], string $instruction = ''): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id, 'branch_id' => 1, 'item_id' => $item->id,
            'quantity' => $qte, 'price' => 10, 'discount' => 0,
            'item_variation_total' => 0, 'item_extra_total' => 0, 'total_price' => 10 * $qte,
            'item_variations' => '[]', 'item_extras' => '[]',
            'composition_snapshot' => $snapshot ?: ['lines' => [], 'extras' => [], 'addons' => []],
            'instruction' => $instruction,
        ]);
    }

    private function viandes(array $noms, array $extras = [], array $addons = []): array
    {
        $lines = [];
        foreach (array_values($noms) as $i => $n) {
            $lines[] = ['attribute_name' => 'Viande '.($i + 1), 'variation_name' => $n];
        }

        return ['lines' => $lines, 'extras' => $extras, 'addons' => $addons];
    }

    private function onHand(RawMaterial $m): float
    {
        return (float) \App\Models\RawMaterialStock::query()
            ->where('raw_material_id', $m->id)->where('branch_id', 1)->value('on_hand');
    }

    private function consomme(Order $order): array
    {
        return app(RawMaterialConsumptionService::class)->consumeForOrder($order);
    }

    // ── Le défaut d'origine ───────────────────────────────────────────────────

    /**
     * LE CŒUR : un Cayenne commandé en VIANDE HACHÉE doit retirer de la viande hachée.
     * Avant correction il retirait 200 g de poulet et zéro hachée.
     */
    public function test_la_viande_choisie_par_le_client_est_celle_qui_sort_du_stock(): void
    {
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $poulet = $this->matiere('Poulet mariné');
        $cayenne = $this->item('Cayenne');
        // La fiche produit porte l'ANCIEN forfait poulet : il doit être ignoré, pas additionné.
        $this->recetteProduit($cayenne, $poulet, 1);

        $order = $this->commande();
        $this->ligne($order, $cayenne, 1, $this->viandes(['Viande Hachée']));
        $this->consomme($order);

        $this->assertSame(-150.0, $this->onHand($hachee), 'Un Cayenne hachée = portion complète = 2 pièces × 75 g.');
        $this->assertSame(0.0, $this->onHand($poulet), 'Le forfait poulet de la fiche produit doit être IGNORÉ : sinon on décompte une viande jamais servie.');
    }

    /** Un produit à deux viandes retire une pièce de chacune — le Méga ne retirait rien. */
    public function test_un_produit_a_deux_viandes_retire_une_piece_de_chacune(): void
    {
        $tenders = $this->matiere('Tenders');
        $cordon = $this->matiere('Cordon bleu');
        $mega = $this->item('Méga');

        $order = $this->commande();
        $this->ligne($order, $mega, 1, $this->viandes(['Tenders', 'Cordon Bleu']));
        $this->consomme($order);

        $this->assertSame(-1.0, $this->onHand($tenders));
        $this->assertSame(-1.0, $this->onHand($cordon));
    }

    /** Une recette FIXE documentée sort ses vraies quantités : le Big Burger a 3 steaks. */
    public function test_une_recette_fixe_retire_ses_vraies_quantites(): void
    {
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $burger = $this->item('Big Burger');

        $order = $this->commande();
        $this->ligne($order, $burger, 2, $this->viandes([]));
        $this->consomme($order);

        $this->assertSame(-450.0, $this->onHand($hachee), '2 Big Burgers × 3 steaks × 75 g.');
    }

    /** Ce que la recette fixe VRAIMENT (pain, cheddar) reste décompté : rien n'a été perdu. */
    public function test_les_matieres_non_viande_restent_pilotees_par_la_recette(): void
    {
        $pain = $this->matiere('Pain');
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $tacos = $this->item('Tacos M');
        $this->recetteProduit($tacos, $pain, 1);

        $order = $this->commande();
        $this->ligne($order, $tacos, 3, $this->viandes(['Viande Hachée']));
        $this->consomme($order);

        $this->assertSame(-3.0, $this->onHand($pain), 'Le pain reste piloté par la fiche produit.');
        $this->assertSame(-450.0, $this->onHand($hachee), '3 tacos × 2 pièces × 75 g.');
    }

    // ── Suppléments : pas de double comptage, pas de perte ─────────────────────

    /** Supplément NOMMÉ → la vraie viande, et la ligne forfaitaire de groupe est écartée. */
    public function test_un_supplement_nomme_sort_la_vraie_viande_sans_doublon(): void
    {
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $poulet = $this->matiere('Poulet mariné');
        $cayenne = $this->item('Cayenne');
        // Ligne de GROUPE historique : 75 g de hachée forfaitaires pour tout supplément.
        RawMaterialRecipeLine::create([
            'branch_id' => 1, 'subject_type' => 'extra_group', 'subject_id' => 1,
            'subject_group' => 'viande supplémentaire', 'raw_material_id' => $hachee->id, 'qty' => 75,
        ]);

        $order = $this->commande();
        $this->ligne(
            $order, $cayenne, 1,
            $this->viandes(['Poulet mariné'], [['extra_name' => 'Viande supplémentaire', 'quantity' => 1]]),
            'Viandes en plus : Viande Hachée'
        );
        $this->consomme($order);

        $this->assertSame(-2.0, $this->onHand($poulet), 'Le Cayenne apporte 2 pièces de poulet.');
        $this->assertSame(-150.0, $this->onHand($hachee), 'Le supplément nommé vaut une portion complète (2×75 g) — et PAS 150+75 : la ligne de groupe doit être écartée.');
    }

    /**
     * Supplément NON nommé → on garde la moyenne historique. Mieux vaut une approximation
     * assumée qu'un trou silencieux : la consommation ne doit pas disparaître.
     */
    public function test_un_supplement_non_nomme_garde_la_moyenne_historique(): void
    {
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $poulet = $this->matiere('Poulet mariné');
        $cayenne = $this->item('Cayenne');
        RawMaterialRecipeLine::create([
            'branch_id' => 1, 'subject_type' => 'extra_group', 'subject_id' => 1,
            'subject_group' => 'viande supplémentaire', 'raw_material_id' => $hachee->id, 'qty' => 75,
        ]);

        $order = $this->commande();
        $this->ligne(
            $order, $cayenne, 1,
            $this->viandes(['Poulet mariné'], [['extra_name' => 'Viande supplémentaire', 'quantity' => 1]]),
            ''  // aucune ligne « Viandes en plus » : le nom est irrécupérable
        );
        $this->consomme($order);

        $this->assertSame(-75.0, $this->onHand($hachee), 'Sans nom, la moyenne historique de 75 g doit rester appliquée.');
        $this->assertSame(-2.0, $this->onHand($poulet));
    }

    // ── Frites ────────────────────────────────────────────────────────────────

    /** Les frites de menu sortent enfin du stock : elles étaient décomptées à 4 % du réel. */
    public function test_les_frites_de_menu_sortent_du_stock(): void
    {
        $frites = $this->matiere('Portion frites');
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $tacos = $this->item('Tacos M');

        $order = $this->commande();
        $this->ligne($order, $tacos, 5, $this->viandes(['Viande Hachée'], [], [
            ['role' => 'menu_frites', 'quantity' => 1, 'addon_name' => 'Frites'],
            ['role' => 'menu_boisson', 'quantity' => 1, 'addon_name' => 'Coca 33cl'],
        ]));
        $this->consomme($order);

        $this->assertSame(-5.0, $this->onHand($frites), '5 menus = 5 portions de frites.');
        $this->assertSame(-750.0, $this->onHand($hachee));
    }

    // ── Garde-fous ────────────────────────────────────────────────────────────

    /**
     * Une matière comptée en GRAMMES sans poids unitaire ne doit PAS être consommée à zéro en
     * silence : le trou doit rester visible, sinon un rapport de coût afficherait sereinement 0.
     */
    public function test_une_matiere_en_grammes_sans_poids_est_signalee_et_non_consommee(): void
    {
        $sansPoids = $this->matiere('Mexicanos', 'g', null);
        $tacos = $this->item('Tacos M');

        $order = $this->commande();
        $this->ligne($order, $tacos, 1, $this->viandes(['Mexicanos']));
        $resultat = $this->consomme($order);

        $this->assertSame(0.0, $this->onHand($sansPoids), 'Aucune consommation inventée sans poids connu.');
        $this->assertNotEmpty(
            array_filter($resultat['skipped'] ?? [], static fn ($s) => ($s['kind'] ?? '') === 'portion_poids_unitaire_absent'),
            'Le manque de poids doit être remonté dans skipped[], pas absorbé.'
        );
    }

    /** Rejouer la même commande ne doit jamais décompter deux fois. */
    public function test_rejouer_la_commande_ne_double_jamais_la_consommation(): void
    {
        $hachee = $this->matiere('Viande hachée', 'g', 75);
        $tacos = $this->item('Tacos M');

        $order = $this->commande();
        $this->ligne($order, $tacos, 1, $this->viandes(['Viande Hachée']));

        $this->consomme($order);
        $apresPremier = $this->onHand($hachee);
        $this->consomme($order);

        $this->assertSame($apresPremier, $this->onHand($hachee), 'Un rejeu doit être sans effet : sinon chaque retry de file vide le stock.');
    }

    private function recetteProduit(Item $item, RawMaterial $mat, float $qty): void
    {
        RawMaterialRecipeLine::create([
            'branch_id' => 1, 'subject_type' => Item::class, 'subject_id' => $item->id,
            'subject_group' => null, 'raw_material_id' => $mat->id, 'qty' => $qty,
        ]);
    }
}
