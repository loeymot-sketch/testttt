<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\WheelSpin;
use App\Models\WheelStepProgress;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LE PARCOURS EN ÉTAPES — avis, puis abonnement, puis le tour.
 *
 * ── CE QU'ON PEUT VÉRIFIER, ET CE QU'ON NE PEUT PAS ──────────────────────────────────────────
 * Aucune API publique ne dit qu'une personne PRÉCISE a écrit un avis Google ou s'est abonnée. Ce
 * n'est pas une lacune du code, c'est une limite de ces plateformes. Ce qui EST vérifiable : que le
 * lien a été ouvert, et le TEMPS écoulé depuis. Personne n'écrit un avis en deux secondes.
 *
 * ── LA GARDE QUI COMPTE ──────────────────────────────────────────────────────────────────────
 * Le compteur de 20 secondes vit dans le NAVIGATEUR : il se contourne dans les outils de
 * développement, ou en rejouant simplement la requête de tour. C'est donc le SERVEUR qui horodate
 * l'ouverture du lien et recalcule le délai au moment du tour. Le client ne peut pas mentir sur
 * l'horloge du serveur — et c'est exactement ce que les tests ci-dessous prouvent.
 *
 * Verrouillé également :
 *   · l'ADRESSE est une seconde clé d'identité : franchir l'unicité du téléphone ne suffit pas ;
 *   · le lot porte le MINIMUM D'ACHAT — sans quoi on distribue des cadeaux sans commande ;
 *   · rouvrir le lien juste avant de tourner ne remet PAS le compteur à zéro dans le bon sens.
 */
class WheelStepsTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true);
        // [ROUE × CAISSE 2026-08-10] La roue ne tire plus de lot en REMISE quand la caisse
        // refuse les codes — c'est une garde neuve, et elle est juste. Ce banc parle d'autre
        // chose : on accepte donc les codes ici, pour qu'un interrupteur de la caisse ne
        // décide pas de ce qu'il éprouve.
        Config::set('pos.coupon_codes_enabled', true);
        Config::set('wheel.campaign_key', 'etapes');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.min_order_amount', 10.0);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('wheel.segments', [
            ['key' => 'r10', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 4.0],
        ]);
        Config::set('wheel.steps', [
            'review' => ['required' => true, 'url' => 'https://g.page/r/exemple/review', 'dwell_seconds' => 20, 'derive_fallback' => false],
            'follow' => ['required' => true, 'instagram' => 'https://instagram.com/lecayenne',
                         'snapchat' => 'https://snapchat.com/add/lecayenne', 'facebook' => '', 'dwell_seconds' => 8],
        ]);
    }

    private function cle(): array
    {
        return ['x-api-key' => (string) config('app.api_key')];
    }

    private function jeton(): string
    {
        return app(WheelUnlockService::class)->issue($this->branchId, 1)['token'];
    }

    private function ouvrir(string $jeton, string $step)
    {
        return $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/step', [
            'branch_id' => $this->branchId, 'step' => $step, 'unlock_token' => $jeton,
        ]);
    }

    /**
     * [DEUX TEMPS 2026-08-10] Le parcours complet : on TOURNE, puis on RÉCLAME. Si le tour est
     * refusé (étape non faite, trop rapide) c'est ce refus qu'on rend — enchaîner masquerait la
     * cause. Les gardes d'étapes s'appliquent aux DEUX appels : la réclamation les revérifie.
     */
    private function tourner(string $jeton, array $extra = [])
    {
        $tour = $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branchId, 'unlock_token' => $jeton,
        ]);

        if ($tour->status() !== 200) {
            return $tour;
        }

        return $this->reclamer($jeton, $extra['phone'] ?? '0611220000',
            $extra['email'] ?? 'client@exemple.fr', $extra);
    }

    private function reclamer(string $jeton, string $tel, string $mail, array $extra = [])
    {
        return $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/claim', $extra + [
            'branch_id' => $this->branchId,
            'phone' => $tel,
            'email' => $mail,
            'unlock_token' => $jeton,
        ]);
    }

    // ── 1. AUCUNE ÉTAPE FRANCHIE ──────────────────────────────────────────────────────────────

    public function test_sans_avoir_ouvert_le_lien_d_avis_le_tour_est_REFUSE(): void
    {
        $r = $this->tourner($this->jeton())->assertStatus(428);

        $this->assertMatchesRegularExpression('/avis/i', (string) $r->json('message'),
            'le refus doit dire QUOI FAIRE : un refus incompris est vécu comme une panne');
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count(),
            'un tour a été enregistré sans qu\'aucune étape soit faite');
    }

    public function test_avis_ouvert_mais_abonnement_non_ouvert_refuse_aussi(): void
    {
        $jeton = $this->jeton();
        $this->ouvrir($jeton, 'review')->assertOk();
        $this->travel(25)->seconds();

        $r = $this->tourner($jeton)->assertStatus(428);
        $this->assertMatchesRegularExpression('/abonne/i', (string) $r->json('message'));
    }

    // ── 2. LA GARDE DU TEMPS, CÔTÉ SERVEUR ────────────────────────────────────────────────────

    /**
     * LE CŒUR DE LA GARDE : le client ouvre les deux liens puis tourne IMMÉDIATEMENT, comme le
     * ferait quelqu'un qui contourne le compteur du navigateur. Le serveur doit refuser, parce que
     * c'est LUI qui a posé l'heure.
     */
    public function test_tourner_IMMEDIATEMENT_apres_avoir_ouvert_les_liens_est_refuse(): void
    {
        $jeton = $this->jeton();
        $this->ouvrir($jeton, 'review')->assertOk();
        $this->ouvrir($jeton, 'follow')->assertOk();

        $r = $this->tourner($jeton)->assertStatus(428);

        $this->assertMatchesRegularExpression('/seconde/i', (string) $r->json('message'),
            'le refus doit donner la durée restante — sinon le client croit à une panne');
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_apres_le_temps_ecoule_le_tour_est_ACCORDE(): void
    {
        $jeton = $this->jeton();
        $this->ouvrir($jeton, 'review')->assertOk();
        $this->travel(21)->seconds();
        $this->ouvrir($jeton, 'follow')->assertOk();
        $this->travel(9)->seconds();

        $r = $this->tourner($jeton)->assertOk();

        $this->assertTrue((bool) $r->json('status'));
        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $this->assertNotNull($spin->review_clicked_at, 'la trace de l\'étape doit être conservée');
        $this->assertNotNull($spin->follow_clicked_at);
        $this->assertGreaterThanOrEqual(20, (int) $spin->steps_seconds,
            'le temps total du parcours doit être enregistré : c\'est lui qui trahit un robot');
    }

    /**
     * ROUVRIR LE LIEN NE REMET PAS LE COMPTEUR À ZÉRO DANS LE BON SENS. Sinon il suffirait de
     * rouvrir juste avant de tourner… ce qui ferait REPARTIR l'attente, donc ce serait absurde dans
     * l'autre sens : ce qu'on protège ici, c'est que la PREMIÈRE ouverture reste la référence.
     */
    public function test_rouvrir_le_lien_ne_reinitialise_PAS_l_horodatage(): void
    {
        $jeton = $this->jeton();
        $this->ouvrir($jeton, 'review')->assertOk();
        $this->travel(21)->seconds();

        // Il rouvre le lien : l'heure d'origine doit être conservée.
        $this->ouvrir($jeton, 'review')->assertOk();
        $this->ouvrir($jeton, 'follow')->assertOk();
        $this->travel(9)->seconds();

        $this->tourner($jeton)->assertOk();

        $p = WheelStepProgress::firstOrFail();
        $this->assertTrue($p->review_opened_at->lessThan(now()->subSeconds(25)),
            'l\'horodatage de l\'avis a été écrasé par la seconde ouverture');
    }

    // ── 3. LES LIENS SONT PUBLIÉS, LE CONTRÔLE NE L'EST PAS ───────────────────────────────────

    public function test_la_configuration_publie_les_etapes_et_le_minimum_d_achat(): void
    {
        $brut = $this->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId)
            ->assertOk()->getContent();

        $this->assertStringContainsString('g.page', $brut, 'le lien d\'avis doit être fourni');
        $this->assertStringContainsString('instagram.com', $brut);
        $this->assertStringContainsString('snapchat.com', $brut);
        $this->assertStringContainsString('"min_order":10', $brut, 'le minimum d\'achat doit être annoncé AVANT');

        // Le contrôle d'abonnés est INVISIBLE du client — décision du propriétaire.
        $this->assertStringNotContainsString('followers', $brut,
            'le contrôle de cohérence fuite vers le client : il n\'a pas à savoir qu\'on compare les totaux');
        $this->assertStringNotContainsString('instagram_token', $brut);
    }

    // ── 4. L'ADRESSE, SECONDE CLÉ ─────────────────────────────────────────────────────────────

    private function parcoursComplet(string $tel, string $mail)
    {
        $jeton = $this->jeton();
        $this->ouvrir($jeton, 'review');
        $this->travel(21)->seconds();
        $this->ouvrir($jeton, 'follow');
        $this->travel(9)->seconds();

        return $this->tourner($jeton, ['phone' => $tel, 'email' => $mail]);
    }

    public function test_l_adresse_ne_rentre_pas_deux_fois_meme_avec_un_autre_numero(): void
    {
        $this->parcoursComplet('0611000001', 'meme@exemple.fr')->assertOk();

        // Numéro DIFFÉRENT, adresse identique : l'unicité du téléphone ne peut pas expliquer un
        // refus — seule celle de l'adresse peut. C'est ce qu'on veut prouver.
        $r = $this->parcoursComplet('0622000002', 'MEME@exemple.fr');

        $this->assertSame(409, $r->status(),
            'la même adresse a rejoué avec un autre numéro : il suffirait de deux cartes SIM');
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_le_numero_ne_rentre_pas_deux_fois_meme_avec_une_autre_adresse(): void
    {
        $this->parcoursComplet('0611000003', 'un@exemple.fr')->assertOk();

        $r = $this->parcoursComplet('06 11 00 00 03', 'deux@exemple.fr');

        $this->assertSame(409, $r->status(),
            'le même numéro a rejoué avec une autre adresse : les adresses sont gratuites et infinies');
    }

    public function test_une_adresse_invalide_est_refusee(): void
    {
        $jeton = $this->jeton();
        $this->ouvrir($jeton, 'review');
        $this->travel(21)->seconds();
        $this->ouvrir($jeton, 'follow');
        $this->travel(9)->seconds();

        $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branchId, 'unlock_token' => $jeton,
        ])->assertOk();

        $this->reclamer($jeton, '0611000004', 'pas-une-adresse')->assertStatus(422);
    }

    /**
     * L'UNICITÉ DE L'ADRESSE VIT EN BASE, pas dans un `if`. La mutation l'a montré : retirer le
     * contrôle applicatif ne casse rien, parce que la contrainte rattrape. C'est de la défense en
     * profondeur — le `if` sert à donner un message propre, la CONTRAINTE est la garantie. On prouve
     * donc la garantie en contournant le service.
     */
    public function test_l_unicite_de_l_adresse_est_garantie_par_la_BASE(): void
    {
        $this->parcoursComplet('0611000030', 'base@exemple.fr')->assertOk();
        $premier = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Insertion DIRECTE avec la MÊME adresse et un AUTRE numéro : seule une contrainte
        // d'unicité sur l'adresse peut encore refuser. Si ça passe, deux requêtes simultanées
        // franchiraient toutes les deux le `if`.
        $doublon = $premier->replicate();
        $doublon->phone = '0699999999';
        $doublon->save();
    }

    // ── 5. LE MINIMUM D'ACHAT ─────────────────────────────────────────────────────────────────

    public function test_le_lot_porte_le_MINIMUM_D_ACHAT(): void
    {
        $this->parcoursComplet('0611000005', 'min@exemple.fr')->assertOk();

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $coupon = Coupon::withoutGlobalScopes()->findOrFail($spin->coupon_id);

        $this->assertSame(10.0, (float) $coupon->minimum_order,
            'sans minimum d\'achat, on distribue des cadeaux à qui ne commande rien');
    }

    /**
     * ON NE PEUT PAS EXIGER CE QU'ON NE FOURNIT PAS. Une étape marquée « requise » mais SANS adresse
     * configurée rendait le jeu INJOUABLE : aucun lien à ouvrir, mais le serveur exigeait
     * l'horodatage — tout tour refusé en 428, indéfiniment, sans que personne comprenne. Le jeu
     * doit TOURNER en sautant l'étape, et le manque doit être SIGNALÉ à l'exploitant.
     */
    public function test_une_etape_requise_SANS_lien_est_sautee_et_signalee(): void
    {
        Config::set('wheel.steps', [
            'review' => ['required' => true, 'url' => '', 'dwell_seconds' => 20, 'derive_fallback' => false],
            'follow' => ['required' => true, 'instagram' => '', 'snapchat' => '', 'facebook' => '', 'dwell_seconds' => 8],
        ]);

        // Aucune étape ouverte, et pourtant le tour doit aboutir : elles ne sont pas fournies.
        $this->tourner($this->jeton(), ['phone' => '0611000040', 'email' => 'sanslien@exemple.fr'])
            ->assertOk();

        // Et le manque est nommé, pas tu.
        $manquantes = app(\App\Services\Wheel\WheelStepService::class)->missingLinks();
        $this->assertSame(['review', 'follow'], $manquantes,
            'le manque doit être signalé : sinon l\'exploitant croit que le jeu vérifie quelque '
            . 'chose qu\'il ne vérifie pas');

        $this->artisan('wheel:reconcile-claims')
            ->expectsOutputToContain('SANS lien configure')
            ->assertExitCode(0);
    }

    /** Et une étape sans lien n'est PAS publiée : afficher un bouton vers rien serait pire. */
    public function test_une_etape_sans_lien_n_est_pas_publiee_au_client(): void
    {
        Config::set('wheel.steps', [
            'review' => ['required' => true, 'url' => '', 'dwell_seconds' => 20, 'derive_fallback' => false],
            'follow' => ['required' => true, 'instagram' => 'https://instagram.com/lecayenne',
                         'snapchat' => '', 'facebook' => '', 'dwell_seconds' => 8],
        ]);

        $r = $this->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId)->assertOk();

        $cles = array_column($r->json('steps'), 'key');
        $this->assertSame(['follow'], $cles,
            'une étape sans lien est publiée : le client verrait un bouton qui ne mène nulle part');
    }

    // ── 6. L'AVIS PEUT ÊTRE DÉCONDITIONNÉ EN UN RÉGLAGE ───────────────────────────────────────

    /**
     * Google interdit de RÉCOMPENSER un avis. Ce réglage permet de basculer l'avis en simple
     * invitation sans toucher au code — la fiche Google vaut beaucoup plus que quelques avis.
     */
    public function test_l_avis_peut_devenir_une_simple_invitation(): void
    {
        Config::set('wheel.steps.review.required', false);

        $jeton = $this->jeton();
        // On n'ouvre PAS le lien d'avis. Seul l'abonnement est fait.
        $this->ouvrir($jeton, 'follow')->assertOk();
        $this->travel(9)->seconds();

        $this->tourner($jeton, ['phone' => '0611000006', 'email' => 'invit@exemple.fr'])->assertOk();

        // Et le lien reste PUBLIÉ : on invite toujours, on ne conditionne plus.
        $brut = $this->withHeaders($this->cle())
            ->getJson('/api/frontend/wheel/config?branch_id=' . $this->branchId)->getContent();
        $this->assertStringContainsString('g.page', $brut,
            'l\'invitation à laisser un avis doit rester visible même non requise');
    }
}
