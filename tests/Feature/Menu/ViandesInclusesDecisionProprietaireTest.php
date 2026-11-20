<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Http\Resources\NormalItemResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Kiosk\KioskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M5-001b M3-101 M5-001 M1-004] Nombre de viandes INCLUSES publié en `viande_count`, selon les
 * décisions du propriétaire du 2026-10-22 : Tacos M = 1, L = 2, XL = 3 ; Méga et Terminator = 2 ; sandwich (Cayenne,
 * Classique) = 1, la 2e viande passant par l'extra payant « Viande supplémentaire » ; bols, burgers, galettes = 1.
 *
 * La borne (KioskWizardComponent, GELÉE) exige EXACTEMENT `viande_count` viandes et les facture au prix de l'article :
 * un `viande_count` trop grand = des viandes imposées et données. Avant correctif, les deux projections comptaient
 * TOUS les attributs « Viande N » : Tacos M = 3 (vendu 6,90 € au lieu de 11,90 €), Cayenne = 2 (2e viande offerte).
 *
 * Le catalogue de test reproduit la base réelle du 2026-10-22 (avant la migration de données) : Viande 1/2 sur les
 * sandwichs, Viande 1/2/3 sur les trois Tacos.
 *
 * @group menu
 */
class ViandesInclusesDecisionProprietaireTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $attr = [];

    private const ATTENDU = [
        'Cayenne'            => 1,
        'Sandwich Classique' => 1,
        'Méga'               => 2,
        'Terminator'         => 2,
        'Suprême'            => 2,
        'Tacos M'            => 1,
        'Tacos L'            => 2,
        'Tacos XL'           => 3,
        'Bol Riz'            => 1,
        'Cheese Burger'      => 1,
        'Galette Cayenne'    => 1,
        'Tiramisu'           => 0,
    ];

    private function catalogue(): Branch
    {
        $branch = Branch::firstOrCreate(['id' => 1], [
            'name' => 'Filiale de test', 'email' => 't@example.test', 'phone' => '+33600000000', 'city' => 'Lille',
            'state' => 'Hauts-de-France', 'zip_code' => '59000', 'address' => '1 rue de Test', 'status' => Status::ACTIVE,
        ]);

        foreach (['Viande 1', 'Viande 2', 'Viande 3'] as $nom) {
            $this->attr[$nom] = DB::table('item_attributes')->insertGetId([
                'name' => $nom, 'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $cat = fn (string $nom) => ItemCategory::factory()->create(['name' => $nom, 'status' => Status::ACTIVE])->id;
        $sandwichs = $cat('Sandwichs');
        $tacos = $cat('Tacos');
        $bols = $cat('Bols');
        $burgers = $cat('Burgers');
        $galette = $cat('Galette');
        $desserts = $cat('Desserts');

        $plan = [
            ['Cayenne', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Sandwich Classique', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Méga', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Terminator', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Suprême', $sandwichs, ['Viande 1', 'Viande 2']],
            ['Tacos M', $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Tacos L', $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Tacos XL', $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Bol Riz', $bols, ['Viande 1']],
            ['Cheese Burger', $burgers, ['Viande 1']],
            ['Galette Cayenne', $galette, ['Viande 1']],
            ['Tiramisu', $desserts, []],
        ];

        foreach ($plan as [$nom, $categorie, $attributs]) {
            $item = Item::factory()->create(['name' => $nom, 'item_category_id' => $categorie, 'status' => Status::ACTIVE]);
            foreach ($attributs as $attribut) {
                foreach (['Poulet mariné', 'Cordon Bleu'] as $viande) {
                    DB::table('item_variations')->insert([
                        'item_id' => $item->id, 'item_attribute_id' => $this->attr[$attribut], 'name' => $viande,
                        'price' => 0, 'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        return $branch;
    }

    public function test_la_borne_recoit_le_nombre_de_viandes_decide_par_le_proprietaire(): void
    {
        $menu = app(KioskMenuService::class)->build($this->catalogue());
        $recu = collect($menu['items'])->pluck('viande_count', 'name')->all();

        foreach (self::ATTENDU as $nom => $attendu) {
            $this->assertSame($attendu, $recu[$nom] ?? null, "borne : « $nom » doit demander $attendu viande(s)");
        }
    }

    public function test_la_fiche_produit_du_site_et_de_la_caisse_publie_la_meme_valeur(): void
    {
        $this->catalogue();

        foreach (self::ATTENDU as $nom => $attendu) {
            $item = Item::query()->where('name', $nom)->firstOrFail();
            foreach ([['surface' => 'web'], ['surface' => 'kiosk'], []] as $query) {
                $payload = (new NormalItemResource($item->fresh()))->toArray(Request::create('/', 'GET', $query));
                $this->assertSame($attendu, $payload['viande_count'], "fiche « $nom » (".json_encode($query).')');
            }
        }
    }

    public function test_une_regle_ne_peut_pas_demander_plus_de_viandes_que_le_catalogue_n_en_porte(): void
    {
        // Tacos XL privé de son 3e emplacement : la borne ne doit pas exiger une 3e viande qu'elle ne saurait pas
        // placer sur la commande (elle la jetterait en silence, client servi de 2 viandes pour un XL).
        $this->catalogue();
        $xl = Item::query()->where('name', 'Tacos XL')->firstOrFail();
        DB::table('item_variations')->where('item_id', $xl->id)->where('item_attribute_id', $this->attr['Viande 3'])
            ->update(['status' => Status::INACTIVE]);

        $payload = (new NormalItemResource($xl->fresh()))->toArray(Request::create('/'));
        $this->assertSame(2, $payload['viande_count']);
    }
}
