<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\ItemCategory;
use App\Services\Menu\MenuProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CHEF 2026-09-02] LES RAYONS DE TEST NE SORTENT NI EN CAISSE NI EN BORNE.
 *
 * Le masque anti-pollution (`ItemCategoryService::isAuditPollutionName`) a été
 * posé sur la liste du catalogue ADMIN. Son propre commentaire annonçait
 * pourtant : « Le commerçant les voyait dans le catalogue ; LA BORNE AUSSI. »
 * La borne — et la caisse — n'ont jamais été couvertes.
 *
 * Mesuré sur la base de développement le 2026-09-02, avant correctif :
 * `MenuProjectionService::forChannel()` renvoyait 12 rayons pour `pos` ET pour
 * `kiosk`, dont trois déchets de campagne : « E2E Cat 1786616399744 »,
 * « E2ECategory13511EDITED » et « E2E_PLAYWRIGHT_STUDIO_CATEGORY ». Le caissier
 * et le client de la borne les avaient sous les yeux.
 *
 * Ce test crée lui-même ses rayons : il ne dépend d'aucun état de base, et il
 * échoue si le filtre disparaît de la projection.
 */
class ProjectionMenuSansPollutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function canaux(): array
    {
        return ['caisse' => ['pos'], 'borne' => ['kiosk']];
    }

    /**
     * @dataProvider canaux
     */
    public function test_un_rayon_de_campagne_ne_sort_pas_sur_le_canal(string $canal): void
    {
        ItemCategory::factory()->create(['name' => 'Burgers', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'E2E Cat 1786616399744', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'E2ECategory13511EDITED', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'E2E_PLAYWRIGHT_STUDIO_CATEGORY', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'AUDIT-KIOSK-MULTI', 'status' => Status::ACTIVE]);
        ItemCategory::factory()->create(['name' => 'ZZ-TEST-orphelin', 'status' => Status::ACTIVE]);

        $noms = collect(app(MenuProjectionService::class)->forChannel($canal, 1)['categories'])
            ->pluck('name')
            ->all();

        $this->assertContains('Burgers', $noms, 'un vrai rayon doit rester servi');

        foreach ([
            'E2E Cat 1786616399744',
            'E2ECategory13511EDITED',
            'E2E_PLAYWRIGHT_STUDIO_CATEGORY',
            'AUDIT-KIOSK-MULTI',
            'ZZ-TEST-orphelin',
        ] as $dechet) {
            $this->assertNotContains(
                $dechet,
                $noms,
                "« {$dechet} » est un rayon de campagne : il ne doit jamais atteindre le canal « {$canal} »."
            );
        }
    }

    /**
     * Le masque ne doit pas déborder : un rayon « interne / technique » n'est pas
     * de la pollution de campagne. Il a ses propres règles de visibilité
     * (`isVisibleOn`) et ce filtre-ci ne doit pas s'y substituer — sans quoi on
     * corrigerait un mensonge en en créant un autre.
     */
    public function test_le_masque_ne_deborde_pas_sur_les_vrais_rayons(): void
    {
        foreach (['Sandwichs', 'Galette', 'Tacos', 'Bols', 'Frites', 'Menu enfant'] as $vrai) {
            ItemCategory::factory()->create(['name' => $vrai, 'status' => Status::ACTIVE]);
        }

        $noms = collect(app(MenuProjectionService::class)->forChannel('pos', 1)['categories'])
            ->pluck('name')
            ->all();

        foreach (['Sandwichs', 'Galette', 'Tacos', 'Bols', 'Frites', 'Menu enfant'] as $vrai) {
            $this->assertContains($vrai, $noms, "« {$vrai} » est un vrai rayon du menu Le Cayenne");
        }
    }
}
