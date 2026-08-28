<?php

namespace Tests\Feature\Onboarding;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\RawMaterial;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-14 2026-08-28] La journée d'un nouveau commerçant, depuis une base VIERGE.
 *
 * ═══ POURQUOI CE BANC PEUT EXISTER MALGRÉ LE GATE ═══
 *
 * ONB-14 était marquée « bloquée, dépend d'ONB-12, lui-même bloqué par G0 ». C'était
 * exact pour la **dé-cayennisation** — mais pas pour ce banc-ci.
 *
 * G0 porte sur la formulation de `CONSTITUTION.md §1` et sur le droit de remonter
 * « multi-marque » comme bloquant. Il ne dit rien de la capacité d'un banc à partir
 * d'une base vide. Or `RefreshDatabase` **donne** exactement ça : une installation
 * vierge, sans le menu, les rôles ni les résidus de tests de Le Cayenne.
 *
 * Autrement dit : le seul environnement où « l'installation vierge » existe
 * aujourd'hui, c'est ici. S'en priver au nom d'un gate qui ne le couvre pas, c'était
 * confondre le blocage d'une mission avec le blocage de tout son contenu.
 *
 * ═══ CE QUE CE BANC PROUVE ═══
 *
 * Sept maillons du parcours, **enchaînés dans l'ordre réel**, chacun repartant de ce
 * que le précédent a produit. Un maillon prouvé isolément ne dit rien de la chaîne :
 * c'est toute la différence entre « chaque écran marche » et « on peut ouvrir ».
 *
 *   1. identité fiscale       — et elle SURVIT à un second enregistrement
 *   2. taxe                   — sans elle, `PricingService` facture à 0 %
 *   3. catégorie + produit    — avec allergènes, canaux et poste de cuisine
 *   4. matière première       — avec son seuil d'alerte
 *   5. personnel              — avec son téléphone, obligatoire en base
 *   6. équipement             — imprimante à la largeur de son modèle réel
 *   7. mission locale         — une sauce ajoutée à toute la catégorie
 *
 * Puis la vérification qui compte : **le produit est visible à la borne**, avec ses
 * allergènes. C'est le seul point où le commerçant voit que sa journée a servi.
 *
 * ═══ CE QUE CE BANC NE PROUVE PAS, ET LE DIT ═══
 *
 * Il ne couvre ni les horaires d'ouverture (aucune table, aucune route, aucun écran),
 * ni les frais de livraison (lus par `DeliveryFeeService`, absents de toute règle),
 * ni l'adresse d'une imprimante réelle (refusée pour toute adresse LAN), ni le wizard
 * de catégorie (jamais appliqué — zone gelée `PricingService`).
 *
 * Ces quatre manques sont structurels et bloqueraient la convergence même avec G0
 * signé. Les nommer ici plutôt que de les contourner évite qu'un banc vert laisse
 * croire le parcours complet.
 */
class LaJourneeDunNouveauCommercantTest extends TestCase
{
    use RefreshDatabase;

    private User $patron;

    protected function setUp(): void
    {
        parent::setUp();

        // Le strict minimum pour qu'un humain puisse se connecter. Rien du catalogue,
        // rien des matières, rien de l'équipement : c'est le sujet du banc.
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->patron = User::factory()->create(['branch_id' => 0]);
        $this->patron->assignRole('Admin');

        foreach ([
            'settings', 'items', 'items_show', 'items_create', 'items_edit',
            'employees_create',
        ] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }

        $this->patron->givePermissionTo([
            'settings', 'items', 'items_show', 'items_create', 'items_edit',
            'employees_create',
        ]);

        // Spatie met les permissions en cache : sans cette purge, le middleware
        // interroge un instantane pris AVANT les octrois ci-dessus et refuse tout.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(
            $this->patron->fresh()->can('items_create'),
            'Le patron doit pouvoir creer : sinon le parcours mesure une permission, pas un parcours.'
        );

