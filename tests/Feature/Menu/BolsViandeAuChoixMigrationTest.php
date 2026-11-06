<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [VG-2026-10-21 R2-002] Un bol se vend AVEC sa viande, en caisse comme à la borne, au prix de la carte.
 *
 * Constat (base locale) : la fiche des bols dit « viande au choix », le catalogue porte 7 viandes actives sur
 * « Viande 1 », mais l'étape « viande » du profil wizard PUBLIÉ des bols (profil de catégorie et profils produit
 * recopiés le 2026-09-02) est ÉTEINTE. PricingService (gelé) fait foi du profil publié : une viande envoyée est
 * refusée « n'appartient pas au profil publié » (caisse : 422), et la borne, qui suit le profil, vend le bol SANS
 * viande. Zéro bol vendu depuis le 2026-09-03.
 *
 * @group menu
 */
class BolsViandeAuChoixMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_22_180000_bols_viande_au_choix_dans_le_profil_publie.php';

    /** @var array<string, int> */
    private array $attr = [];

    /** @var array<string, int> */
    private array $item = [];

    private int $bolsCategory = 0;

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function catalogue(bool $bolRizSansViandeActive = false): void
    {
        foreach (['Viande 1' => 1, 'Viande 2' => 0, 'Sauce bol' => 1] as $nom => $min) {
            $this->attr[$nom] = DB::table('item_attributes')->insertGetId([
                'name' => $nom, 'status' => Status::ACTIVE, 'min_select' => $min, 'max_select' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->bolsCategory = ItemCategory::factory()->create(['name' => 'Bols', 'status' => Status::ACTIVE])->id;
        $tacos = ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE])->id;

        // Profil de CATÉGORIE publié des bols (comme le n° 39 local) : viande éteinte, et — comme en base — NON liée à
        // son attribut (source_ref vide : la liaison n'est posée qu'aux étapes actives) mais reliée à sa page wizard.
        $this->profil(null, $this->bolsCategory, [['viande', 'Viande 1', 0], ['sauce_bol', 'Sauce bol', 1]], unbound: true);

        foreach ([['Bol Frites', $this->bolsCategory], ['Bol Riz', $this->bolsCategory], ['Tacos L', $tacos]] as [$nom, $categorie]) {
            $id = Item::factory()->create([
                'name' => $nom, 'price' => $nom === 'Tacos L' ? 8.90 : 7.90, 'item_category_id' => $categorie,
                'status' => Status::ACTIVE, 'tax_id' => null,
            ])->id;
            $this->item[$nom] = $id;
            $statutViande = ($bolRizSansViandeActive && $nom === 'Bol Riz') ? Status::INACTIVE : Status::ACTIVE;
            foreach (['Mexicanos', 'Poulet mariné'] as $viande) {
                $this->variation($id, 'Viande 1', $viande, $statutViande);
            }
            $this->variation($id, 'Sauce bol', 'Ketchup', Status::ACTIVE);

            if ($nom === 'Tacos L') {
                $this->variation($id, 'Viande 2', 'Cordon Bleu', Status::ACTIVE);
                // Hors périmètre : une étape éteinte sur un autre article ne doit pas être rallumée.
                $this->profil($id, null, [['viande', 'Viande 1', 1], ['viande_2', 'Viande 2', 0], ['sauce_bol', 'Sauce bol', 1]]);
            } else {
                // Profils PRODUIT publiés des bols (comme 77/81) : recopie de la catégorie, viande éteinte.
                $this->profil($id, null, [['viande', 'Viande 1', 0], ['sauce_bol', 'Sauce bol', 1]]);
            }
        }
    }

    private function variation(int $itemId, string $attribut, string $nom, int $status): int
    {
        return DB::table('item_variations')->insertGetId([
            'item_id' => $itemId, 'item_attribute_id' => $this->attr[$attribut], 'name' => $nom, 'price' => 0,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<int, array{0: string, 1: string, 2: int}> $etapes */
    private function profil(?int $itemId, ?int $categoryId, array $etapes, bool $unbound = false): void
    {
        $profil = DB::table('item_wizard_profiles')->insertGetId([
            'item_id' => $itemId, 'item_category_id' => $categoryId, 'template' => 'sandwich', 'version' => 1,
            'is_published' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($etapes as $i => [$cle, $attribut, $actif]) {
            DB::table('item_wizard_steps')->insert([
                'profile_id' => $profil, 'step_key' => $cle, 'label' => $attribut, 'source_type' => 'item_attribute',
                'source_ref' => ($unbound && ! $actif) ? '' : (string) $this->attr[$attribut],
                'source_item_attribute_id' => ($unbound && ! $actif) ? null : $this->attr[$attribut],
                'wizard_page_id' => $this->page($attribut),
                'min_select' => $cle === 'viande_2' ? 0 : 1, 'max_select' => 1, 'visible_on' => json_encode(['pos', 'kiosk']),
                'position' => $i, 'is_active' => $actif, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @var array<string, int> */
    private array $pages = [];

    private function page(string $attribut): int
    {
        return $this->pages[$attribut] ??= DB::table('wizard_pages')->insertGetId([
            'key' => mb_strtolower(str_replace(' ', '_', $attribut)), 'label' => $attribut, 'kind' => 'generic',
            'source_type' => 'item_attribute', 'item_attribute_id' => $this->attr[$attribut], 'min_select' => 1, 'max_select' => 1,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, int> « article|catégorie/étape » => is_active */
    private function etapes(): array
    {
        return DB::table('item_wizard_steps')
            ->join('item_wizard_profiles', 'item_wizard_profiles.id', '=', 'item_wizard_steps.profile_id')
            ->leftJoin('items', 'items.id', '=', 'item_wizard_profiles.item_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'item_wizard_profiles.item_category_id')
            ->get(['items.name as item', 'item_categories.name as cat', 'item_wizard_steps.step_key', 'item_wizard_steps.is_active'])
            ->mapWithKeys(fn ($r) => [($r->item ?? 'catégorie '.$r->cat).'/'.$r->step_key => (int) $r->is_active])
            ->all();
    }

    private function devis(string $surface, string $bol, array $variationNames): float
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();
        $ids = [];
        foreach ($variationNames as $nom) {
            $ids[] = (int) DB::table('item_variations')->where('item_id', $this->item[$bol])->where('name', $nom)->value('id');
        }
        $lines = [(object) [
            'item_id' => $this->item[$bol], 'quantity' => 1,
            'item_variations' => array_map(fn ($id) => (object) ['id' => $id, 'quantity' => 1], $ids),
            'item_extras' => [], 'item_addons' => [],
        ]];
        $req = $surface === 'kiosk'
            ? PricingRequest::forKiosk(0, $branch->id, $lines, 0, 0, 0.0)
            : PricingRequest::forPos(0, $branch->id, $lines, 0, 0, 0.0, 0.0);

        return (new PricingService())->calculateOrder($req, app(CouponService::class))->total;
    }

    public function test_avant_la_migration_un_bol_avec_sa_viande_est_invendable(): void
    {
        $this->catalogue();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/profil publié/');
        $this->devis('pos', 'Bol Frites', ['Mexicanos', 'Ketchup']);
    }

    public function test_un_bol_se_vend_avec_sa_viande_en_caisse_et_a_la_borne_au_prix_de_la_carte(): void
    {
        $this->catalogue();

        $this->migration()->up();

        foreach (['pos', 'kiosk'] as $surface) {
            $this->assertEqualsWithDelta(7.90, $this->devis($surface, 'Bol Frites', ['Mexicanos', 'Ketchup']), 0.001, $surface);
            $this->assertEqualsWithDelta(7.90, $this->devis($surface, 'Bol Riz', ['Poulet mariné', 'Ketchup']), 0.001, $surface);
        }

        $etapes = $this->etapes();
        $this->assertSame(1, $etapes['Bol Frites/viande']);
        $this->assertSame(1, $etapes['Bol Riz/viande']);
        $this->assertSame(1, $etapes['catégorie Bols/viande'], 'sinon la prochaine publication de la catégorie l’éteindrait de nouveau');
        $this->assertSame(0, $etapes['Tacos L/viande_2'], 'un autre article n’est pas touché');
    }

    public function test_apres_la_migration_un_bol_sans_viande_est_refuse(): void
    {
        $this->catalogue();
        $this->migration()->up();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/minimum 1/');
        $this->devis('kiosk', 'Bol Frites', ['Ketchup']);
    }

    public function test_idempotente_puis_reversible_a_l_identique(): void
    {
        $this->catalogue();
        $avant = $this->etapes();

        $this->migration()->up();
        $apres = $this->etapes();
        $journal = DB::table('migration_undo_log')->count();
        $this->assertSame(3, $journal, 'Bol Frites, Bol Riz et la catégorie Bols');

        $this->migration()->up();
        $this->assertSame($apres, $this->etapes(), 'une 2e exécution ne change rien');
        $this->assertSame($journal, DB::table('migration_undo_log')->count());

        $this->migration()->down();
        $this->assertSame($avant, $this->etapes(), 'la descente rend l’état d’avant');
        $this->assertFalse(Schema::hasTable('migration_undo_log'), 'journal vide supprimé');
    }

    public function test_un_bol_sans_viande_active_au_catalogue_n_est_pas_touche(): void
    {
        // Allumer l'étape sans aucune viande à proposer rendrait le bol invendable : on s'abstient.
        $this->catalogue(bolRizSansViandeActive: true);

        $this->migration()->up();

        $etapes = $this->etapes();
        $this->assertSame(0, $etapes['Bol Riz/viande']);
        $this->assertSame(1, $etapes['Bol Frites/viande']);
    }
}
