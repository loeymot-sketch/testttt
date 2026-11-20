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
 * [VG-2026-10-21 M5-001 M1-004 M5-001b M3-101 · OWNER 2026-10-22] Données : la 2e viande d'un sandwich (Cayenne,
 * Classique) n'est plus jamais gratuite, et un Tacos M (resp. L) ne porte plus d'emplacement gratuit pour une 2e/3e
 * (resp. 3e) viande. PricingService (gelé) facture le prix de la VARIATION choisie : une variation « Viande 2 » à 0 €
 * offrait donc la 2e viande sur les trois canaux. La 2e viande passe désormais par l'extra « Viande supplémentaire »
 * (2,50 €), que la caisse, la borne et le site savent déjà sceller au-delà des viandes incluses.
 *
 * @group menu
 */
class ViandesInclusesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_22_130000_viandes_incluses_decision_proprietaire.php';

    /** @var array<string, int> */
    private array $attr = [];

    /** @var array<string, int> */
    private array $item = [];

    /** @var array<string, int> */
    private array $extra = [];

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    private function catalogue(bool $classiqueSansExtraPayant = false): void
    {
        foreach (['Viande 1', 'Viande 2', 'Viande 3'] as $nom) {
            $this->attr[$nom] = DB::table('item_attributes')->insertGetId([
                'name' => $nom, 'status' => Status::ACTIVE, 'min_select' => 0, 'max_select' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $sandwichs = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE])->id;
        $tacos = ItemCategory::factory()->create(['name' => 'Tacos', 'status' => Status::ACTIVE])->id;

        $plan = [
            ['Cayenne', 7.40, $sandwichs, ['Viande 1', 'Viande 2']],
            ['Sandwich Classique', 7.40, $sandwichs, ['Viande 1', 'Viande 2']],
            ['Méga', 8.00, $sandwichs, ['Viande 1', 'Viande 2']],
            ['Tacos M', 6.90, $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Tacos L', 8.90, $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
            ['Tacos XL', 10.90, $tacos, ['Viande 1', 'Viande 2', 'Viande 3']],
        ];

        foreach ($plan as [$nom, $prix, $categorie, $attributs]) {
            $id = Item::factory()->create([
                'name' => $nom, 'price' => $prix, 'item_category_id' => $categorie, 'status' => Status::ACTIVE, 'tax_id' => null,
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
            if (! ($classiqueSansExtraPayant && $nom === 'Sandwich Classique')) {
                $this->extra[$nom] = DB::table('item_extras')->insertGetId([
                    'item_id' => $id, 'name' => 'Viande supplémentaire', 'price' => 2.50, 'status' => Status::ACTIVE,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Profil wizard PUBLIÉ du produit : une étape par emplacement viande (comme la base réelle, n° 55/60/93/95/96).
            $profil = DB::table('item_wizard_profiles')->insertGetId([
                'item_id' => $id, 'template' => 'sandwich', 'version' => 1, 'is_published' => 1, 'published_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($attributs as $i => $attribut) {
                DB::table('item_wizard_steps')->insert([
                    'profile_id' => $profil, 'step_key' => $i === 0 ? 'viande' : 'viande_'.($i + 1), 'label' => $attribut,
                    'source_type' => 'item_attribute', 'source_ref' => (string) $this->attr[$attribut],
                    'source_item_attribute_id' => $this->attr[$attribut], 'min_select' => $i === 0 ? 1 : 0, 'max_select' => 1,
                    'position' => $i, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** @return array<string, int> « article/attribut » => nombre de variations ACTIVES */
    private function emplacementsActifs(): array
    {
        $out = [];
        foreach ($this->item as $nom => $id) {
            foreach ($this->attr as $attribut => $attrId) {
                $n = DB::table('item_variations')->where('item_id', $id)->where('item_attribute_id', $attrId)
                    ->where('status', Status::ACTIVE)->count();
                if ($n > 0) {
                    $out["$nom/$attribut"] = $n;
                }
            }
        }

        return $out;
    }

    /** @return array<string, int> « article/étape » => is_active */
    private function etapes(): array
    {
        return DB::table('item_wizard_steps')
            ->join('item_wizard_profiles', 'item_wizard_profiles.id', '=', 'item_wizard_steps.profile_id')
            ->join('items', 'items.id', '=', 'item_wizard_profiles.item_id')
            ->get(['items.name', 'item_wizard_steps.step_key', 'item_wizard_steps.is_active'])
            ->mapWithKeys(fn ($r) => [$r->name.'/'.$r->step_key => (int) $r->is_active])
            ->all();
    }

    public function test_les_emplacements_gratuits_en_trop_sont_retires_et_les_autres_intacts(): void
    {
        $this->catalogue();

        $this->migration()->up();

        $this->assertSame([
            'Cayenne/Viande 1'            => 2,
            'Sandwich Classique/Viande 1' => 2,
            'Méga/Viande 1'               => 2,
            'Méga/Viande 2'               => 2,
            'Tacos M/Viande 1'            => 2,
            'Tacos L/Viande 1'            => 2,
            'Tacos L/Viande 2'            => 2,
            'Tacos XL/Viande 1'           => 2,
            'Tacos XL/Viande 2'           => 2,
            'Tacos XL/Viande 3'           => 2,
        ], $this->emplacementsActifs());

        // L'étape du profil est éteinte avec ses variations : sinon la prochaine publication du profil
        // (WizardPageMaterializer) réactiverait la « Viande 2 » gratuite.
        $etapes = $this->etapes();
        $this->assertSame(0, $etapes['Cayenne/viande_2']);
        $this->assertSame(0, $etapes['Sandwich Classique/viande_2']);
        $this->assertSame(0, $etapes['Tacos M/viande_2']);
        $this->assertSame(0, $etapes['Tacos M/viande_3']);
        $this->assertSame(0, $etapes['Tacos L/viande_3']);
        $this->assertSame(1, $etapes['Tacos L/viande_2']);
        $this->assertSame(1, $etapes['Méga/viande_2']);
        $this->assertSame(1, $etapes['Tacos XL/viande_3']);
        $this->assertSame(1, $etapes['Cayenne/viande']);
    }

    public function test_idempotente_puis_reversible_a_l_identique(): void
    {
        $this->catalogue();
        $avant = [$this->emplacementsActifs(), $this->etapes()];

        $this->migration()->up();
        $apres = [$this->emplacementsActifs(), $this->etapes()];
        $journal = DB::table('migration_undo_log')->count();
        $this->assertGreaterThan(0, $journal);

        $this->migration()->up();
        $this->assertSame($apres, [$this->emplacementsActifs(), $this->etapes()], 'une 2e exécution ne change rien');
        $this->assertSame($journal, DB::table('migration_undo_log')->count(), 'ni le journal de retour arrière');

        $this->migration()->down();
        $this->assertSame($avant, [$this->emplacementsActifs(), $this->etapes()], 'la descente rend l’état d’avant');
        $this->assertFalse(Schema::hasTable('migration_undo_log'), 'journal vide supprimé');
    }

    public function test_un_sandwich_sans_extra_payant_n_est_pas_touche(): void
    {
        // Sans « Viande supplémentaire » payant, retirer la Viande 2 rendrait la 2e viande invendable : on s'abstient.
        $this->catalogue(classiqueSansExtraPayant: true);

        $this->migration()->up();

        $this->assertSame(2, $this->emplacementsActifs()['Sandwich Classique/Viande 2'] ?? 0);
        $this->assertSame(1, $this->etapes()['Sandwich Classique/viande_2']);
        $this->assertArrayNotHasKey('Cayenne/Viande 2', $this->emplacementsActifs());
    }

    public function test_le_devis_serveur_facture_la_deuxieme_viande_du_cayenne_2_50(): void
    {
        $this->catalogue();
        $branch = Branch::factory()->create();
        $cayenne = $this->item['Cayenne'];
        $v = fn (string $attribut, string $viande) => (int) DB::table('item_variations')
            ->where('item_id', $cayenne)->where('item_attribute_id', $this->attr[$attribut])->where('name', $viande)->value('id');
        $devis = fn (array $variations, array $extras = []) => (new PricingService())->calculateOrder(
            PricingRequest::forPos(0, $branch->id, [(object) [
                'item_id' => $cayenne, 'quantity' => 1,
                'item_variations' => array_map(fn ($id) => (object) ['id' => $id, 'quantity' => 1], $variations),
                'item_extras' => array_map(fn ($id) => (object) ['id' => $id, 'quantity' => 1], $extras),
            ]], 0, 0, 0.0, 0.0),
            app(CouponService::class)
        )->total;

        $base = $devis([$v('Viande 1', 'Poulet mariné')]);
        // AVANT : la 2e viande passée en « Viande 2 » est gratuite — c'est le défaut.
        $this->assertEqualsWithDelta($base, $devis([$v('Viande 1', 'Poulet mariné'), $v('Viande 2', 'Cordon Bleu')]), 0.001);

        $this->migration()->up();

        $this->assertEqualsWithDelta($base, $devis([$v('Viande 1', 'Poulet mariné')]), 0.001, 'une viande : prix inchangé');
        $this->assertEqualsWithDelta(
            $base + 2.50,
            $devis([$v('Viande 1', 'Poulet mariné')], [$this->extra['Cayenne']]),
            0.001,
            '2e viande = prix de base + 2,50 €'
        );

        // La porte gratuite est fermée côté serveur : un client périmé qui renverrait la « Viande 2 » est refusé.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inactive/');
        $devis([$v('Viande 1', 'Poulet mariné'), $v('Viande 2', 'Cordon Bleu')]);
    }
}