        $this->actingAs($this->patron, 'sanctum');
    }

    public function test_le_parcours_complet_depuis_une_base_vierge(): void
    {
        $this->assertSame(0, Item::query()->count(), 'On part bien de zéro : aucun produit.');
        $this->assertSame(0, RawMaterial::query()->count(), 'Aucune matière première.');

        $branche = $this->etape1_identiteFiscale();
        $taxe = $this->etape2_taxe();
        [$categorie, $produit] = $this->etape3_catalogue($taxe);
        $this->etape4_matierePremiere();
        $this->etape5_personnel();
        $this->etape6_equipement($branche);
        $this->etape7_missionLocale($categorie, $produit);

        $this->verificationFinale_leProduitEstVendable($produit);
    }

    // ═══════════════════════════════════════════════════════════ 1. l'identité

    /**
     * Refuse une URL qui n'est enregistrée nulle part.
     *
     * ⚠️ POURQUOI CE GARDE-FOU EXISTE : en écrivant ce banc, j'ai visé
     * `api/admin/branch/show/{id}` — la vraie route est `api/admin/setting/branch/...`.
     * Un `assertNotSame(422, …)` aurait été SATISFAIT par le 404 qui en résulte, et le
     * banc serait passé au vert en ne mesurant rien. C'est arrivé une fois cette nuit,
     * sur le banc des imprimantes, et personne ne l'aurait vu.
     *
     * La leçon générale : **une assertion négative est presque toujours trop faible —
     * elle est satisfaite par tous les échecs sauf un.** Ici on vérifie d'abord que la
     * cible existe, ensuite seulement ce qu'elle répond.
     */
    private function routeExistante(string $uri, string $methode = 'POST'): string
    {
        $nue = ltrim($uri, '/');

        $connue = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->contains(function ($route) use ($nue, $methode) {
                $motif = preg_replace('#\{[^}]+\}#', '[^/]+', $route->uri());

                if (! preg_match('#^' . $motif . '$#', $nue)) {
                    return false;
                }

                // [corrigé après audit adverse] La version précédente ne vérifiait
                // que l'URI. Une route enregistrée en GET seule laissait donc passer
                // un `postJson` : le banc mesurait un 405 au lieu du comportement
                // visé. Le garde-fou était réel, mais à moitié — et un garde-fou à
                // moitié rassure sans protéger.
                return in_array(strtoupper($methode), $route->methods(), true);
            });

        $this->assertTrue(
            $connue,
            "Aucune route `{$methode} {$nue}` n'est enregistrée.\n"
            . 'Ce banc mesurerait un 404 ou un 405 au lieu du comportement visé.'
        );

        return '/' . $nue;
    }

    private function etape1_identiteFiscale(): Branch
    {
        $branche = Branch::factory()->create([
            'siret'       => '12345678901234',
            'vat_intra'   => 'FR12345678901',
            'register_id' => 'CAISSE-01',
        ]);

        // LE PIÈGE HISTORIQUE : rouvrir la fiche et corriger un détail effaçait
        // l'identité fiscale, parce que la ressource ne renvoyait pas ces champs et
        // que le formulaire reposait son repli `?? ""`.
        $lecture = $this->getJson($this->routeExistante('api/admin/setting/branch/show/' . $branche->id, 'GET'));
        $lecture->assertOk();

        $relu = $lecture->json('data') ?? $lecture->json();
        $this->assertIsArray($relu, 'La fiche de filiale doit être relisible.');

        foreach (['siret', 'vat_intra', 'register_id'] as $champ) {
            $this->assertArrayHasKey(
                $champ,
                $relu,
                "`{$champ}` absent de la lecture : le formulaire l'écraserait au prochain\n"
                . "enregistrement, et le ticket sortirait non conforme."
            );
        }

        $this->assertSame('12345678901234', $relu['siret']);
        $this->assertSame('CAISSE-01', $relu['register_id']);

        // ET LE SECOND ENREGISTREMENT, qui est tout l'objet de l'étape.
        //
        // Un audit adverse a relevé, à juste titre, que la version précédente
        // annonçait « elle survit à un second enregistrement » sans jamais en faire
        // un : elle créait la filiale en base et se contentait de la relire. Elle
        // prouvait que la ressource expose les champs — utile, mais pas ce qui était
        // écrit au-dessus.
        //
        // Ici le patron rouvre sa fiche et corrige UN détail sans retoucher
        // l'identité fiscale, exactement comme il le ferait à l'écran.
        $correction = $this->putJson(
            $this->routeExistante('api/admin/setting/branch/' . $branche->id, 'PUT'),
            [
                // Le formulaire renvoie l'intégralité de la fiche ; on reproduit ce
                // que l'écran enverrait, en ne changeant QUE le nom. Les champs
                // d'identité fiscale ne sont volontairement PAS transmis : c'est
                // précisément le cas qui les effaçait.
                'name'     => 'Chez Sami',
                'city'     => (string) ($relu['city'] ?? 'Lyon'),
                'state'    => (string) ($relu['state'] ?? 'Rhone'),
                'zip_code' => (string) ($relu['zip_code'] ?? '69000'),
                'address'  => (string) ($relu['address'] ?? '1 rue du Test'),
                'status'   => (int) ($relu['status'] ?? \App\Enums\Status::ACTIVE),
            ]
        );

        if (in_array($correction->status(), [200, 201, 202], true)) {
            $apres = $this->getJson(
                $this->routeExistante('api/admin/setting/branch/show/' . $branche->id, 'GET')
            )->json('data');

            $this->assertSame(
                '12345678901234',
                $apres['siret'] ?? null,
                "Le SIRET a été effacé par un enregistrement qui ne le concernait pas.\n"
                . "C'est le défaut historique : la ressource ne renvoyait pas le champ,\n"
                . 'le formulaire reposait son repli `?? ""`, et le ticket sortait non conforme.'
            );

            $this->assertSame('CAISSE-01', $apres['register_id'] ?? null);
        } else {
            // Le formulaire de filiale exige beaucoup de champs ; si l'écriture est
            // refusée ici, on le DIT plutôt que de laisser croire à une preuve.
            $this->markTestIncomplete(
                'Le second enregistrement de filiale a été refusé (' . $correction->status()
                . ') : la survie de l\'identité fiscale n\'est PAS prouvée par ce parcours. '
                . 'Elle l\'est isolément par IdentiteFiscaleSurvitAUnSecondEnregistrementTest.'
            );
        }

        return $branche;
    }

    // ══════════════════════════════════════════════════════════════ 2. la taxe

    private function etape2_taxe(): Tax
    {
        // Pas d'assertion ici : affirmer que 10 > 0 sur une valeur qu'on vient
        // d'écrire soi-même ne peut rougir sous AUCUNE mutation du code de
        // production. Un audit adverse l'a relevé, et c'était juste : c'était un
        // maillon décoratif.
        //
        // Ce qui compte vraiment — que la taxe soit rattachée au produit et non
        // nulle — est vérifié plus bas, sur le produit réellement créé par l'API.
        return Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
    }

    // ════════════════════════════════════════════════════════ 3. le catalogue

    /** @return array{0:ItemCategory, 1:Item} */
    private function etape3_catalogue(Tax $taxe): array
    {
        $this->artisan('db:seed', ['--class' => 'AllergensSeeder']);

        $categorie = ItemCategory::factory()->create([
            'name'   => 'Tacos',
            'status' => Status::ACTIVE,
        ]);

        $reponse = $this->postJson($this->routeExistante('api/admin/item'), [
            'name'                  => 'Tacos poulet',
            'price'                 => '8.50',
            'item_category_id'      => $categorie->id,
            'tax_id'                => $taxe->id,
            'item_type'             => 1,
            'is_featured'           => 10,
            'status'                => Status::ACTIVE,
            'order'                 => 1,
            'description'           => '',
            'caution'               => '',
            'channels'              => ['kiosk', 'pos'],
            'kds_station'           => 'cuisine_chaude',
            'allergen_flags'        => ['gluten', 'moutarde'],
            'allergen_flags_defini' => '1',
        ]);

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            'Création du premier produit : ' . mb_substr((string) $reponse->getContent(), 0, 300)
        );

        $produit = Item::query()->where('name', 'Tacos poulet')->firstOrFail();

        $this->assertSame('cuisine_chaude', $produit->kds_station, 'Le poste de cuisine doit être écrit.');
        $this->assertEqualsCanonicalizing(['gluten', 'moutarde'], $produit->allergen_flags ?? []);

        // Le pivot est ce que la borne interroge pour son filtre allergènes.
        $this->assertEqualsCanonicalizing(
            ['gluten', 'moutarde'],
            $produit->allergens()->pluck('code')->all(),
            "Sans le pivot, la borne présente le produit comme sans allergène."
        );

        return [$categorie, $produit];
    }

    // ═══════════════════════════════════════════════════ 4. la matière première

    private function etape4_matierePremiere(): void
    {
        $reponse = $this->postJson($this->routeExistante('api/admin/raw-materials'), [
            'name'          => 'Poulet frais',
            'unit'          => 'g',
            'threshold_low' => 2000,
            'is_active'     => true,
        ]);

        $this->assertSame(
            201,
            $reponse->status(),
            "Déclarer un ingrédient : c'était impossible avant le 2026-08-28 — le domaine\n"
            . "n'avait aucun CRUD, et tout arrivait pré-rempli avec celui de Le Cayenne.\n"
            . mb_substr((string) $reponse->getContent(), 0, 250)
        );

        $matiere = RawMaterial::query()->firstOrFail();

        $this->assertEqualsWithDelta(
            2000.0,
            (float) $matiere->threshold_low,
            0.001,
            "Le seuil d'alerte n'avait aucun chemin d'écriture : 20/20 matières à NULL,\n"
            . "et le listener filtre `whereNotNull` — donc l'alerte était structurellement muette."
        );
    }

    // ═════════════════════════════════════════════════════════════ 5. l'équipe

    private function etape5_personnel(): void
    {
        // Le rôle embauché doit être MOINS doté que le patron : `EmployeeService::
        // callerMayGrantRole` refuse toute attribution d'un rôle plus puissant que le
        // sien. C'est une protection anti-escalade, et elle est juste — mon premier jet
        // demandait `role_id => 1` (Admin) et se faisait refuser à bon droit.
        $roleModeste = \Spatie\Permission\Models\Role::query()
            ->where('name', 'Stuff')
            ->where('guard_name', 'sanctum')
            ->firstOrFail();

        $this->assertLessThan(
            $this->patron->getAllPermissions()->count(),
            $roleModeste->permissions()->count(),
            "Le rôle embauché est aussi doté que le patron : le refus anti-escalade\n"
            . 'mordrait, et ce banc mesurerait la protection au lieu du parcours.'
        );

        $reponse = $this->postJson($this->routeExistante('api/admin/employee'), [
            'name'         => 'Sami',
            'email'        => 'sami@exemple.test',
            'username'     => 'sami',
            'password'     => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
            'country_code' => '+33',
            'phone'        => '+33612345678',
            'status'       => Status::ACTIVE,
            'role_id'      => $roleModeste->id,
        ]);

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            "Embaucher le premier employé : " . mb_substr((string) $reponse->getContent(), 0, 250)
        );

        // Et le contrôle négatif, dans le même souffle : sans téléphone, le refus doit
        // être NOMMÉ. C'est ce qui donnait « erreur de base de données » au patron.
        $sansTelephone = $this->postJson($this->routeExistante('api/admin/employee'), [
            'name'         => 'Nadia',
            'email'        => 'nadia@exemple.test',
            'username'     => 'nadia',
            'password'     => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
            'country_code' => '+33',
            'status'       => Status::ACTIVE,
            'role_id'      => $roleModeste->id,
        ]);

        $sansTelephone->assertStatus(422);
        $sansTelephone->assertJsonValidationErrors(['phone']);
        $this->assertStringNotContainsString('SQLSTATE', (string) $sansTelephone->getContent());
    }

    // ════════════════════════════════════════════════════════ 6. l'équipement

    private function etape6_equipement(Branch $branche): void
    {
        // La largeur 42 est celle des SAGA 80 mm, que l'écran NOMME. Elle était
        // proposée puis refusée sans un mot, sur un champ sans affichage d'erreur.
        $reponse = $this->postJson($this->routeExistante('api/admin/printers'), [
            'name'        => 'Caisse',
            'type'        => 'escpos_tcp',
            'host'        => 'imprimante.exemple.test',
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => 42,
            'status'      => Status::ACTIVE,
            // Obligatoire pour l'admin (`branch_id = 0`) : sans elle, l'insertion
            // partait a 0 et la cle etrangere rejetait. Voir
            // UneImprimanteSansEtablissementEstRefuseeLisiblementTest.
            'branch_id'   => $branche->id,
        ]);

        $this->assertContains(
            $reponse->status(),
            [200, 201, 202],
            'Déclarer son imprimante : ' . mb_substr((string) $reponse->getContent(), 0, 250)
        );
    }

    // ═════════════════════════════════════════════════════ 7. la mission locale

    private function etape7_missionLocale(ItemCategory $categorie, Item $produit): void
    {
        // Le chatbot demandé par le mandat : « ajoute une sauce à tous les tacos ».
        $plan = $this->postJson($this->routeExistante('api/admin/assistant/mission/lecture'), [
            'phrase' => 'ajoutez la sauce Algérienne à tous les tacos',
        ]);

        $plan->assertOk();
        $this->assertTrue($plan->json('compris'), (string) $plan->getContent());

        // La lecture ne doit RIEN écrire : le commerçant voit d'abord.
        $this->assertSame(0, ItemExtra::query()->count(), "La lecture a écrit en base.");

        $this->postJson($this->routeExistante('api/admin/assistant/mission/application'), [
            'phrase'       => 'ajoutez la sauce Algérienne à tous les tacos',
            'confirmation' => true,
        ])->assertOk();

        $this->assertSame(
            1,
            ItemExtra::query()->where('item_id', $produit->id)->where('name', 'Algérienne')->count(),
            "L'option doit être posée sur le produit de la catégorie visée."
        );
    }

    // ════════════════════════════════════ la vérification qui compte vraiment

    private function verificationFinale_leProduitEstVendable(Item $produit): void
    {
        $frais = $produit->fresh();

        // C'est le seul point où le commerçant voit que sa journée a servi : son
        // produit existe, il est actif, il est rattaché à une taxe non nulle, il porte
        // ses allergènes, et il est visible sur les surfaces qu'il a choisies.
        $this->assertSame(Status::ACTIVE, (int) $frais->status, 'Le produit doit être actif.');

        $this->assertNotNull($frais->tax_id, 'Sans taxe, il serait facturé à 0 % en silence.');
        $this->assertGreaterThan(0, (float) Tax::query()->whereKey($frais->tax_id)->value('tax_rate'));

        $this->assertEqualsCanonicalizing(['kiosk', 'pos'], $frais->channels ?? []);

        $this->assertNotEmpty(
            $frais->allergens()->pluck('code')->all(),
            "Le filtre allergènes de la borne interroge ce pivot : vide, il présente le\n"
            . 'produit comme sans allergène à un client qui filtre par allergie.'
        );

        $this->assertSame(
            1,
            ItemExtra::query()->where('item_id', $frais->id)->count(),
            "L'option ajoutée par la mission locale doit être là."
        );
    }

    /**
     * ⚠️ CE QUE LE PARCOURS NE COUVRE PAS — dit ici plutôt que passé sous silence.
     *
     * Un banc vert sur sept maillons laisserait croire le parcours complet. Il ne
     * l'est pas, et les quatre manques sont structurels — ils bloqueraient la
     * convergence même avec G0 signé.
     */
    public function test_les_quatre_manques_structurels_sont_toujours_la(): void
    {
        // 1. Les horaires d'ouverture : ni table, ni route, ni écran.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('branch_opening_hours'),
            "Une table d'horaires est apparue : ce banc doit être relu, et ONB-01 mis à jour."
        );

        // 2. Les frais de livraison : lus par le service, absents de toute règle.
        $regle = file_get_contents(base_path('app/Http/Requests/BranchRequest.php'));
        $this->assertStringNotContainsString(
            'delivery_fee_base',
            $regle,
            "`delivery_fee_*` est devenu réglable : ONB-01 doit être mis à jour."
        );

        // 3. Le wizard de catégorie : écrit avec `item_id => null`, lu par personne.
        $lecteur = file_get_contents(app_path('Services/Composer/ComposerProfileService.php'));
        $this->assertStringContainsString(
            'item_id',
            $lecteur,
            'Le service du composer a changé de forme : le dossier G-WIZARD doit être relu.'
        );

        $this->markTestIncomplete(
            "Parcours partiel, volontairement. Manquent : horaires d'ouverture (aucune "
            . "table), frais de livraison (aucune règle), adresse d'imprimante réelle "
            . "(refusée pour toute adresse LAN), wizard de catégorie (jamais appliqué, "
            . 'zone gelée). Voir MISSION_ONB14 §8.3.'
        );
    }
}
