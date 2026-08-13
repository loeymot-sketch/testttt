<?php

namespace Tests\Feature\Loyalty;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * « /loyalty/register NE REMET JAMAIS UN SOLDE À ZÉRO » — un piège désamorcé avant qu'il ne coûte.
 *
 * ── CE QUE L'AUDIT A TROUVÉ ──────────────────────────────────────────────────────────────────
 * En recensant TOUS les endroits qui déplacent `users.loyalty_points` (10 sites), un seul écrivait
 * un solde SANS ligne au grand-livre : `LoyaltyController::register()`, qui faisait
 *
 *     if (!$user->loyalty_code) { $user->loyalty_code = …; $user->loyalty_points = 0; }
 *
 * sur un compte EXISTANT retrouvé par téléphone, depuis un endpoint PUBLIC et NON AUTHENTIFIÉ.
 * L'intention est « un nouveau compte part de zéro » ; l'effet écrit est « tout compte sans code
 * repart de zéro ». Aucune ligne `loyalty_transactions` n'étant écrite, la perte serait INVISIBLE :
 * ni le client ni la caisse ne pourraient dire où sont passés les points.
 *
 * ── CE QUE J'AI MESURÉ AVANT DE CRIER AU FEU ─────────────────────────────────────────────────
 * L'état nécessaire (des points AVEC un `loyalty_code` NULL) n'existe NULLE PART : 0 compte en
 * développement, **0 compte en production** (25 adhérents ont un code, 5 comptes sans code ont 0
 * point). Et aucun chemin de crédit ne peut le créer : le crédit automatique et l'ajout manuel
 * résolvent le client PAR son code, le rattachement en caisse aussi. Donc ce n'était PAS un défaut
 * actif — c'était un piège qui n'attendait que le premier chemin créditant un compte sans code.
 *
 * On le referme quand même : la garde coûte une condition, la perte serait silencieuse, et
 * « personne ne peut créer cet état AUJOURD'HUI » n'est pas une protection, c'est un accident.
 */
class LoyaltyRegisterNeRemetPasLeSoldeAZeroTest extends TestCase
{
    use RefreshDatabase;

    private function inscrire(array $charge)
    {
        return $this->postJson('/api/frontend/loyalty/register', $charge,
            ['x-api-key' => config('app.api_key')]);
    }

    /**
     * LE CŒUR : un compte existant qui a des points et pas encore de code garde ses points.
     *
     * Il reçoit bien son code de fidélité au passage — c'est le service rendu ; ce qu'on interdit,
     * c'est que ce service s'accompagne d'une remise à zéro muette.
     */
    public function test_un_compte_existant_avec_des_points_ne_perd_rien_en_recevant_son_code(): void
    {
        $client = User::factory()->create(['phone' => '0655447788']);
        DB::table('users')->where('id', $client->id)
            ->update(['loyalty_code' => null, 'loyalty_points' => 500]);

        $this->inscrire(['name' => 'Client Fidele', 'phone' => '0655447788']);

        $apres = DB::table('users')->where('id', $client->id)->first();

        $this->assertSame(500, (int) $apres->loyalty_points,
            'les points d\'un compte existant ont été effacés par un endpoint public');
        $this->assertNotNull($apres->loyalty_code,
            'le compte devait au contraire RECEVOIR son code de fidélité');
    }

    /**
     * ET LA CONTREPARTIE : un compte réellement NOUVEAU part bien de zéro.
     *
     * Sans ce test, on pourrait « corriger » le défaut en supprimant la remise à zéro pour tout le
     * monde, et un nouveau compte hériterait d'une valeur de colonne inattendue.
     *
     * ⚠️ HONNÊTETÉ SUR CE QUE CELUI-CI PROUVE : la mutation « ne jamais remettre à zéro » lui SURVIT,
     * parce que la colonne `users.loyalty_points` vaut déjà 0 par défaut — la ligne du contrôleur est
     * donc redondante aujourd'hui. Ce test ne verrouille pas un comportement vivant : il verrouille
     * le jour où ce défaut de colonne changerait. Je le garde à ce titre, pas en prétendant qu'il
     * attrape une régression actuelle.
     */
    public function test_un_compte_reellement_nouveau_part_de_zero_avec_un_code(): void
    {
        $this->inscrire(['name' => 'Toute Nouvelle', 'phone' => '0655001234'])
            ->assertStatus(200);

        $cree = DB::table('users')->where('phone', '0655001234')->first();

        $this->assertNotNull($cree, 'le compte n\'a pas été créé');
        $this->assertSame(0, (int) $cree->loyalty_points, 'un nouveau compte doit partir de zéro');
        $this->assertNotNull($cree->loyalty_code, 'un nouveau compte doit recevoir un code');
    }

    /**
     * AUCUN SOLDE NE BOUGE SANS LIGNE AU GRAND-LIVRE — la règle générale que cet audit applique.
     *
     * C'est la même règle qui a fait trouver, le même jour, le crédit de la roue qui déplaçait un
     * solde sans rien inscrire. Ici on vérifie l'autre sens : puisque `register` ne doit RIEN
     * déplacer sur un compte existant, il ne doit produire AUCUNE ligne non plus.
     */
    public function test_register_n_ecrit_aucun_mouvement_de_points(): void
    {
        $client = User::factory()->create(['phone' => '0655447799']);
        DB::table('users')->where('id', $client->id)
            ->update(['loyalty_code' => null, 'loyalty_points' => 320]);

        $this->inscrire(['name' => 'Client Fidele', 'phone' => '0655447799']);

        $this->assertSame(0, (int) DB::table('loyalty_transactions')->where('user_id', $client->id)->count(),
            'register a écrit un mouvement de points alors qu\'il ne doit rien déplacer');
        $this->assertSame(320, (int) DB::table('users')->where('id', $client->id)->value('loyalty_points'));
    }
}
