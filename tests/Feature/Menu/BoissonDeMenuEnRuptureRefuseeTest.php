<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Support\Menu\MenuFormulaChoiceAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M1-101] Une boisson (ou un accompagnement) de MENU en rupture est refusée, comme le même produit seul.
 *
 * Constat : Coca-Cola 33cl en rupture (item_branch_availability is_available = 0). Seul, il est refusé (422
 * « n'est plus disponible ») ; choisi comme boisson d'un menu, il passait (commande 8084 du site acceptée). La boisson
 * de formule voyage en TEXTE dans l'instruction de la ligne — « Boisson menu: X » (site), « BOISSON: X » (caisse),
 * « Formule : … (X) » (borne) — et aucun contrôle ne la lisait : le client payait un menu dont la boisson promise
 * n'existe pas, et le stock n'était pas décompté.
 *
 * @group menu
 */
class BoissonDeMenuEnRuptureRefuseeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    /** @var array<string, int> */
    private array $item = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $burgers = ItemCategory::factory()->create(['name' => 'Burgers', 'status' => Status::ACTIVE])->id;
        $boissons = ItemCategory::factory()->create(['name' => 'Boissons', 'status' => Status::ACTIVE])->id;
        foreach ([
            ['Cheese Burger', 7.50, $burgers, Status::ACTIVE],
            ['Coca-Cola 33cl', 1.90, $boissons, Status::ACTIVE],
            ['Fanta 33cl', 1.90, $boissons, Status::ACTIVE],
            ['Oasis Tropical 33cl', 1.90, $boissons, Status::INACTIVE],
            ['Potatoes', 2.50, $burgers, Status::ACTIVE],
        ] as [$nom, $prix, $categorie, $statut]) {
            $this->item[$nom] = Item::factory()->create([
                'name' => $nom, 'price' => $prix, 'item_category_id' => $categorie, 'status' => $statut, 'tax_id' => $tax->id,
            ])->id;
        }

        foreach (['Coca-Cola 33cl', 'Potatoes'] as $enRupture) {
            DB::table('item_branch_availability')->insert([
                'item_id' => $this->item[$enRupture], 'branch_id' => $this->branch->id, 'is_available' => 0,
                'unavailable_reason' => 'stock_rupture', 'daily_reset_at' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function ligne(string $instruction): array
    {
        return ['item_id' => $this->item['Cheese Burger'], 'quantity' => 1, 'item_variations' => [], 'item_extras' => [], 'instruction' => $instruction];
    }

    /** @return array<int, string> */
    private function refus(string $instruction): array
    {
        $out = [];
        MenuFormulaChoiceAvailability::validateCollectionKeyedByItemIndex([$this->ligne($instruction)], function (int $i, string $m) use (&$out): void {
            $out[$i] = $m;
        }, $this->branch->id);

        return $out;
    }

    public static function instructions(): array
    {
        return [
            'site, boisson en rupture' => ['Boisson menu: Coca-Cola 33cl · Sauce frites : Mayonnaise', 'Coca-Cola 33cl'],
            'caisse, boisson en rupture' => ["CHEESE BURGER\n+ Menu (Frites + Boisson) (+2,50 €)\nBOISSON: Coca-Cola 33cl", 'Coca-Cola 33cl'],
            'borne, formule en rupture' => ['Formule : Menu complet (frites + boisson) (Coca-Cola 33cl). Sauce frites : Algérienne', 'Coca-Cola 33cl'],
            'casse et accents différents' => ['boisson menu : coca-cola 33CL', 'Coca-Cola 33cl'],
            'boisson retirée de la carte' => ['BOISSON: Oasis Tropical 33cl', 'Oasis Tropical 33cl'],
            'accompagnement en rupture' => ['Accompagnement: Potatoes', 'Potatoes'],
            'boisson disponible' => ['Boisson menu: Fanta 33cl · Sauce frites : Mayonnaise', null],
            'formule sans boisson nommée' => ['Formule : Menu complet (frites + boisson). Sauce frites : Algérienne', null],
            'texte qui n’est pas un produit' => ['Boisson menu: Eau du robinet', null],
            'note client libre' => ['bien cuit, sans oignons', null],
        ];
    }

    /** @dataProvider instructions */
    public function test_la_validation_de_composition_lit_la_boisson_du_menu(string $instruction, ?string $refusee): void
    {
        $refus = $this->refus($instruction);

        if ($refusee === null) {
            $this->assertSame([], $refus);
        } else {
            $this->assertArrayHasKey(0, $refus, "« {$refusee} » de menu accepté");
            $this->assertStringContainsString("« {$refusee} »", $refus[0]);
            $this->assertStringContainsString("n'est plus disponible", $refus[0]);
        }
    }

    public function test_devis_caisse_et_borne_refusent_un_menu_dont_la_boisson_est_en_rupture(): void
    {
        $operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $kiosk = User::factory()->create(['branch_id' => $this->branch->id]);
        KioskMachine::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $kiosk->id]);

        foreach ([[$operator, '/api/admin/pos/quote'], [$kiosk, '/api/frontend/order/quote']] as [$acteur, $route]) {
            $corps = fn (string $instruction) => [
                'branch_id' => $this->branch->id, 'order_type' => 20, 'items' => json_encode([$this->ligne($instruction)]),
            ];

            $refus = $this->actingAs($acteur, 'sanctum')->postJson($route, $corps('Boisson menu: Coca-Cola 33cl'));
            $refus->assertStatus(422);
            $this->assertStringContainsString('« Coca-Cola 33cl »', (string) $refus->json('message'), $route);

            $this->actingAs($acteur, 'sanctum')->postJson($route, $corps('Boisson menu: Fanta 33cl'))->assertOk();
        }
    }
}
