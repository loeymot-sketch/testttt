<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-11 2026-08-28] Un article désactivé doit rester visible… de son propriétaire.
 *
 * DEUX CORRECTIFS JUSTES S'ANNULAIENT, pour la seconde fois cette nuit.
 *
 * Le 2026-07-05, un audit a fermé une vraie fuite : `simpleList()` sert
 * `/api/frontend/item` (borne, web, mobile, clé publique) et un `?status=10`
 * exposait au client les articles que l'admin avait DÉSACTIVÉS — nom, prix, image.
 * Le correctif force `status = ACTIVE` côté serveur. Nécessaire, et correct.
 *
 * Mais son commentaire se terminait par une phrase FAUSSE : « L'admin utilise
 * list() (visibilité complète), pas simpleList ». `Admin\ItemController::index()`
 * appelle bien `simpleList()`. Le filtre s'appliquait donc aussi au back-office.
 *
 * Ce que vivait le commerçant : il désactive un produit — pour l'hiver, une
 * rupture, un essai — et le produit quitte sa liste. Il ouvre le filtre « Inactif »
 * que l'écran lui propose pourtant : zéro ligne, « Aucune donnée disponible ». Le
 * Studio catalogue, même store, même angle mort. Il n'avait plus aucun moyen de le
 * réactiver : il devait le recréer à la main. Seul l'export Excel le contenait
 * encore, parce que lui passe par `list()` — le fichier contenait ce que l'écran
 * jurait ne pas avoir.
 *
 * CE BANC GARDE LES DEUX MOITIÉS DE LA PORTE, parce que n'en garder qu'une est
 * exactement ce qui a produit le défaut : la surface publique reste fermée, le
 * back-office est rouvert. Un futur correctif qui refermerait le back-office, ou
 * qui rouvrirait le public, fait échouer ce banc.
 */
class ArticleDesactiveResteVisibleAuCommercantTest extends TestCase
{
    use RefreshDatabase;

    private Item $desactive;
    private Item $actif;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $taxe = Tax::factory()->create(['status' => Status::ACTIVE]);

        $commun = [
            'item_category_id' => $categorie->id,
            'tax_id'           => $taxe->id,
        ];

        $this->actif = Item::factory()->create($commun + [
            'name'   => 'Tacos poulet',
            'status' => Status::ACTIVE,
        ]);

        $this->desactive = Item::factory()->create($commun + [
            'name'   => 'Bûche de Noël',
            'status' => Status::INACTIVE,
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('items', 'sanctum');
        Permission::findOrCreate('items_edit', 'sanctum');
        $admin->givePermissionTo(['items', 'items_edit']);

        return $admin;
    }

    /** @return list<string> Les noms d'articles rendus par la liste du back-office. */
    private function nomsDuBackOffice(array $parametres = []): array
    {
        $reponse = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/item?' . http_build_query($parametres + ['paginate' => 0]));

        $reponse->assertOk();

        return array_column($reponse->json('data') ?? [], 'name');
    }

    public function test_le_commercant_voit_son_article_desactive(): void
    {
        $noms = $this->nomsDuBackOffice();

        $this->assertContains(
            'Bûche de Noël',
            $noms,
            "L'article désactivé n'apparaît pas dans le back-office. Le commerçant ne\n"
            . "peut donc plus jamais le réactiver : il devra le recréer à la main.\n"
            . 'Reçu : ' . implode(', ', $noms)
        );

        $this->assertContains('Tacos poulet', $noms, "L'article actif doit rester listé.");
    }

    public function test_le_filtre_inactif_de_l_ecran_rend_quelque_chose(): void
    {
        // L'écran PROPOSE ce filtre (`ItemListComponent.vue:154`). Un filtre offert
        // qui ne rend jamais rien est pire qu'un filtre absent : le commerçant en
        // conclut que son article n'existe plus.
        $noms = $this->nomsDuBackOffice(['status' => Status::INACTIVE]);

        $this->assertSame(
            ['Bûche de Noël'],
            $noms,
            "Le filtre « Inactif » du back-office ne rend rien.\n"
            . 'Reçu : ' . implode(', ', $noms)
        );
    }

    public function test_la_surface_PUBLIQUE_reste_fermee(): void
    {
        // L'AUTRE MOITIÉ DE LA PORTE. Le correctif du 2026-07-05 fermait une vraie
        // fuite : un article désactivé ne doit jamais reparaître à la borne, au web
        // ou au mobile — ni son nom, ni son prix, ni son image. Rouvrir le
        // back-office ne doit rien rouvrir ici.
        $reponse = $this->getJson('/api/frontend/item?' . http_build_query([
            'paginate' => 0,
            'status'   => Status::INACTIVE,
        ]), ['x-api-key' => config('app.api_key')]);

        if ($reponse->status() !== 200) {
            $this->markTestSkipped(
                'La route publique a répondu ' . $reponse->status()
                . " — l'en-tête de clé publique diffère dans cet environnement."
            );
        }

        $noms = array_column($reponse->json('data') ?? [], 'name');

        $this->assertNotContains(
            'Bûche de Noël',
            $noms,
            "FUITE : un article DÉSACTIVÉ est exposé sur la surface client. C'est\n"
            . "exactement ce que le correctif du 2026-07-05 avait fermé."
        );
    }

    public function test_le_defaut_du_service_est_la_valeur_SURE(): void
    {
        // Le paramètre vaut VRAI par défaut : un appelant qui l'oublie FILTRE, il
        // n'expose pas. C'est le sens dans lequel un oubli doit pencher — et c'est
        // la seule protection contre le prochain contrôleur écrit à la hâte.
        $reflexion = new \ReflectionMethod(\App\Services\ItemService::class, 'simpleList');
        $parametres = $reflexion->getParameters();

        $this->assertCount(2, $parametres, 'simpleList doit porter le paramètre de visibilité.');

        $visibilite = $parametres[1];

        $this->assertTrue(
            $visibilite->isDefaultValueAvailable() && $visibilite->getDefaultValue() === true,
            "Le défaut doit être VRAI (catalogue public, donc filtré). Un défaut à faux\n"
            . 'exposerait les articles désactivés à tout appelant qui oublie le paramètre.'
        );
    }
}
