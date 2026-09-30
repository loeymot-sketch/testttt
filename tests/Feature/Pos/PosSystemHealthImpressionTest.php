<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [P0-07 / P0-08 · RAPPORT_DEV_CAISSE_2026-09-24] « État système : Tout va bien » alors
 * que la table Imprimantes était VIDE en production.
 *
 * L'écran de santé de la caisse contrôlait le temps réel, les files, le stock et les
 * commandes en souffrance — mais JAMAIS la seule chose qui met le plat en route. Un
 * restaurant pouvait ouvrir, lire « Tout va bien », et découvrir à la première commande
 * que rien ne sortait en cuisine.
 *
 * Ces tests figent l'honnêteté de l'écran : sans imprimante active il cesse de dire que
 * tout va bien, sans jamais passer au rouge non plus (on encaisse toujours, et l'écran
 * cuisine reste la voie de secours — c'est une panne d'atelier, pas une caisse morte).
 */
class PosSystemHealthImpressionTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::flush();
        config(['app.api_key' => self::API_KEY]);
        $this->withHeaders(['x-api-key' => self::API_KEY, 'Accept' => 'application/json']);
    }

    private function caissier(int $branchId = 1): User
    {
        if ($branchId > 0 && ! Branch::withoutGlobalScopes()->find($branchId)) {
            Branch::factory()->create(['id' => $branchId]);
        }
        $u = User::factory()->create(['branch_id' => $branchId]);
        $u->assignRole('POS Operator');

        return $u;
    }

    /** Aucune fabrique `Printer` n'existe dans ce dépôt : on écrit la ligne directement. */
    private function imprimante(int $branchId, int $statut): void
    {
        \Illuminate\Support\Facades\DB::table('printers')->insert([
            'branch_id' => $branchId,
            'name' => 'Imprimante de test '.$branchId.'-'.$statut,
            'status' => $statut,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sante(User $u): array
    {
        Sanctum::actingAs($u, ['*']);

        return $this->getJson('/api/admin/pos/system-health')->assertOk()->json();
    }

    public function test_sans_imprimante_active_l_ecran_cesse_de_dire_que_tout_va_bien(): void
    {
        $reponse = $this->sante($this->caissier());

        $this->assertArrayHasKey('impression', $reponse['checks'], 'Le contrôle impression doit exister.');
        $this->assertSame(0, $reponse['checks']['impression']['count']);
        $this->assertSame('warn', $reponse['checks']['impression']['status']);
        $this->assertStringContainsString(
            'cuisine',
            $reponse['checks']['impression']['message'],
            'Le message doit dire la CONSÉQUENCE, pas seulement l\'état.'
        );
        $this->assertNotSame(
            'ok',
            $reponse['overall'],
            'RÉGRESSION P0-07 : « Tout va bien » avec zéro imprimante — le restaurant '
            . 'le découvrirait à la première commande.'
        );
    }

    public function test_sans_imprimante_l_ecran_ne_passe_pas_au_rouge_pour_autant(): void
    {
        $reponse = $this->sante($this->caissier());

        $this->assertNotSame(
            'down',
            $reponse['overall'],
            'On encaisse toujours et l\'écran cuisine reste la voie de secours : ambre, pas rouge.'
        );
    }

    public function test_avec_une_imprimante_active_le_controle_est_vert(): void
    {
        $caissier = $this->caissier();
        $this->imprimante($caissier->branch_id, Status::ACTIVE);

        $reponse = $this->sante($caissier);

        $this->assertSame('ok', $reponse['checks']['impression']['status']);
        $this->assertSame(1, $reponse['checks']['impression']['count']);
    }

    public function test_une_imprimante_INACTIVE_ne_compte_pas(): void
    {
        $caissier = $this->caissier();
        $this->imprimante($caissier->branch_id, Status::INACTIVE);

        $reponse = $this->sante($caissier);

        $this->assertSame(
            0,
            $reponse['checks']['impression']['count'],
            'Une imprimante désactivée n\'imprime rien : la compter serait un faux vert.'
        );
        $this->assertSame('warn', $reponse['checks']['impression']['status']);
    }

    public function test_l_imprimante_d_une_AUTRE_branche_ne_compte_pas(): void
    {
        $caissier = $this->caissier(1);
        $autre = Branch::factory()->create();
        $this->imprimante($autre->id, Status::ACTIVE);

        $reponse = $this->sante($caissier);

        $this->assertSame(
            0,
            $reponse['checks']['impression']['count'],
            'L\'imprimante d\'une autre succursale n\'imprimera jamais ce ticket-ci.'
        );
    }
}
