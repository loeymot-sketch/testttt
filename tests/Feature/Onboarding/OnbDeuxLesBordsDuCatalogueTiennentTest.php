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
 * [ONB-02 · clôture des constats de reconnaissance] Les bords du catalogue tiennent.
 *
 * `MISSION_ONB02_CATALOGUE_DE_ZERO.md §2.3` liste cinq constats mesurés le
 * 2026-08-26 sur la reconnaissance Z1. Ce banc les rejoue UN PAR UN contre le code
 * d'aujourd'hui, pour que la clôture repose sur une mesure et non sur une lecture.
 *
 * Trois d'entre eux ont été corrigés depuis, et ce banc les VERROUILLE pour qu'ils
 * ne reviennent pas :
 *
 *   P1 · `kds_station` inconnue rendait « SQLSTATE[01000] Data truncated for column
 *        'kds_station' » — une erreur SQL brute à l'écran d'un commerçant, parce que
 *        la règle disait `max:32` sans `in:`. La colonne est un ENUM MySQL strict.
 *
 *   P1 · Canal inconnu accepté en 201 malgré `channels.* in kiosk,pos,web`. Le
 *        constat parlait d'un article 241 créé avec un canal fantaisiste : un article
 *        rangé dans un canal qui n'existe pas n'apparaît NULLE PART.
 *
 *   P1 fiscal · Le Studio envoyait `tax_id = 1` (« No-VAT 0 % ») par défaut alors que
 *        `config/menu.php:80` désigne le taux à 10 %. Chaque produit créé au Studio
 *        naissait donc hors taxe, sans un mot.
 *
 * Ce banc ne remplace pas la reconnaissance navigateur : il fige ce qu'elle a trouvé.
 */
class OnbDeuxLesBordsDuCatalogueTiennentTest extends TestCase
{
    use RefreshDatabase;

    private User $karim;
    private Tax $taxe;
    private ItemCategory $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->taxe = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $this->categorie = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        $this->karim = User::factory()->create(['branch_id' => 0]);
        $this->karim->assignRole('Admin');
        foreach (['items', 'items_create', 'items_edit'] as $droit) {
            Permission::findOrCreate($droit, 'sanctum');
        }
        $this->karim->givePermissionTo(['items', 'items_create', 'items_edit']);
    }

    /** @param array<string, mixed> $extra */
    private function creer(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->karim, 'sanctum')->postJson('/api/admin/item', array_merge([
            'name'             => 'Tacos ONB02',
            'price'            => '8.50',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'item_type'        => 1,
            'is_featured'      => 10,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'description'      => '',
            'caution'          => '',
        ], $extra));
    }

    public function test_P1_une_station_de_cuisine_inconnue_est_refusee_en_francais(): void
    {
        $reponse = $this->creer(['kds_station' => 'friteuse_du_fond']);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['kds_station']);

        $corps = (string) $reponse->getContent();

        // LE FOND DU CONSTAT : ce n'est pas seulement qu'il fallait refuser, c'est que
        // le refus arrivait sous forme d'erreur SQL BRUTE. Un commerçant ne peut rien
        // faire de « SQLSTATE[01000] Data truncated ».
        $this->assertStringNotContainsString('SQLSTATE', $corps, 'Erreur SQL brute rendue au commerçant.');
        $this->assertStringNotContainsString('Data truncated', $corps);

        $this->assertSame(0, Item::query()->where('name', 'Tacos ONB02')->count());
    }

    public function test_P1_un_canal_de_vente_inconnu_est_refuse(): void
    {
        // Un article rangé dans un canal qui n'existe pas n'apparaît NULLE PART :
        // ni caisse, ni borne, ni web. Il est créé, facturable, invisible.
        $reponse = $this->creer(['channels' => ['kiosk', 'telepathie']]);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['channels.1']);

        $this->assertSame(
            0,
            Item::query()->where('name', 'Tacos ONB02')->count(),
            "Le constat Z1 rapportait un 201 et l'article 241 créé malgré la règle."
        );
    }

    public function test_P1_un_canal_inconnu_est_aussi_refuse_par_le_chemin_du_formulaire(): void
    {
        /*
         * LE PÉRIMÈTRE QUI COMPTE. La reconnaissance Z1 mesurait un **201** — pas un
         * 422 — et l'écran réel n'envoie pas du JSON : `ItemCreateComponent.save()`
         * construit un `FormData` et fait `fd.append('channels[]', c)`.
         *
         * Clore ce constat sur un `postJson` seul aurait été une sentinelle au mauvais
         * périmètre : verte sur un chemin que le commerçant n'emprunte jamais. On
         * rejoue donc l'encodage de formulaire, celui du bouton « Enregistrer ».
         */
        $reponse = $this->actingAs($this->karim, 'sanctum')->post('/api/admin/item', [
            'name'             => 'Tacos ONB02',
            'price'            => '8.50',
            'item_category_id' => $this->categorie->id,
            'tax_id'           => $this->taxe->id,
            'item_type'        => 1,
            'is_featured'      => 10,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'description'      => '',
            'caution'          => '',
            'channels'         => ['kiosk', 'telepathie'],
        ], ['Accept' => 'application/json']);

        $reponse->assertStatus(422);

        $this->assertSame(
            0,
            Item::query()->where('name', 'Tacos ONB02')->count(),
            "Par le chemin du formulaire, un canal fantaisiste créait un article\n"
            . "invisible partout : ni caisse, ni borne, ni web. Facturable, introuvable."
        );
    }

    public function test_P1_les_trois_canaux_legitimes_passent_toujours(): void
    {
        // Contrôle positif : sans lui, le banc précédent serait vert avec une règle
        // qui refuserait TOUT, ce qui serait un défaut pire.
        $this->creer(['channels' => ['kiosk', 'pos', 'web']])->assertStatus(201);

        $this->assertSame(
            ['kiosk', 'pos', 'web'],
            Item::query()->where('name', 'Tacos ONB02')->value('channels')
        );
    }

    public function test_P1_fiscal_le_studio_ne_propose_jamais_un_taux_a_zero(): void
    {
        // `defaultTaxId()` choisissait `this.taxes[0]`, soit « No-VAT 0 % ». Chaque
        // produit créé au Studio naissait hors taxe. Le correctif ne propose plus
        // qu'un taux STRICTEMENT POSITIF, et rend `null` sinon — un refus explicite
        // vaut mieux qu'une vente hors taxe silencieuse.
        $source = file_get_contents(
            resource_path('js/components/admin/items/CatalogStudioComponent.vue')
        );

        $this->assertMatchesRegularExpression(
            '/defaultTaxId\(\)\s*\{.*?Number\(t\.tax_rate\)\s*>\s*0/s',
            $source,
            "Le Studio reproposerait un taux à 0 % par défaut : chaque produit créé\n"
            . 'naîtrait hors taxe, sans un mot.'
        );
    }

    public function test_P1_fiscal_un_article_sans_taxe_est_refuse_par_le_serveur(): void
    {
        // L'autre bout de la même corde : même si un écran envoyait un `tax_id` vide,
        // le serveur doit refuser. `PricingService` fait `$taxes[0] ?? null` sur un
        // `tax_id` absent — donc 0 % sans alerte ni journal.
        $reponse = $this->creer(['tax_id' => '']);

        $reponse->assertStatus(422);
        $reponse->assertJsonValidationErrors(['tax_id']);
    }
}
