<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RETROUVER UN LOT PAR LE CODE QUE LE CLIENT TIENT DANS SA MAIN.
 *
 * ── LE TROU QUE ÇA REFERME ───────────────────────────────────────────────────────────────────
 * [2026-08-13 · propriétaire : « valider le code promo au cas où, ou bien dans la caisse »]
 * L'écran de remise ne cherchait que par NUMÉRO. Or ce que le client présente au comptoir, c'est
 * son code — « ROUE-FLZ5EN ». Le seul objet que le jeu lui remet était le seul avec lequel
 * l'équipe ne pouvait rien faire.
 *
 * ── CE QUE CE BANC PROTÈGE ───────────────────────────────────────────────────────────────────
 * Ouvrir une SECONDE porte d'entrée sur un écran qui distribue des lots, c'est risquer de ne pas
 * lui remettre les serrures de la première. Trois d'entre elles comptent :
 *   · la CAISSE — un code gagné ailleurs ne se remet pas ici ;
 *   · l'ABSENCE de recherche approximative — sur des codes courts, un `LIKE` ferait d'une saisie
 *     partielle la clé du lot d'un autre client ;
 *   · le CODE RÉVOQUÉ — un coupon supprimé ne doit rien retrouver.
 */
class WheelFindByCodeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branche = Branch::factory()->create();
        Config::set('wheel.counter_branch_id', $this->branche->id);
    }

    /** Un tour porteur d'un coupon, dans la caisse demandée. Rend l'identifiant du tour. */
    private function tourAvecCode(string $code, ?int $branchId = null, bool $revoque = false): int
    {
        $coupon = Coupon::withoutGlobalScope(BranchScope::class)->create([
            'branch_id' => $branchId ?? $this->branche->id,
            'name' => 'Lot roue',
            'code' => $code,
            'discount_type' => 'amount',
            'discount' => 0,
            'status' => 1,
        ]);

        if ($revoque) {
            $coupon->delete(); // suppression douce — le code existe encore en base, mais il est mort
        }

        return (int) DB::table('wheel_spins')->insertGetId([
            'branch_id' => $branchId ?? $this->branche->id,
            'phone' => '0612345678',
            'prize_key' => 'boisson',
            'prize_label' => 'Boisson',
            'prize_type' => 'free_item',
            'prize_value' => 0,
            'coupon_id' => $coupon->id,
            'campaign_key' => 'test-campagne',
            'unlock_method' => 'review',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_le_code_exact_retrouve_le_tour(): void
    {
        $id = $this->tourAvecCode('ROUE-FLZ5EN');

        $trouve = WheelSpin::parCode($this->branche->id, 'ROUE-FLZ5EN');

        $this->assertNotNull($trouve, 'le code exact ne retrouve rien');
        $this->assertSame($id, (int) $trouve->id);
    }

    /**
     * LA SAISIE HUMAINE, DEBOUT, PENDANT UN SERVICE. Minuscules, espaces collés, préfixe oublié :
     * quelqu'un qui lit son code à voix haute dit « FLZ5EN » aussi souvent que le tout.
     *
     * @dataProvider saisiesTolerees
     */
    public function test_les_saisies_humaines_sont_tolerees(string $saisie): void
    {
        $id = $this->tourAvecCode('ROUE-FLZ5EN');

        $trouve = WheelSpin::parCode($this->branche->id, $saisie);

        $this->assertNotNull($trouve, "la saisie « {$saisie} » ne retrouve rien");
        $this->assertSame($id, (int) $trouve->id);
    }

    public static function saisiesTolerees(): array
    {
        return [
            'minuscules' => ['roue-flz5en'],
            'sans le préfixe' => ['FLZ5EN'],
            'sans préfixe et en minuscules' => ['flz5en'],
            'avec des espaces' => ['  ROUE-FLZ5EN  '],
            'espace au milieu' => ['ROUE- FLZ5EN'],
        ];
    }

    /**
     * L'ISOLATION DE CAISSE — la serrure qu'il aurait été le plus facile d'oublier en ajoutant
     * cette seconde porte. Le code existe, il est valide, il a un tour : mais pas ici.
     */
    public function test_un_code_d_une_autre_caisse_ne_retrouve_rien(): void
    {
        $autre = Branch::factory()->create();
        $this->tourAvecCode('ROUE-AILLEURS', $autre->id);

        $this->assertNull(WheelSpin::parCode($this->branche->id, 'ROUE-AILLEURS'),
            "un lot gagné dans une autre caisse a été retrouvé ici");
    }

    /**
     * PAS DE RECHERCHE APPROXIMATIVE. Une saisie partielle ne doit RIEN rendre — surtout pas le
     * lot du voisin. C'est la différence entre une aide à la saisie et une remise au mauvais
     * client.
     */
    public function test_une_saisie_partielle_ne_retrouve_rien(): void
    {
        $this->tourAvecCode('ROUE-FLZ5EN');

        $this->assertNull(WheelSpin::parCode($this->branche->id, 'FLZ5'),
            'une saisie partielle a retrouvé un lot : la recherche est approximative');
        $this->assertNull(WheelSpin::parCode($this->branche->id, 'ROUE-'),
            'le seul préfixe a retrouvé un lot');
    }

    /** Un code révoqué est mort : il ne doit rien ouvrir au comptoir. */
    public function test_un_code_revoque_ne_retrouve_rien(): void
    {
        $this->tourAvecCode('ROUE-MORT01', null, true);

        $this->assertNull(WheelSpin::parCode($this->branche->id, 'ROUE-MORT01'),
            'un coupon supprimé a quand même retrouvé son tour');
    }

    /** Une saisie vide ou faite de ponctuation ne doit pas partir en base. */
    public function test_une_saisie_vide_ne_cherche_rien(): void
    {
        $this->tourAvecCode('ROUE-FLZ5EN');

        $this->assertNull(WheelSpin::parCode($this->branche->id, ''));
        $this->assertNull(WheelSpin::parCode($this->branche->id, '   '));
        $this->assertNull(WheelSpin::parCode($this->branche->id, '!!!'));
    }

    /** L'écran doit répondre, et dire honnêtement qu'il n'a rien trouvé plutôt que de se taire. */
    public function test_l_ecran_cherche_par_code_et_le_dit_quand_il_ne_trouve_pas(): void
    {
        Config::set('wheel.access.pin', '481526');
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $navigateur = $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml']);
        $navigateur->post('/admin/roue/ouvrir', ['pin' => '481526']);

        $navigateur->get('/admin/roue-lot?code=ROUE-INEXISTANT')
            ->assertOk()
            ->assertSee('Aucun lot ne correspond à ce code', false);
    }
}
