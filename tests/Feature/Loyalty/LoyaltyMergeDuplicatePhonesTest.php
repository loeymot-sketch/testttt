<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [FIDÉLITÉ 2026-08-19] RENDRE À UN CLIENT LES POINTS DE SON AUTRE COMPTE.
 *
 * Les surfaces cherchent désormais toutes les écritures d'un numéro, donc aucun NOUVEAU doublon
 * ne se crée. Restent ceux déjà en base — mesurés le 2026-08-19 : 6 numéros, dont un avec 500
 * points d'un côté et 0 de l'autre. `fidelite:fusionner-doublons` les regroupe.
 *
 * Ce fichier existe parce que cette commande DÉPLACE DE L'ARGENT. Trois choses doivent tenir :
 * le transfert passe par le grand-livre (jamais un UPDATE muet), le personnel n'est jamais
 * touché, et rien ne bouge tant qu'on n'a pas demandé `--apply`.
 */
class LoyaltyMergeDuplicatePhonesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function client(string $phone, int $points, int $isGuest = Ask::YES, string $code = null): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'is_guest' => $isGuest,
            'loyalty_code' => $code ?: strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'loyalty_points' => $points,
        ]);
    }

    public function test_les_points_du_doublon_reviennent_au_compte_principal_par_le_grand_livre(): void
    {
        // Le compte PLEIN (le client s'y connecte) doit l'emporter sur le talon créé à la borne.
        $principal = $this->client('0612345678', 200, Ask::NO);
        $doublon = $this->client('+33612345678', 800, Ask::YES);

        $this->artisan('fidelite:fusionner-doublons --apply')->assertSuccessful();

        $principal->refresh();
        $doublon->refresh();

        $this->assertSame(1000, (int) $principal->loyalty_points, 'le principal récupère 200 + 800');
        $this->assertSame(0, (int) $doublon->loyalty_points, 'le doublon est vidé');

        // DEUX écritures, pas une : le grand-livre doit répondre aussi bien à « où sont passés mes
        // points ? » qu'à « d'où viennent ceux-ci ? ».
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $doublon->id,
            'type' => 'manual_deduct',
            'points' => -800,
            'balance_after' => 0,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $principal->id,
            'type' => 'manual_add',
            'points' => 800,
            'balance_after' => 1000,
        ]);

        // Le principal porte désormais la forme canonique : le prochain client qui donne son
        // numéro tombe sur LUI, quelle que soit l'écriture qu'il présente.
        $this->assertSame('0612345678', $principal->phone);
    }

    public function test_sans_apply_absolument_rien_ne_bouge(): void
    {
        $principal = $this->client('0612345678', 200, Ask::NO);
        $doublon = $this->client('+33612345678', 800);

        $this->artisan('fidelite:fusionner-doublons')->assertSuccessful();

        $this->assertSame(200, (int) $principal->refresh()->loyalty_points);
        $this->assertSame(800, (int) $doublon->refresh()->loyalty_points);
        $this->assertSame(0, LoyaltyTransaction::count(), 'un aperçu n\'écrit rien');
    }

    /**
     * LA GARDE QUI COMPTE LE PLUS.
     *
     * Dans un restaurant de quartier, l'exploitant ou un caissier partage volontiers son numéro
     * avec un client. Sans cette garde, la commande transférerait les points DU CLIENT vers le
     * compte DU PERSONNEL — en écrivant proprement au grand-livre que c'était voulu.
     */
    public function test_le_compte_du_personnel_n_est_jamais_touche(): void
    {
        $caissier = $this->client('0612345678', 0, Ask::NO);
        $caissier->assignRole('POS Operator');

        $clientFidele = $this->client('+33612345678', 900);

        $this->artisan('fidelite:fusionner-doublons --apply')->assertSuccessful();

        $this->assertSame(0, (int) $caissier->refresh()->loyalty_points, 'le personnel ne reçoit rien');
        $this->assertSame(900, (int) $clientFidele->refresh()->loyalty_points, 'le client garde ses points');
        $this->assertSame(0, LoyaltyTransaction::count(), 'aucune écriture : il n\'y avait rien à fusionner');
    }

    public function test_un_numero_sans_doublon_est_ignore(): void
    {
        $seul = $this->client('0655667788', 400);

        $this->artisan('fidelite:fusionner-doublons --apply')->assertSuccessful();

        $this->assertSame(400, (int) $seul->refresh()->loyalty_points);
        $this->assertSame(0, LoyaltyTransaction::count());
    }
}
