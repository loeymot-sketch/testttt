<?php

namespace Tests\Feature\Loyalty;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [FIDÉLITÉ 2026-08-19] « 06 … », « +33 6 … » ET « 6 … » SONT LA MÊME PERSONNE.
 *
 * ── LE DÉFAUT, MESURÉ SUR LA BASE RÉELLE ─────────────────────────────────────────────────────
 * 6 numéros portent plusieurs comptes. Le plus parlant : `+33600009999` avec **500 points** et
 * `0600009999` avec **0**. Un seul humain, coupé en deux — et ses points sont introuvables
 * depuis la moitié qu'il présente au comptoir.
 *
 * La cause est un « jumeau oublié » caractérisé. `PhoneIdentity` a été créé le 2026-08-10
 * précisément pour ce problème (62 comptes non normalisés à l'époque) et la CAISSE l'utilise
 * (`PosCustomerLookupService::byPhone`). La BORNE et le SITE, eux, comparaient l'écriture EXACTE
 * tapée — donc ne trouvaient rien, et `register()` créait un compte de plus à chaque fois.
 * Réparer la lecture sans réparer l'écriture n'aurait fait que déplacer le problème : les
 * comptes créés par la borne sont désormais enregistrés sous la forme canonique.
 *
 * ── CE QUE CE FICHIER EMPÊCHE DE REVENIR ─────────────────────────────────────────────────────
 * Qu'une surface se remette à chercher « le numéro tel qu'il a été tapé ». C'est silencieux :
 * personne ne voit un doublon se créer, le client voit juste son solde repartir de zéro.
 */
class LoyaltyPhoneVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function cle(): string
    {
        return (string) config('app.api_key');
    }

    private function clientExistant(string $telEnBase, int $points = 500): User
    {
        return User::factory()->create([
            'phone' => $telEnBase,
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            'is_guest' => Ask::YES,
            'loyalty_code' => 'PHONEVAR',
            'loyalty_points' => $points,
        ]);
    }

    /**
     * @return array<int, array{0:string,1:string}> [écriture en base, écriture tapée]
     */
    public static function ecritures(): array
    {
        return [
            'base 06… / tapé +33…'   => ['0600009999', '+33600009999'],
            'base +33… / tapé 06…'   => ['+33600009999', '0600009999'],
            'base 06… / tapé sans 0' => ['0600009999', '600009999'],
            'base 06… / tapé espacé' => ['0600009999', '06 00 00 99 99'],
        ];
    }

    /**
     * @dataProvider ecritures
     */
    public function test_une_inscription_borne_ne_cree_PAS_un_second_compte(string $enBase, string $tape): void
    {
        $existant = $this->clientExistant($enBase);

        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/register', [
                'phone' => $tape,
                'name' => 'Le Même Client',
            ])
            ->assertOk()
            // Compte retrouvé → réponse « existe déjà », JAMAIS une création.
            ->assertJsonPath('code', 'PHONE_EXISTS');

        $this->assertSame(
            1,
            User::whereIn('phone', app(\App\Services\Identity\PhoneIdentity::class)->variants($tape))->count(),
            "Écriture « {$tape} » face à « {$enBase} » : un SECOND compte a été créé, les points du premier sont perdus."
        );

        $existant->refresh();
        $this->assertSame(500, (int) $existant->loyalty_points, 'le solde du compte retrouvé ne bouge pas');
    }

    public function test_un_compte_cree_a_la_borne_est_enregistre_sous_la_forme_canonique(): void
    {
        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/register', [
                'phone' => '+33612345678',
                'name' => 'Nouveau',
            ])
            ->assertOk();

        // Réparer la lecture sans corriger l'écriture, c'est continuer à semer des formes
        // divergentes que la lecture devra rattraper indéfiniment.
        $this->assertDatabaseHas('users', ['phone' => '0612345678']);
        $this->assertDatabaseMissing('users', ['phone' => '+33612345678']);
    }

    /**
     * L'INSCRIPTION AVEC CONSENTEMENT RGPD DOIT SURVIVRE À LA NORMALISATION.
     *
     * Dégât indirect qu'a failli causer le correctif lui-même : `optIn()` retrouvait le client
     * avec le numéro BRUT après que `register()` l'a enregistré normalisé → le consentement,
     * seule pièce qui justifie le traitement des données du client, n'était plus écrit.
     */
    public function test_le_consentement_rgpd_est_bien_enregistre_avec_un_numero_international(): void
    {
        $this->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/opt-in', [
                'phone' => '+33655443322',
                'name' => 'Client Consentant',
                'consent_accepted' => true,
                'privacy_notice_version' => '1.0',
            ])
            ->assertOk();

        $client = User::where('phone', '0655443322')->first();
        $this->assertNotNull($client, 'compte créé sous forme canonique');
        $this->assertDatabaseHas('loyalty_consents', ['user_id' => $client->id]);
    }

    /**
     * LA CONSULTATION DE SOLDE AUSSI. Un client qui donne « +33… » au comptoir doit voir SES
     * points, pas un compte vide.
     */
    public function test_la_consultation_retrouve_le_compte_quelle_que_soit_l_ecriture(): void
    {
        $this->clientExistant('0600009999', 750);
        $staff = User::factory()->create(['branch_id' => 1]);
        $staff->assignRole('Admin');

        $reponse = $this->actingAs($staff, 'sanctum')
            ->withHeader('x-api-key', $this->cle())
            ->postJson('/api/frontend/loyalty/check', ['code' => '+33600009999'])
            ->assertOk();

        $this->assertSame(750, (int) $reponse->json('data.points'));
    }
}
