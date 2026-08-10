<?php

namespace Tests\Feature\Wheel;

use App\Http\Middleware\EnsureWheelAccess;
use App\Models\Branch;
use App\Models\User;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LA PORTE DES ÉCRANS DE LA ROUE — le P0 le plus coûteux de tout ce chantier.
 *
 * ── CE QUI SE PASSAIT ────────────────────────────────────────────────────────────────────────
 * Les quatre écrans étaient gardés par `auth`. Or la garde par défaut est `sanctum`, la connexion
 * détruit la session web et rend un jeton Bearer, et une navigation de DOCUMENT ne porte jamais
 * d'en-tête `Authorization`. Après une connexion parfaitement normale, ouvrir un écran de la roue
 * rendait donc `{"errors":"unauthenticated"}` — un JSON, dans un navigateur, sans aucune issue.
 *
 * Personne ne pouvait ouvrir ces écrans. Y compris celui des réglages, construit précisément pour
 * que le propriétaire débloque le jeu tout seul : il était derrière une porte que personne ne
 * pouvait ouvrir. C'est la réponse à « ça marche toujours pas ».
 *
 * ── CE QUE CE BANC PROTÈGE ───────────────────────────────────────────────────────────────────
 * Deux choses, et il faut les deux : que la porte S'OUVRE pour qui a le droit (sinon le jeu
 * n'existe pas), et qu'elle reste FERMÉE pour les autres (ces écrans distribuent des lots).
 */
class WheelAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Les quatre écrans, tels qu'un navigateur les ouvre. */
    private const ECRANS = [
        '/admin/roue-validation',
        '/admin/roue-lot',
        '/admin/roue-reglages',
        '/admin/roue-borne',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        Branch::factory()->create();
        Config::set('wheel.access.pin', '481526');
        Config::set('wheel.access.session_minutes', 240);
        Config::set('wheel.counter_branch_id', 1);
        Config::set('wheel.public_url', 'https://exemple.test');
    }

    /**
     * LE BANC DE BASE POSE `Accept: application/json` POUR TOUTES LES REQUÊTES. Or ce qu'on éprouve
     * ici est justement le chemin du NAVIGATEUR : celui qui doit voir une page et pas un JSON. Sans
     * ce `Accept: text/html`, on testerait l'autre branche et on croirait avoir prouvé la bonne.
     */
    private function navigateur()
    {
        return $this->withHeaders(['Accept' => 'text/html,application/xhtml+xml']);
    }

    private function ouvrir(string $pin = '481526')
    {
        return $this->navigateur()->post('/admin/roue/ouvrir', ['pin' => $pin]);
    }

    // ── 1. LA PORTE FERMÉE MONTRE UNE PAGE, JAMAIS UN JSON ───────────────────────────────────

    /**
     * LE CŒUR DU DÉFAUT. Un navigateur qui ouvre une page doit voir une PAGE. L'ancienne redirection
     * menait au talon JSON de l'API : le personnel lisait `{"errors":"unauthenticated"}` et n'avait
     * aucun écran pour s'en sortir.
     */
    public function test_sans_code_chaque_ecran_renvoie_vers_l_accueil_et_jamais_vers_un_JSON(): void
    {
        foreach (self::ECRANS as $url) {
            $r = $this->navigateur()->get($url);

            $r->assertRedirect(route('admin.wheel.home'));
            $this->assertStringNotContainsString('unauthenticated', $r->getContent(),
                "L'écran $url rend un JSON d'erreur : le personnel n'a aucune issue");
        }
    }

    /** Et l'accueil, lui, est TOUJOURS ouvrable — sinon il n'y a plus de porte du tout. */
    public function test_l_accueil_est_toujours_atteignable_et_porte_le_champ_du_code(): void
    {
        $r = $this->navigateur()->get('/admin/roue')->assertOk();

        $r->assertSee('name="pin"', false);
        $this->assertStringNotContainsString('481526', $r->getContent(),
            'LE CODE EST ÉCRIT DANS LA PAGE : n\'importe qui le lirait dans le source');
    }

    // ── 2. LE CODE ───────────────────────────────────────────────────────────────────────────

    public function test_un_mauvais_code_n_ouvre_rien_et_le_dit(): void
    {
        $this->ouvrir('000000')->assertRedirect();
        $this->assertNull(session(EnsureWheelAccess::SESSION_KEY),
            'une session a été ouverte avec un code faux');

        $this->navigateur()->get('/admin/roue-reglages')->assertRedirect(route('admin.wheel.home'));
    }

    public function test_le_bon_code_ouvre_les_quatre_ecrans(): void
    {
        $this->ouvrir()->assertRedirect(route('admin.wheel.home'));
        $this->assertNotNull(session(EnsureWheelAccess::SESSION_KEY));

        foreach (self::ECRANS as $url) {
            $r = $this->navigateur()->get($url);
            $this->assertNotSame(302, $r->status(),
                "L'écran $url reste fermé après le bon code : le jeu est inutilisable");
            $this->assertNotSame(403, $r->status(),
                "L'écran $url répond 403 après que la porte l'a ouvert — une garde interne le "
                . 'referme (c\'était le défaut : chaque écran relisait $request->user())');
        }
    }

    /** L'accueil ouvert NOMME les quatre écrans : aucun lien n'y menait, il fallait taper les URL. */
    public function test_l_accueil_ouvert_liste_les_quatre_ecrans(): void
    {
        $this->ouvrir();

        $r = $this->navigateur()->get('/admin/roue')->assertOk();
        foreach (['roue-validation', 'roue-lot', 'roue-borne', 'roue-reglages'] as $ecran) {
            $r->assertSee($ecran, false);
        }
    }

    // ── 3. FAIL-CLOSED ───────────────────────────────────────────────────────────────────────

    /**
     * Sans code configuré, l'accès est REFUSÉ — pas ouvert. Ces écrans distribuent des lots : une
     * porte ouverte par défaut serait pire que la porte fermée qu'on répare.
     */
    public function test_sans_code_configure_rien_ne_s_ouvre_et_l_accueil_explique(): void
    {
        Config::set('wheel.access.pin', '');

        // Le fail-closed passe AVANT la validation, et c'est le bon ordre : on ne valide pas un
        // champ pour une porte qui n'existe pas. Le refus est donc un retour avec message, pas une
        // erreur de champ.
        $this->ouvrir('')->assertRedirect();
        $this->assertNull(session(EnsureWheelAccess::SESSION_KEY));
        $this->navigateur()->get('/admin/roue-reglages')->assertRedirect(route('admin.wheel.home'));

        $r = $this->navigateur()->get('/admin/roue')->assertOk();
        $r->assertSee('WHEEL_PIN', false);
        $this->assertStringNotContainsString('name="pin"', $r->getContent(),
            'un champ de code est proposé alors qu\'aucun code n\'existe : on invite à une impasse');
    }

    /**
     * RETIRER LE CODE REFERME LES SESSIONS EN COURS. Le Carnet a déjà eu exactement ce défaut : le
     * fail-closed ne bloquait que les NOUVEAUX déverrouillages, et une session déjà ouverte survivait
     * indéfiniment. Un code retiré doit fermer la porte tout de suite, y compris pour qui est dedans.
     */
    public function test_retirer_le_code_referme_immediatement_une_session_ouverte(): void
    {
        $this->ouvrir();
        $this->navigateur()->get('/admin/roue-lot')->assertOk();

        Config::set('wheel.access.pin', '');

        $this->navigateur()->get('/admin/roue-lot')->assertRedirect(route('admin.wheel.home'));
    }

    public function test_la_session_expire(): void
    {
        Config::set('wheel.access.session_minutes', 60);
        $this->ouvrir();
        $this->navigateur()->get('/admin/roue-lot')->assertOk();

        $this->travel(61)->minutes();

        $this->navigateur()->get('/admin/roue-lot')->assertRedirect(route('admin.wheel.home'));
    }

    public function test_refermer_ferme_vraiment(): void
    {
        $this->ouvrir();
        $this->navigateur()->post('/admin/roue/fermer')->assertRedirect(route('admin.wheel.home'));

        $this->navigateur()->get('/admin/roue-lot')->assertRedirect(route('admin.wheel.home'));
    }

    // ── 4. LE CHEMIN DE LA SESSION WEB N'EST PAS AFFAIBLI ────────────────────────────────────

    public function test_une_session_web_habilitee_passe_sans_aucun_code(): void
    {
        Config::set('wheel.access.pin', '');   // aucun code : seul ce chemin peut ouvrir

        $caissier = User::factory()->create(['branch_id' => 1]);
        $caissier->givePermissionTo('pos');

        $this->actingAs($caissier, 'web')->navigateur()->get('/admin/roue-lot')->assertOk();
    }

    public function test_une_session_web_SANS_habilitation_reste_dehors(): void
    {
        Config::set('wheel.access.pin', '');

        $quidam = User::factory()->create(['branch_id' => 1]);

        $this->actingAs($quidam, 'web')->navigateur()
            ->get('/admin/roue-lot')
            ->assertRedirect(route('admin.wheel.home'));
    }

    // ── 5. CE QUE LE CORPS DE LA REQUÊTE NE PEUT PAS FAIRE ──────────────────────────────────

    /**
     * LA BRANCHE NE VIENT JAMAIS DE LA REQUÊTE. Sur le chemin du code il n'y a pas de compte, donc
     * plus de `branch_id` venu d'un utilisateur : la tentation serait de le lire dans le corps. Ce
     * serait valider un tour chez le voisin.
     */
    public function test_la_branche_ne_peut_pas_etre_choisie_par_le_client(): void
    {
        $autre = Branch::factory()->create();
        Config::set('wheel.counter_branch_id', 1);

        $this->ouvrir();

        $r = $this->navigateur()->post('/admin/roue-validation', ['branch_id' => $autre->id])->assertOk();

        // On relit le jeton émis. Il n'est PAS écrit en clair dans la page — c'est voulu, un jeton
        // recopiable circulerait — donc on le prend dans les données de la vue, pas dans le HTML.
        $donnees = $r->original->getData();
        $this->assertArrayHasKey('token', $donnees, 'aucun jeton n\'a été émis : le test ne prouve rien');
        $this->assertNotEmpty($donnees['token']);

        $v = app(WheelUnlockService::class)->verify((string) $donnees['token']);
        $this->assertSame(1, (int) $v['branch_id'],
            'la branche vient du CORPS de la requête : on valide des tours chez le voisin');
        $this->assertNotSame((int) $autre->id, (int) $v['branch_id']);
    }

    /** Une requête JSON reçoit du JSON — on ne renvoie pas une page à un appel programmatique. */
    public function test_un_appel_json_recoit_du_json_et_pas_une_redirection(): void
    {
        $r = $this->getJson('/admin/roue-lot');

        $r->assertStatus(401)->assertJsonPath('locked', true);
    }

    /**
     * DEUX REFUS DISTINCTS, et la distinction compte.
     *
     * « aucun code n'est configuré sur cette machine » (403) n'est pas « entre le code » (401) : le
     * premier appelle une intervention sur le serveur, le second un geste du personnel. Confondre les
     * deux envoie l'équipe chercher un code qui n'existe pas.
     *
     * Cette assertion rend aussi la garde du fail-closed OBSERVABLE : sans elle, la retirer ne casse
     * rien — l'accès reste refusé par la garde de session juste en dessous — et on ne saurait pas que
     * le message est devenu faux.
     */
    public function test_le_refus_distingue_pas_de_code_configure_de_code_a_saisir(): void
    {
        Config::set('wheel.access.pin', '');
        $this->getJson('/admin/roue-lot')->assertStatus(403);

        Config::set('wheel.access.pin', '481526');
        $this->getJson('/admin/roue-lot')->assertStatus(401);
    }
}
