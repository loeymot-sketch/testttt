<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Services\Stock\UnifiedStockViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ONB-11 2026-08-28] « Conso & Stock » exigeait que la catégorie s'appelle « Boisson ».
 *
 * `UnifiedStockViewService::resoldProductRows()` identifiait les produits REVENDUS
 * par un mot écrit en dur :
 *
 *     $query->where('slug', 'like', 'boisson%')->orWhere('name', 'like', 'Boisson%');
 *
 * Rien n'oblige un restaurateur à nommer sa catégorie ainsi. « Softs »,
 * « Canettes », « Bières » sont des noms parfaitement normaux, et rien à l'écran
 * ne les lui déconseille. Sa section « Produits revendus » restait alors VIDE,
 * sous le message « Aucun élément ne correspond au filtre » — alors qu'il n'avait
 * posé aucun filtre, et que les seuls filtres offerts (`all / to_buy / out / low`)
 * ne pouvaient pas expliquer ce vide. Un écran de valorisation de stock vide, et
 * la faute rejetée sur lui.
 *
 * ⏳ Le correctif reste imparfait, et il faut le dire : la bonne réponse serait un
 * attribut porté par la catégorie elle-même (« ceci est un produit revendu »), ce
 * qui demande une migration, donc le gate propriétaire G-DATA, en attente. Un
 * réglage par nom reste une convention de nommage — mais au moins l'exploitant
 * peut déclarer la sienne sans toucher au code.
 */
class CategorieRevendueEstReglableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `stock_levels.branch_id` porte une clé étrangère : la branche que le
        // service interroge doit exister avant qu'on y pose un niveau de stock.
        if (! \App\Models\Branch::query()->whereKey(UnifiedStockViewService::BRANCH_ID)->exists()) {
            \App\Models\Branch::factory()->create(['id' => UnifiedStockViewService::BRANCH_ID]);
        }
    }

    private function semerUneBoisson(string $nomDeCategorie): void
    {
        $taxe = Tax::factory()->create(['status' => Status::ACTIVE]);
        $categorie = ItemCategory::factory()->create([
            'name'   => $nomDeCategorie,
            'slug'   => \Illuminate\Support\Str::slug($nomDeCategorie),
            'status' => Status::ACTIVE,
        ]);

        $article = Item::factory()->create([
            'name'             => 'Coca 33cl',
            'item_category_id' => $categorie->id,
            'tax_id'           => $taxe->id,
            'status'           => Status::ACTIVE,
        ]);

        StockLevel::query()->create([
            'branch_id'      => UnifiedStockViewService::BRANCH_ID,
            'stockable_type' => Item::class,
            'stockable_id'   => $article->id,
            'quantity'       => 24,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function lignesRevendues(): array
    {
        $service = app(UnifiedStockViewService::class);

        $methode = new \ReflectionMethod($service, 'resoldProductRows');
        $methode->setAccessible(true);

        return $methode->invoke(
            $service,
            UnifiedStockViewService::BRANCH_ID,
            \Illuminate\Support\Carbon::now()->subDays(30)
        );
    }

    public function test_la_categorie_par_defaut_fonctionne_toujours(): void
    {
        // Contrôle négatif : rendre la règle réglable ne doit pas casser le cas
        // qui marchait déjà.
        $this->semerUneBoisson('Boissons');

        $this->assertNotEmpty(
            $this->lignesRevendues(),
            'La catégorie « Boissons » doit toujours être reconnue.'
        );
    }

    public function test_une_categorie_nommee_autrement_est_invisible_par_defaut(): void
    {
        // C'est le défaut, tel qu'il se présente à un commerçant qui n'a rien réglé.
        // On le documente plutôt que de le cacher : le réglage existe pour ça.
        $this->semerUneBoisson('Softs');

        $this->assertSame(
            [],
            $this->lignesRevendues(),
            "Sans réglage, « Softs » n'est pas reconnue — c'est attendu, et c'est\n"
            . 'exactement pourquoi le réglage devait exister.'
        );
    }

    public function test_le_reglage_rend_la_categorie_visible(): void
    {
        // LE CŒUR DU CORRECTIF. Avant, aucun réglage n'existait : le commerçant
        // n'avait AUCUN moyen de faire apparaître ses produits, sinon renommer sa
        // catégorie en devinant le mot attendu.
        $this->semerUneBoisson('Softs');

        config(['stock.categories_revendues' => ['boisson', 'soft']]);

        $lignes = $this->lignesRevendues();

        $this->assertNotEmpty(
            $lignes,
            "Déclarer « soft » dans `stock.categories_revendues` doit faire apparaître\n"
            . 'la catégorie. Si ce test échoue, le mot est resté écrit en dur.'
        );

        $this->assertSame('Coca 33cl', $lignes[0]['name'] ?? null);
    }

    public function test_la_reconnaissance_ignore_la_casse(): void
    {
        // « CANETTES », « Canettes », « canettes » : le même mot pour un restaurateur.
        $this->semerUneBoisson('CANETTES');

        config(['stock.categories_revendues' => ['canette']]);

        $this->assertNotEmpty($this->lignesRevendues());
    }

    public function test_plusieurs_noms_peuvent_cohabiter(): void
    {
        // Une carte peut avoir « Softs » ET « Bières » : les deux doivent remonter.
        $this->semerUneBoisson('Softs');
        $this->semerUneBoisson('Bieres');

        config(['stock.categories_revendues' => ['soft', 'biere']]);

        $this->assertCount(2, $this->lignesRevendues());
    }

    public function test_le_reglage_est_atteignable_depuis_un_deploiement(): void
    {
        // Même leçon que la borne SLA plus tôt dans cette session : un service qui
        // lit une clé de configuration ne prouve pas que la clé soit ATTEIGNABLE.
        // Le fichier doit exister, exposer la clé, et être branché sur une variable
        // d'environnement documentée.
        $this->assertFileExists(config_path('stock.php'));

        $this->assertNotNull(config('stock.categories_revendues'));

        $source = file_get_contents(config_path('stock.php'));
        $this->assertStringContainsString('STOCK_CATEGORIES_REVENDUES', $source);

        $this->assertStringContainsString(
            'STOCK_CATEGORIES_REVENDUES=',
            file_get_contents(base_path('.env.example')),
            "Un réglage qu'on ne découvre qu'en lisant le code source n'est pas un\n"
            . 'réglage offert au commerçant.'
        );
    }
}
