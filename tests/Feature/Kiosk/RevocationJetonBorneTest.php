<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use App\Services\KioskMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * [ONB-10 T-1.1.1 2026-08-27] Retirer une borne doit la couper pour de bon.
 *
 * Le défaut : aucun des trois gestes du Dashboard — supprimer, désactiver,
 * déconnecter — ne révoquait le jeton Sanctum de la borne. Chacun envoyait une
 * notification Firebase « vous êtes déconnecté » et espérait que l'appareil
 * obéisse. Une borne volée, hors ligne, ou dont l'application a été modifiée
 * continuait à commander pendant 8 heures (`sanctum.expiration = 480`).
 *
 * Le seul chemin qui révoquait vraiment était la déconnexion demandée PAR LA BORNE
 * elle-même — c'est-à-dire le cas où on n'en a pas besoin.
 *
 * Le second test est aussi important que les trois premiers : la révocation doit
 * être portée par l'APPAREIL, jamais par le compte. Plusieurs bornes partagent un
 * même compte, et couper l'une ne doit pas éteindre la caisse d'à côté — c'est le
 * défaut corrigé le 2026-08-07 pour les écrans multiples, à ne pas réintroduire.
 */
class RevocationJetonBorneTest extends TestCase
{
    use RefreshDatabase;

    /** La filiale doit exister : `kiosk_machines.branch_id` porte une cle etrangere. */
    private function filiale(): Branch
    {
        return Branch::firstOrCreate(
            ['id' => 1],
            [
                'name'     => 'Filiale de test',
                'email'    => 'test@example.test',
                'phone'    => '+33600000000',
                'city'     => 'Henin-Beaumont',
                'state'    => 'Hauts-de-France',
                'zip_code' => '62110',
                'address'  => '1 rue de Test',
                'status'   => Status::ACTIVE,
            ]
        );
    }

    private function borneAvecJeton(User $compte, int $etat = Status::ACTIVE): array
    {
        $this->filiale();

        $borne = KioskMachine::create([
            'user_id'    => $compte->id,
            'machine_id' => 'BORNE-' . uniqid('', true),
            'username'   => 'borne-' . uniqid('', true),
            'password'   => bcrypt('secret-de-test'),
            'branch_id'  => 1,
            'status'     => $etat,
            'is_login'   => Ask::YES,
        ]);

        $jeton = $compte->createToken('kiosk_token', ['kiosk:order']);
        PersonalAccessToken::query()
            ->whereKey($jeton->accessToken->id)
            ->update(['device_id' => 'kiosk-' . $borne->id]);

        return [$borne, $jeton->accessToken->id];
    }

    private function jetonExiste(int $id): bool
    {
        return PersonalAccessToken::query()->whereKey($id)->exists();
    }

    public function test_supprimer_une_borne_revoque_son_jeton(): void
    {
        $compte = User::factory()->create();
        [$borne, $idJeton] = $this->borneAvecJeton($compte);

        $this->assertTrue($this->jetonExiste($idJeton), 'Le jeton doit exister au départ.');

        app(KioskMachineService::class)->destroy($borne);

        $this->assertFalse(
            $this->jetonExiste($idJeton),
            "Une borne supprimée doit perdre son jeton : sinon elle commande encore 8 heures."
        );
    }

    public function test_desactiver_une_borne_revoque_son_jeton(): void
    {
        $compte = User::factory()->create();
        [$borne, $idJeton] = $this->borneAvecJeton($compte);

        app(KioskMachineService::class)->changeStatus(
            $borne,
            new Request(['status' => Status::INACTIVE])
        );

        $this->assertFalse(
            $this->jetonExiste($idJeton),
            'Désactiver une borne doit la couper, pas seulement changer une colonne.'
        );
    }

    public function test_deconnecter_une_borne_revoque_son_jeton(): void
    {
        $compte = User::factory()->create();
        [$borne, $idJeton] = $this->borneAvecJeton($compte);

        app(KioskMachineService::class)->logout($borne);

        $this->assertFalse(
            $this->jetonExiste($idJeton),
            "Le bouton « Déconnecter » posait un drapeau is_login sans rien couper."
        );
    }

    public function test_couper_une_borne_ne_coupe_pas_sa_voisine(): void
    {
        // Deux bornes, UN SEUL compte — le cas réel d'un restaurant à plusieurs écrans.
        $compte = User::factory()->create();
        [$borneA, $jetonA] = $this->borneAvecJeton($compte);
        [$borneB, $jetonB] = $this->borneAvecJeton($compte);

        app(KioskMachineService::class)->logout($borneA);

        $this->assertFalse($this->jetonExiste($jetonA), 'La borne visée doit être coupée.');
        $this->assertTrue(
            $this->jetonExiste($jetonB),
            "La borne voisine doit rester en service : révoquer par compte plutôt que par "
            . "appareil éteindrait tout le restaurant d'un coup."
        );
    }

    public function test_reactiver_une_borne_ne_detruit_rien(): void
    {
        $compte = User::factory()->create();
        [$borne, $idJeton] = $this->borneAvecJeton($compte, Status::INACTIVE);

        app(KioskMachineService::class)->changeStatus(
            $borne,
            new Request(['status' => Status::ACTIVE])
        );

        $this->assertTrue(
            $this->jetonExiste($idJeton),
            'Réactiver ne doit rien révoquer : la borne se reconnecte d\'elle-même.'
        );
    }
}
