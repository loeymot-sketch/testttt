<?php

namespace Tests\Feature\Menu;

use App\Console\Commands\EnsureTacosXl3ViandesCommand;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Rules\MultiVariationConstraint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER TACOS-XL 2026-08-24] Le tacos DEUX viandes passe à 8,90 € et le tacos TROIS viandes
 * entre en carte à 10,90 €, avec « toute la logique » des tacos existants.
 *
 * Ce que ces tests protègent, dans l'ordre d'importance :
 *   1. Les trois viandes sont COMPRISES dans le prix (variations à 0 €) — pas facturées deux fois.
 *   2. Les trois viandes sont OBLIGATOIRES côté serveur : une commande qui n'en porte que deux est
 *      REFUSÉE. C'est la seule barrière qui tienne si un client contourne le wizard.
 *   3. La personnalisation (sauce, suppléments, formule menu, recette matière) est identique à
 *      celle du Tacos L — le propriétaire a demandé « tout le reste de la logique ».
 *   4. La commande est ré-exécutable sans rien dupliquer (elle tourne aussi en migration).
 */
class TacosXl3ViandesCommandTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $tacos;

    private ItemAttribute $viande1;

    private ItemAttribute $viande2;

    private ItemAttribute $sauce;

    private Item $tacosL;

    /** Les 7 viandes de la carte Le Cayenne. */
    private const MEATS = [
        'Mexicanos', 'Cordon Bleu', 'Viande Hachée', 'Nuggets',
        'Tenders', 'Fricadelle', 'Poulet mariné',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tacos = ItemCategory::factory()->create(['name' => 'Tacos', 'slug' => 'tacos']);
        $this->viande1 = ItemAttribute::factory()->create(['name' => 'Viande 1', 'min_select' => 1, 'max_select' => 1]);
        $this->viande2 = ItemAttribute::factory()->create(['name' => 'Viande 2', 'min_select' => 1, 'max_select' => 1]);
        $this->sauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)', 'min_select' => 1, 'max_select' => 1]);

        // Le gabarit : le tacos DEUX viandes tel qu'il est en base aujourd'hui (7,90 €).
        $this->tacosL = Item::factory()->create([
            'name' => 'Tacos L',
            'slug' => 'tacos-l',
            'price' => 7.90,
            'description' => 'Galette de blé, 2 viandes au choix, frites maison et sauce.',
            'item_category_id' => $this->tacos->id,
            'status' => Status::ACTIVE,
            'is_available' => 1,
            'order' => 1,
        ]);

        foreach ([$this->viande1->id, $this->viande2->id] as $attrId) {
            foreach (self::MEATS as $meat) {
                $this->variation($this->tacosL->id, $attrId, $meat, 0.0);
            }
        }
        foreach (['Mayonnaise', 'Ketchup', 'Blanche'] as $s) {
            $this->variation($this->tacosL->id, $this->sauce->id, $s, 0.0);
        }

        $this->extra($this->tacosL->id, 'Cheddar', 0.90, 'supplement');
        $this->extra($this->tacosL->id, 'Viande supplémentaire', 2.50, 'supplement');
        $this->extra($this->tacosL->id, 'Sauce supplémentaire', 0.50, 'sauce');

        $formule = Item::factory()->create(['name' => 'Menu (Frites + Boisson)', 'status' => Status::ACTIVE]);
        DB::table('item_addons')->insert([
            'item_id' => $this->tacosL->id, 'addon_item_id' => $formule->id,
            'addon_item_variation' => null, 'role' => 'menu_component',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $galette = DB::table('raw_materials')->insertGetId([
            'branch_id' => 1, 'name' => 'Galette', 'unit' => 'piece', 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('raw_material_recipe_lines')->insert([
            'branch_id' => 1, 'subject_type' => Item::class, 'subject_id' => $this->tacosL->id,
            'subject_group' => null, 'raw_material_id' => $galette, 'qty' => 1, 'note' => 'galette',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Profil wizard porté par la CATÉGORIE, comme en production.
        $profile = ItemWizardProfile::create([
            'item_id' => null, 'item_category_id' => $this->tacos->id, 'template' => 'tacos',
            'version' => 1, 'is_published' => true, 'published_at' => now(),
        ]);
        $this->step($profile->id, 'viande', 'Viande 1', 1, 1);
        $this->step($profile->id, 'viande_2', 'Viande 2', 0, 2);
        $this->step($profile->id, 'sauce', 'Sauce (1ère Gratuite)', 1, 3);
        $this->step($profile->id, 'menu', '', 0, 4);
    }

    private function variation(int $itemId, int $attrId, string $name, float $price): int
    {
        return (int) DB::table('item_variations')->insertGetId([
            'item_id' => $itemId, 'item_attribute_id' => $attrId, 'name' => $name,
            'price' => $price, 'status' => Status::ACTIVE, 'visible_on' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function extra(int $itemId, string $name, float $price, string $group): void
    {
        DB::table('item_extras')->insert([
            'item_id' => $itemId, 'name' => $name, 'price' => $price, 'group_label' => $group,
            'status' => Status::ACTIVE, 'is_available' => 1, 'visible_on' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function step(int $profileId, string $key, string $ref, int $min, int $position): void
    {
        ItemWizardStep::create([
            'profile_id' => $profileId, 'step_key' => $key, 'label' => $key,
            'source_type' => $ref === '' ? 'addon' : 'item_attribute', 'source_ref' => $ref,
            'min_select' => $min, 'max_select' => 4, 'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'], 'stockable_choices' => false,
            'position' => $position, 'is_active' => true,
            'addon_role' => $ref === '' ? 'menu_component' : null,
        ]);
    }

    private function tacosXl(): Item
    {
        return Item::where('slug', EnsureTacosXl3ViandesCommand::TARGET_SLUG)->firstOrFail();
    }

    /** @return array<int, string> noms des variations actives sous cet attribut */
    private function choices(int $itemId, int $attrId): array
    {
        return DB::table('item_variations')
            ->where('item_id', $itemId)->where('item_attribute_id', $attrId)
            ->whereNull('deleted_at')->orderBy('name')->pluck('name')->all();
    }

    /** @test */
    public function le_tacos_deux_viandes_passe_a_8_90(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $this->assertEqualsWithDelta(
            8.90,
            (float) $this->tacosL->fresh()->price,
            0.001,
            'Demande owner 2026-08-24 : le tacos 2 viandes est à 8,90 €'
        );
    }

    /** @test */
    public function le_tacos_trois_viandes_entre_en_carte_a_10_90(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $xl = $this->tacosXl();

        $this->assertEqualsWithDelta(10.90, (float) $xl->price, 0.001, 'Le tacos 3 viandes est à 10,90 €');
        $this->assertSame('Tacos XL', $xl->name);
        $this->assertSame((int) $this->tacos->id, (int) $xl->item_category_id, 'Même catégorie que les autres tacos');
        $this->assertSame(Status::ACTIVE, (int) $xl->status, 'Le produit doit être vendable, pas en brouillon');
        $this->assertSame(1, (int) $xl->is_available);
        $this->assertTrue((bool) $xl->is_new, 'Owner : « c\'est un nouveau » — la borne affiche un bandeau NOUVEAU');
        $this->assertStringContainsString(
            '3 viandes au choix',
            (string) $xl->description,
            'La caisse relit « N viandes » dans la description en second recours (pos-wizard.js)'
        );
        $this->assertGreaterThan(
            (int) $this->tacosL->order,
            (int) $xl->order,
            'Il se range APRÈS le Tacos L dans la grille (M, L, XL)'
        );
    }

    /**
     * Le nom n'est pas décoratif : trois mécanismes (dont deux gelés) déduisent le nombre de
     * viandes du libellé. « XL » est la seule graphie que `pos-wizard.js::detectViandeCount`,
     * `kioskTacosSize.js` et le ticket cuisine lisent tous les trois comme « 3 ».
     *
     * @test
     */
    public function le_nom_est_celui_que_les_wizards_gelés_lisent_comme_trois_viandes(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $name = $this->tacosXl()->name;

        $this->assertMatchesRegularExpression('/tacos\s*xl\b/i', $name);
        $this->assertDoesNotMatchRegularExpression('/xxl/i', $name, '« XXL » serait lu comme 4 viandes');
    }

    /** @test */
    public function les_trois_viandes_sont_comprises_dans_le_prix(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $xl = $this->tacosXl();
        $viande3 = ItemAttribute::where('name', 'Viande 3')->firstOrFail();

        foreach ([$this->viande1->id, $this->viande2->id, $viande3->id] as $slot) {
            $this->assertSame(
                collect(self::MEATS)->sort()->values()->all(),
                $this->choices((int) $xl->id, (int) $slot),
                "Les 7 viandes doivent être proposées sur l'emplacement #{$slot}"
            );
        }

        $prices = DB::table('item_variations')
            ->where('item_id', $xl->id)
            ->whereIn('item_attribute_id', [$this->viande1->id, $this->viande2->id, $viande3->id])
            ->pluck('price')->map(fn ($p) => (float) $p)->unique()->all();

        $this->assertSame([0.0], array_values($prices),
            'Les 3 viandes sont COMPRISES dans les 10,90 € — aucune ne doit porter de prix');
    }

    /**
     * La caisse (`pos-wizard.js`, gelé) reporte les viandes choisies dans les listes déroulantes
     * du modal DANS L'ORDRE DU DOM, lui-même issu de l'ordre d'insertion des variations. Les
     * trois emplacements doivent donc rester contigus et AVANT la sauce : sinon ça marche encore,
     * mais par chance.
     *
     * @test
     */
    public function les_trois_emplacements_viande_precedent_la_sauce_dans_le_payload(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $viande3 = ItemAttribute::where('name', 'Viande 3')->firstOrFail();

        $order = DB::table('item_variations')
            ->where('item_id', $this->tacosXl()->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('item_attribute_id')
            ->unique()->values()->all();

        $this->assertSame(
            [(int) $this->viande1->id, (int) $this->viande2->id, (int) $viande3->id, (int) $this->sauce->id],
            array_map('intval', $order),
            'Viande 1 · Viande 2 · Viande 3, PUIS la sauce'
        );
    }

    /**
     * La barrière serveur. `MultiVariationConstraint` exige la présence de tout attribut à
     * `min_select >= 1` porté par l'article : c'est ce qui rend la 3ᵉ viande réellement
     * obligatoire, même si le wizard est contourné.
     *
     * @test
     */
    public function une_commande_a_deux_viandes_seulement_est_refusee(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $xl = $this->tacosXl();
        $viande3 = ItemAttribute::where('name', 'Viande 3')->firstOrFail();

        $this->assertSame(1, (int) $viande3->min_select, '« Viande 3 » doit être obligatoire');
        $this->assertSame(1, (int) $viande3->max_select, 'Un seul choix par emplacement');

        $pick = fn (int $attrId) => (int) DB::table('item_variations')
            ->where('item_id', $xl->id)->where('item_attribute_id', $attrId)
            ->orderBy('id')->value('id');

        $payloadFor = fn (array $attrIds) => [[
            'item_id' => (int) $xl->id,
            'item_variations' => array_map(fn ($a) => ['id' => $pick($a), 'quantity' => 1], $attrIds),
        ]];

        $errors = [];
        MultiVariationConstraint::validateCollectionKeyedByItemIndex(
            $payloadFor([$this->viande1->id, $this->viande2->id]),
            function (int $i, string $m) use (&$errors) { $errors[] = $m; }
        );
        $this->assertNotEmpty($errors, 'Un Tacos XL sans 3ᵉ viande doit être REFUSÉ par le serveur');

        $ok = [];
        MultiVariationConstraint::validateCollectionKeyedByItemIndex(
            $payloadFor([$this->viande1->id, $this->viande2->id, $viande3->id, $this->sauce->id]),
            function (int $i, string $m) use (&$ok) { $ok[] = $m; }
        );
        $this->assertSame([], $ok, 'Un Tacos XL complet (3 viandes + sauce) doit passer');
    }

    /** @test */
    public function la_personnalisation_est_identique_a_celle_du_tacos_l(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $xl = $this->tacosXl();

        $sauces = $this->choices((int) $xl->id, (int) $this->sauce->id);
        $this->assertSame(['Blanche', 'Ketchup', 'Mayonnaise'], $sauces, 'Mêmes sauces que le Tacos L');

        $extras = DB::table('item_extras')->where('item_id', $xl->id)
            ->orderBy('name')->get(['name', 'price', 'group_label']);
        $this->assertSame(
            ['Cheddar', 'Sauce supplémentaire', 'Viande supplémentaire'],
            $extras->pluck('name')->all(),
            'Suppléments et sauce supplémentaire repris à l\'identique'
        );
        $this->assertEqualsWithDelta(
            2.50,
            (float) $extras->firstWhere('name', 'Viande supplémentaire')->price,
            0.001,
            'Une QUATRIÈME viande reste payante @2,50 — les 3 premières sont comprises'
        );

        $this->assertSame(
            1,
            DB::table('item_addons')->where('item_id', $xl->id)->where('role', 'menu_component')->count(),
            'Sans formule clonée, le tacos ne pourrait pas être passé en menu à la caisse'
        );

        $this->assertSame(
            1,
            DB::table('raw_material_recipe_lines')
                ->where('subject_type', Item::class)->where('subject_id', $xl->id)->count(),
            'La recette matière première (galette, frites…) suit le produit'
        );
    }

    /** @test */
    public function la_troisieme_etape_viande_est_inseree_juste_apres_la_deuxieme(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $steps = ItemWizardStep::query()
            ->whereIn('profile_id', ItemWizardProfile::where('item_category_id', $this->tacos->id)->pluck('id'))
            ->orderBy('position')->pluck('step_key')->all();

        $this->assertSame(
            ['viande', 'viande_2', 'viande_3', 'sauce', 'menu'],
            $steps,
            'Le client choisit ses trois viandes d\'affilée, PUIS la sauce — pas la 3ᵉ en fin de parcours'
        );

        $v3 = ItemWizardStep::where('step_key', 'viande_3')->firstOrFail();
        $this->assertSame(
            0,
            (int) $v3->min_select,
            "L'étape reste à min 0 : le profil est porté par la CATÉGORIE, donc les Tacos M et L en héritent "
            .'sans porter l\'attribut. L\'obligation vit sur l\'attribut, pas sur l\'étape.'
        );
    }

    /**
     * Les Tacos M et L héritent du profil de catégorie : ils reçoivent donc l'étape `viande_3`.
     * Elle doit rester SANS effet pour eux — zéro choix, donc écartée à l'affichage, exactement
     * comme `viande_2` l'est déjà aujourd'hui pour le Tacos M.
     *
     * @test
     */
    public function la_troisieme_etape_reste_vide_pour_les_tacos_qui_nont_que_deux_viandes(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);

        $viande3 = ItemAttribute::where('name', 'Viande 3')->firstOrFail();

        $this->assertSame(
            [],
            $this->choices((int) $this->tacosL->id, (int) $viande3->id),
            'Le Tacos L ne doit gagner AUCUN 3ᵉ emplacement de viande'
        );
    }

    /** @test */
    public function la_commande_est_rejouable_sans_rien_dupliquer(): void
    {
        EnsureTacosXl3ViandesCommand::ensure(false);
        $first = $this->snapshotCounts();

        EnsureTacosXl3ViandesCommand::ensure(false);
        $second = $this->snapshotCounts();

        $this->assertSame($first, $second, 'Deuxième passage : aucune ligne en double (elle tourne aussi en migration)');
        $this->assertSame(1, Item::where('slug', 'tacos-xl')->count());
    }

    /** @return array<string, int> */
    private function snapshotCounts(): array
    {
        $xlId = (int) $this->tacosXl()->id;

        return [
            'variations' => DB::table('item_variations')->where('item_id', $xlId)->whereNull('deleted_at')->count(),
            'extras' => DB::table('item_extras')->where('item_id', $xlId)->whereNull('deleted_at')->count(),
            'addons' => DB::table('item_addons')->where('item_id', $xlId)->whereNull('deleted_at')->count(),
            'recipe' => DB::table('raw_material_recipe_lines')->where('subject_id', $xlId)->count(),
            'steps' => ItemWizardStep::where('step_key', 'viande_3')->count(),
        ];
    }

    /**
     * Base sans le menu Le Cayenne : mieux vaut ne rien faire que poser en carte un produit
     * deviné et à moitié câblé.
     *
     * @test
     */
    public function sans_gabarit_la_commande_ne_fabrique_rien(): void
    {
        $this->tacosL->delete(); // retiré de la carte

        $stats = EnsureTacosXl3ViandesCommand::ensure(false);

        $this->assertArrayHasKey('skipped', $stats);
        $this->assertSame(0, Item::where('slug', 'tacos-xl')->count());
    }
}
