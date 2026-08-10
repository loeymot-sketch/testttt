<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * INSCRIRE UN CLIENT AU COMPTOIR.
 *
 * [propriétaire : « je veux ajouter la section pour pouvoir créer un compte pour un client »]
 *
 * ── LE VRAI RISQUE ───────────────────────────────────────────────────────────────────────────
 * Ce n'est pas de ne pas créer le compte : c'est d'en créer un DEUXIÈME pour quelqu'un qui en a
 * déjà un. Deux comptes, deux soldes de points, un seul humain — et une plainte au comptoir qu'on
 * ne saura pas expliquer. Un client qui dit « je ne suis pas inscrit » se trompe une fois sur deux.
 *
 * C'est pourquoi cet écran passe par le MÊME service que la roue (`CustomerAccountProvisioner`) :
 * une seconde façon de créer un client aurait été une seconde façon de fabriquer ce doublon.
 */
class PosCustomerCreateTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/pos-loyalty/customers';

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('loyalty.qr.secret', 'test-qr-secret-'.str_repeat('d', 40));
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 1000,
        ]);

        $branche = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $branche->id, 'phone' => '0100000002']);
        $this->caissier->assignRole('POS Operator');

        RateLimiter::clear('pos-loyalty-lookup');
    }

    private function creer(array $corps)
    {
        return $this->actingAs($this->caissier, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'test-'.bin2hex(random_bytes(8)))
            ->postJson(self::URL, $corps);
    }

    // ── LE CAS ORDINAIRE ─────────────────────────────────────────────────────────────────────

    /** Un numéro suffit : exiger une adresse au comptoir, c'est perdre l'inscription. */
    public function test_un_numero_suffit_a_inscrire_un_client(): void
    {
        $r = $this->creer(['phone' => '06 11 22 33 44'])->assertStatus(201);

        $this->assertTrue($r->json('data.created'));

        $u = User::where('phone', '0611223344')->firstOrFail();
        $this->assertNotEmpty($u->loyalty_code, 'sans code de fidélité, il cumule zéro point');
        $this->assertSame((int) Ask::YES, (int) $u->is_guest);
        $this->assertSame('Client Comptoir', $u->name,
            'un compte né au comptoir ne doit pas s\'appeler « Client Roue »');
        $this->assertSame(0, (int) $u->branch_id, 'un client appartient à la maison, pas à un poste');
    }

    /** Avec un nom et une adresse, les deux sont gardés — c'est ce qui permet d'envoyer le code. */
    public function test_le_nom_et_l_adresse_sont_gardes_quand_ils_sont_donnes(): void
    {
        $this->creer(['phone' => '0611223355', 'name' => 'Sophie M', 'email' => 'Sophie.M@Exemple.FR'])
            ->assertStatus(201);

        $u = User::where('phone', '0611223355')->firstOrFail();
        $this->assertSame('Sophie M', $u->name);
        $this->assertSame('sophie.m@exemple.fr', $u->email, 'l\'adresse est rangée en minuscules');
        $this->assertNull($u->email_verified_at,
            'personne n\'a prouvé que cette adresse est la sienne : la marquer vérifiée serait mentir');
    }

    /** L'écran rend tout de suite ce que le comptoir doit lire : le solde et ce qui est utilisable. */
    public function test_la_reponse_contient_de_quoi_enchainer_sans_seconde_recherche(): void
    {
        $c = $this->creer(['phone' => '0611223366'])->assertStatus(201)->json('data.customer');

        $this->assertSame(0, $c['balance']);
        $this->assertFalse($c['can_use']);
        $this->assertSame(1000, $c['missing_points'], 'on dit ce qu\'il lui manque dès l\'inscription');
        $this->assertSame(1000, $c['effective_floor']);
        $this->assertNotEmpty($c['loyalty_code'], 'le code est ce que la commande de caisse rattachera');
    }

    // ── LE DOUBLON, LE VRAI DANGER ───────────────────────────────────────────────────────────

    /**
     * LE CŒUR. Un numéro déjà connu ne reçoit PAS un second compte : on rend l'existant, et le
     * caissier voit le solde au lieu d'un doublon vide.
     */
    public function test_un_numero_deja_connu_ne_recoit_pas_un_second_compte(): void
    {
        $ancien = User::factory()->create(['phone' => '0611223377', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $ancien->id)->update(['loyalty_code' => 'DEJA001', 'loyalty_points' => 2500]);

        $r = $this->creer(['phone' => '0611223377'])->assertStatus(200);

        $this->assertFalse($r->json('data.created'),
            'l\'écran ne doit pas annoncer « compte créé » à un client inscrit depuis six mois');
        $this->assertSame('DEJA001', $r->json('data.customer.loyalty_code'));
        $this->assertSame(2500, $r->json('data.customer.balance'), 'son solde doit apparaître, pas zéro');
        $this->assertSame(1, User::where('phone', '0611223377')->count());
    }

    /**
     * ET MÊME SI LE CAISSIER TAPE LE NUMÉRO AUTREMENT. Le compte en base peut porter
     * « +33611223388 » alors que le caissier tape « 0611223388 » : 62 comptes sur 348 portent une
     * forme non normalisée. Un seul de ces cas mal traité fabrique le doublon.
     */
    public function test_une_autre_ecriture_du_numero_ne_fabrique_pas_de_doublon(): void
    {
        $ancien = User::factory()->create(['phone' => '+33611223388', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $ancien->id)->update(['loyalty_code' => 'DEJA002', 'loyalty_points' => 700]);

        $r = $this->creer(['phone' => '0611223388'])->assertStatus(200);

        $this->assertFalse($r->json('data.created'));
        $this->assertSame('DEJA002', $r->json('data.customer.loyalty_code'));
        $this->assertSame(1, User::whereIn('phone', ['+33611223388', '0611223388'])->count());
    }

    /** Un double appui sur « Créer » ne produit pas deux comptes. */
    public function test_un_double_appui_ne_produit_pas_deux_comptes(): void
    {
        $cle = 'double-appui-'.bin2hex(random_bytes(6));

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->caissier, 'sanctum')
                ->withHeader('X-Idempotency-Key', $cle)
                ->postJson(self::URL, ['phone' => '0611223399'])
                ->assertSuccessful();
        }

        $this->assertSame(1, User::where('phone', '0611223399')->count());
    }

    // ── LES REFUS, ET LEUR PHRASE ────────────────────────────────────────────────────────────

    /** Le numéro d'un COLLÈGUE n'est pas transformé en compte client. */
    public function test_le_numero_d_un_collegue_est_refuse_avec_une_phrase_lisible(): void
    {
        $collegue = User::factory()->create(['phone' => '0699000222']);
        $collegue->assignRole('POS Operator');

        $r = $this->creer(['phone' => '0699000222'])->assertStatus(422);

        $this->assertSame('STAFF_PHONE', $r->json('code'));
        $this->assertStringContainsString('équipe', $r->json('message'));
    }

    /**
     * UN COMPTE SUPPRIMÉ NE RESSUSCITE PAS AU COMPTOIR. La suppression était une décision ; la
     * rétablir demande un responsable, et le message le DIT au lieu de laisser le caissier bloqué.
     */
    public function test_un_compte_supprime_ne_ressuscite_pas_et_le_message_dit_quoi_faire(): void
    {
        $parti = User::factory()->create(['phone' => '0655000111', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $parti->id)->update(['loyalty_code' => 'PARTI02']);
        $parti->delete();

        $r = $this->creer(['phone' => '0655000111'])->assertStatus(422);

        $this->assertSame('DELETED_ACCOUNT', $r->json('code'));
        $this->assertStringContainsString('responsable', $r->json('message'));
    }

    /**
     * Un numéro incomplet est refusé, et l'erreur se pose SUR LE CHAMP.
     *
     * Le service refuse aussi les numéros courts (`strlen < 9`), donc la garde de validation est
     * redondante quant au résultat — retirer `min:9` laisse un 422. Elle n'est pas inutile pour
     * autant : une erreur de validation se rattache au champ `phone` et la tablette la surligne, là
     * où un refus du service n'affiche qu'un bandeau général. Un caissier pressé doit voir OÙ
     * corriger. C'est cette différence que ce test épingle — sinon la garde disparaîtrait un jour
     * « puisque le service refuse déjà ».
     */
    public function test_un_numero_incomplet_est_refuse_sur_le_champ_lui_meme(): void
    {
        $this->creer(['phone' => '0612'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, User::where('phone', 'like', '0612%')->count());
    }

    /** Une adresse invalide est refusée : sans adresse valable, le client ne reçoit jamais son code. */
    public function test_une_adresse_invalide_est_refusee(): void
    {
        $this->creer(['phone' => '0611224400', 'email' => 'pas-une-adresse'])->assertStatus(422);
        $this->assertSame(0, User::where('phone', '0611224400')->count());
    }

    // ── LA PORTE ─────────────────────────────────────────────────────────────────────────────

    /** Créer un compte client n'est pas une action publique. */
    public function test_sans_le_droit_caisse_personne_n_inscrit_de_client(): void
    {
        $this->postJson(self::URL, ['phone' => '0611225500'])->assertStatus(401);

        $quidam = User::factory()->create(['is_guest' => Ask::NO]);
        $quidam->assignRole('Customer');
        $this->actingAs($quidam, 'sanctum')
            ->withHeader('X-Idempotency-Key', 'x-'.bin2hex(random_bytes(6)))
            ->postJson(self::URL, ['phone' => '0611225500'])
            ->assertStatus(403);

        $this->assertSame(0, User::where('phone', '0611225500')->count());
    }
}
