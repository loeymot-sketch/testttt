<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Rules\MultiVariationConstraint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [VG-2026-10-21 R2-005 · OWNER 2026-10-22] Le serveur impose le nombre de viandes INCLUSES décidé par le propriétaire
 * (config/menu_meat_rules.php, calcul unique App\Support\Menu\IncludedMeatCount) : Méga et Terminator = 2,
 * Tacos M = 1, L = 2, XL = 3, sandwich = 1.
 *
 * Avant : « Viande 2 » est facultative (min_select 0) au catalogue comme au profil — un Méga à 1 viande passait (200,
 * 8,00 €), un Tacos L à 1 viande aussi, un Tacos XL à 2 viandes aussi, sur la caisse comme à la borne. La borne (gelée)
 * exige exactement `viande_count`, la caisse et un client forgé non : la décision du propriétaire n'était pas un
 * invariant serveur. La garde vit dans la validation de composition (MultiVariationConstraint, partagée par le devis,
 * la caisse, la borne et le site), jamais dans PricingService (gelé).
 */
class ViandesIncluesImposeesParLeServeurTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $attr = [];

    /** @var array<string, int> */
    private array $item = [];

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->catalogue();
    }

    private function catalogue(): void
    {
        foreach (['Viande 1' => 1, 'Viande 2' => 0, 'Viande 3' => 0, 'Sauce' => 0] as $nom => $min) {
            $this->attr[$nom] = DB::table('item_attributes')->insertGetId([
                'name' => $nom, 'status' => Status::ACTIVE, 'min_select' => $min, 'max_select' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $cat = fn (string $n) => ItemCategory::factory()->create(['name' => $n, 'status' => Status::ACTIVE])->id;
        $sandwichs = $cat('Sandwichs');
        $tacos = $cat('Tacos');
        $burgers = $cat('Burgers');
        $boissons = $cat('Boissons');

        $plan = [
            ['Méga', 8.00, $sandwichs, ['Viande 1', 'Viande 2']],
            ['Terminator', 9.00, $sandwichs, ['Viande 1', 'Viande 2']],
            ['Cayenne', 7.40, $sandwichs, ['Viande 1']],
            ['Tacos M', 6.90, $tacos, ['Viande 1']],
            ['Tacos L', 8.90, $tacos, ['Viande 1', 'Viande 2']],
            ['Tacos XL', 10.90, $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Cheese Burger', 7.50, $burgers, ['Viande 1']],
            ['Coca-Cola 33cl', 1.90, $boissons, []],
        ];
        foreach ($plan as [$nom, $prix, $categorie, $attributs]) {
            $id = Item::factory()->create([
                'name' => $nom, 'price' => $prix, 'item_category_id' => $categorie, 'status' => Status::ACTIVE, 'tax_id' => $tax->id,
            ])->id;
            $this->item[$nom] = $id;
            foreach ($attributs as $attribut) {
                foreach (['Poulet mariné', 'Cordon Bleu'] as $viande) {
                    DB::table('item_variations')->insert([
                        'item_id' => $id, 'item_attribute_id' => $this->attr[$attribut], 'name' => $viande, 'price' => 0,
                        'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /** Une viande (Poulet mariné) par attribut « Viande N » cité. */
    private function ligne(string $article, array $attributs): array
    {
        $vars = [];
        foreach ($attributs as $attribut) {
            $vars[] = ['id' => (int) DB::table('item_variations')->where('item_id', $this->item[$article])
                ->where('item_attribute_id', $this->attr[$attribut])->where('name', 'Poulet mariné')->value('id'), 'quantity' => 1];
        }

        return ['item_id' => $this->item[$article], 'quantity' => 1, 'item_variations' => $vars, 'item_extras' => []];
    }

    /** @return array<int, string> messages de refus, par ligne */
    private function refus(array $lignes): array
    {
        $out = [];
        MultiVariationConstraint::validateCollectionKeyedByItemIndex($lignes, function (int $i, string $m) use (&$out): void {
            $out[$i] = ($out[$i] ?? '').$m;
        }, $this->branch->id);

        return $out;
    }

    public static function compositions(): array
    {
        return [
            'Méga 1 viande' => ['Méga', ['Viande 1'], false],
            'Méga 2 viandes' => ['Méga', ['Viande 1', 'Viande 2'], true],
            'Terminator 1 viande' => ['Terminator', ['Viande 1'], false],
            'Terminator 2 viandes' => ['Terminator', ['Viande 1', 'Viande 2'], true],
            'Tacos M 1 viande' => ['Tacos M', ['Viande 1'], true],
            'Tacos L 1 viande' => ['Tacos L', ['Viande 1'], false],
            'Tacos L 2 viandes' => ['Tacos L', ['Viande 1', 'Viande 2'], true],
            'Tacos XL viande 1 + viande 3' => ['Tacos XL', ['Viande 1', 'Viande 3'], false],
            'Tacos XL 3 viandes' => ['Tacos XL', ['Viande 1', 'Viande 2', 'Viande 3'], true],
            'Cayenne 1 viande' => ['Cayenne', ['Viande 1'], true],
            'Burger sans règle 1 viande' => ['Cheese Burger', ['Viande 1'], true],
            'Boisson sans viande' => ['Coca-Cola 33cl', [], true],
        ];
    }

    /** @dataProvider compositions */
    public function test_la_validation_de_composition_impose_les_viandes_incluses(string $article, array $attributs, bool $accepte): void
    {
        $refus = $this->refus([$this->ligne($article, $attributs)]);

        if ($accepte) {
            $this->assertSame([], $refus, "{$article} légitime refusé");
        } else {
            $this->assertArrayHasKey(0, $refus, "{$article} à ".count($attributs).' viande(s) accepté');
            $this->assertStringContainsString("« {$article} »", $refus[0]);
            $this->assertStringContainsString('viandes', $refus[0]);
        }
    }

    public function test_devis_caisse_et_borne_refusent_un_mega_a_une_viande_et_acceptent_deux(): void
    {
        $operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $kiosk = User::factory()->create(['branch_id' => $this->branch->id]);
        KioskMachine::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $kiosk->id]);

        foreach ([[$operator, '/api/admin/pos/quote'], [$kiosk, '/api/frontend/order/quote']] as [$acteur, $route]) {
            $corps = fn (array $attributs) => [
                'branch_id' => $this->branch->id, 'order_type' => 20,
                'items' => json_encode([$this->ligne('Méga', $attributs)]),
            ];

            $refus = $this->actingAs($acteur, 'sanctum')->postJson($route, $corps(['Viande 1']));
            $refus->assertStatus(422);
            $this->assertStringContainsString('« Méga » comprend 2 viandes', (string) $refus->json('message'), $route);

            $this->actingAs($acteur, 'sanctum')->postJson($route, $corps(['Viande 1', 'Viande 2']))
                ->assertOk()
                ->assertJsonPath('data.total_ttc', 8);
        }
    }
}
