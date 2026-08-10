<?php

namespace Tests\Feature\Wheel;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Wheel\WheelSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * LES ÉCRANS DE L'ÉQUIPE — CE QU'ILS DISENT, ET SURTOUT CE QU'ILS NE DOIVENT PLUS MENTIR.
 *
 * L'audit E2E du 2026-08-10 (vague C) a trouvé les trois écrans techniquement fonctionnels et
 * pourtant inutilisables, pour une seule raison de fond : ils affirmaient des choses fausses.
 *
 *   1. la bannière des réglages annonçait « Le parcours tourne » en vert au-dessus de champs TOUS
 *      VIDES — elle interrogeait le moteur (qui compte un lien de secours) et non le patron ;
 *   2. vider un champ était IMPOSSIBLE : la chaîne vide était bien écrite en base, la lecture la
 *      remplaçait par la valeur par défaut, et l'écran affichait quand même « enregistré » ;
 *   3. toute saisie refusée par le serveur disparaissait en SILENCE (302, page identique) ;
 *   4. l'écran de remise engageait un cadeau sans montrer le nom enregistré, sans rappeler la
 *      condition d'achat, et sans jamais afficher le code du coupon que le client vient chercher ;
 *   5. la consigne de validation réclamait « les deux comptes » que le client ne s'était jamais vu
 *      proposer ;
 *   6. une session expirée affichait « 419 PAGE EXPIRED », en anglais, sans issue.
 *
 * Chaque test ci-dessous éprouve UNE de ces affirmations. Ils ont tous été vus rouges par mutation du
 * code corrigé avant d'être livrés verts.
 */
class WheelOperatorScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $caissier;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $branche = Branch::factory()->create([
            'name' => 'Le Cayenne (principal)',
            'address' => '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont',
            'zip_code' => '62110',
            'city' => 'Hénin-Beaumont',
        ]);
        $this->branchId = (int) $branche->id;
        $this->caissier = User::factory()->create(['branch_id' => $branche->id]);
        $this->caissier->givePermissionTo('pos');

        // Départ : la configuration livrée fournit une page Facebook (elle est déjà publique sur le
        // site du restaurant) et RIEN d'autre. C'est l'état réel de la production.
        Config::set('wheel.steps', [
            'review' => ['required' => true, 'url' => '', 'dwell_seconds' => 20, 'derive_fallback' => true],
            'follow' => [
                'required' => true, 'instagram' => '', 'snapchat' => '',
                'facebook' => 'https://www.facebook.com/LeCayenne', 'dwell_seconds' => 8,
            ],
        ]);
        Config::set('wheel.public_url', 'https://exemple.test');
        Config::set('wheel.min_order_amount', 12);
    }

    /** La porte des écrans accepte une session web habilitée : c'est ce chemin qu'on emprunte ici. */
    private function comptoir()
    {
        return $this->actingAs($this->caissier, 'web');
    }

    // ── 1. LA BANNIÈRE DIT LA VÉRITÉ, CHAMP PAR CHAMP ────────────────────────────────────────

    /**
     * LE DÉFAUT CENTRAL. Le moteur peut tourner (lien de secours + Facebook livré par défaut) et
     * pourtant le patron n'a RIEN réglé. L'écran doit dire les deux : « ça tourne » n'est pas
     * « c'est le tien ».
     */
    public function test_la_banniere_ne_pretend_PAS_que_c_est_regle_quand_le_patron_n_a_rien_saisi(): void
    {
        $svc = app(WheelSettingsService::class);

        // Le moteur, lui, est bien prêt — c'est un choix assumé, on ne le casse pas.
        $this->assertTrue($svc->journeyReady(), 'le repli du 2026-08-09 a disparu : le jeu attendrait de nouveau');
        $this->assertFalse($svc->configuredByOperator(),
            'l\'écran croit que le patron a réglé son jeu alors qu\'il n\'a rien saisi');

        $r = $this->comptoir()->get('/admin/roue-reglages')->assertOk();

        $r->assertSee('Aucun lien n\'est de TOI', false);
        $r->assertDontSee('Le parcours tourne.', false);
        // Et le bilan NOMME chaque champ, avec son état réel.
        $r->assertSee('lien de secours', false);
        $r->assertSee('valeur livrée par défaut', false);
        $r->assertSee('absent', false);
    }

    /** Un lien collé, et la bannière passe au vert — mais elle continue de nommer ce qui manque. */
    public function test_un_lien_colle_fait_passer_la_banniere_au_vert_sans_cacher_les_manques(): void
    {
        $this->comptoir()->post('/admin/roue-reglages', [
            'review_url' => 'https://g.page/r/Cdirect/review',
        ])->assertOk();

        $svc = app(WheelSettingsService::class);
        $this->assertTrue($svc->configuredByOperator());

        $r = $this->comptoir()->get('/admin/roue-reglages')->assertOk();
        $r->assertSee('Le parcours tourne.', false);
        // Instagram et Snapchat sont toujours vides : l'écran doit le dire, pas se taire.
        $r->assertSee('absent', false);

        $etats = collect($svc->linkStatuses())->keyBy('cle');
        $this->assertSame('saisi', $etats['review_url']['etat']);
        $this->assertSame('defaut', $etats['facebook_url']['etat']);
        $this->assertSame('absent', $etats['instagram_url']['etat']);
    }

    // ── 2. VIDER UN CHAMP RETIRE VRAIMENT LE COMPTE ──────────────────────────────────────────

    /**
     * « Le patron retire un lien, on lui dit oui, et le lien revient. » La chaîne vide était écrite en
     * base puis jetée à la lecture, et la valeur par défaut reprenait la main.
     */
    public function test_vider_un_champ_retire_VRAIMENT_le_compte(): void
    {
        // Il colle d'abord SA page, puis change d'avis et la retire.
        $this->comptoir()->post('/admin/roue-reglages', [
            'facebook_url' => 'https://www.facebook.com/MaPage',
        ])->assertOk();
        $this->assertSame('https://www.facebook.com/MaPage', app(WheelSettingsService::class)->facebookUrl());

        $r = $this->comptoir()->post('/admin/roue-reglages', ['facebook_url' => ''])->assertOk();

        $this->assertSame('', app(WheelSettingsService::class)->facebookUrl(),
            'le compte retiré est revenu tout seul : le choix du patron a été écrasé par la configuration');
        $this->assertStringNotContainsString('facebook.com/LeCayenne', $r->getContent(),
            'la page livrée par défaut est réaffichée dans le champ : le retrait n\'a servi à rien');

        // Et le retrait SURVIT au rechargement — c'était le second symptôme.
        $r2 = $this->comptoir()->get('/admin/roue-reglages')->assertOk();
        $this->assertStringNotContainsString('facebook.com/LeCayenne', $r2->getContent());
        $r2->assertSee('Facebook</b> : retiré par toi', false);
    }

    /** Une clé JAMAIS écrite garde bien sa valeur de départ : on n'a pas cassé la garde, on l'a déplacée. */
    public function test_une_cle_jamais_touchee_garde_sa_valeur_de_depart(): void
    {
        $this->assertSame('https://www.facebook.com/LeCayenne',
            app(WheelSettingsService::class)->facebookUrl(),
            'la valeur de départ ne s\'applique plus : le jeu partirait vide au premier démarrage');
    }

    // ── 3. UNE SAISIE REFUSÉE REVIENT AVEC LE MOTIF ET LA VALEUR TAPÉE ───────────────────────

    public function test_une_saisie_refusee_revient_avec_le_motif_et_ce_qui_a_ete_tape(): void
    {
        $r = $this->comptoir()->post('/admin/roue-reglages', [
            'review_url' => 'instagram.com/lecayenne',   // sans schéma
            'review_dwell' => 9999,                       // hors bornes
        ]);

        $r->assertRedirect(url('/admin/roue-reglages'));
        $r->assertSessionHasErrors(['review_url', 'review_dwell']);

        // Rien n'a été enregistré : c'est la moitié de la promesse.
        $svc = app(WheelSettingsService::class);
        $this->assertSame(20, $svc->reviewDwell(), 'une valeur hors bornes a été enregistrée');
        $this->assertFalse($svc->configuredByOperator());

        // L'autre moitié : l'écran le DIT, et ne fait pas retaper ce qui vient d'être tapé.
        $suite = $this->comptoir()->get('/admin/roue-reglages')->assertOk();
        $suite->assertSee('Rien n\'a été enregistré.', false);
        $suite->assertSee('va de 0 à 180 secondes.', false);
        $suite->assertSee('value="9999"', false);
        $suite->assertSee('instagram.com/lecayenne', false);
    }

    /** Les bornes sont ÉCRITES à l'écran : une limite qu'on découvre en étant refusé n'est pas une aide. */
    public function test_les_bornes_sont_ecrites_dans_l_aide(): void
    {
        $r = $this->comptoir()->get('/admin/roue-reglages')->assertOk();

        $r->assertSee('de 0 à 180 secondes', false);
        $r->assertSee('De 0 à 200 €', false);
    }

    /**
     * Une adresse doit être OUVRABLE depuis un téléphone. `javascript:` était déjà refusé ; `ftp://`
     * passait — un lien qui n'ouvre rien sur la tablette du client.
     */
    public function test_une_adresse_non_ouvrable_est_refusee_et_le_motif_est_dit(): void
    {
        foreach (['javascript:alert(1)', 'ftp://exemple.test/x'] as $mauvaise) {
            $r = $this->comptoir()->post('/admin/roue-reglages', ['instagram_url' => $mauvaise]);

            $r->assertRedirect(url('/admin/roue-reglages'));
            $r->assertSessionHasErrors('instagram_url');
            $this->assertSame('', app(WheelSettingsService::class)->instagramUrl(),
                "une adresse inouvrable a été enregistrée : $mauvaise");
        }
    }

    // ── 4. L'ÉCRAN DE REMISE MONTRE CE QU'IL ENGAGE ──────────────────────────────────────────

    public function test_l_ecran_de_remise_montre_le_nom_enregistre_et_la_condition_d_achat(): void
    {
        $this->tour('0611000001', ['prize_type' => 'free_item', 'prize_label' => 'Boisson offerte',
            'customer_name' => 'Client Audit Un']);

        $r = $this->comptoir()->get('/admin/roue-lot?phone=0611000001')->assertOk();

        $r->assertSee('Client Audit Un', false);
        $r->assertSee('12 € minimum', false);
        $r->assertSee('REMIS AU CLIENT', false);
        // On ne laisse pas croire que le logiciel contrôle la commande : il ne le fait pas.
        $r->assertSee('Le logiciel ne peut pas le contrôler', false);
    }

    /** Le code du coupon existe en base et n'apparaissait sur AUCUNE surface comptoir. */
    public function test_l_historique_affiche_le_code_du_coupon_et_son_minimum(): void
    {
        $spin = $this->tour('0611000002', ['prize_type' => 'coupon_percent', 'prize_label' => '-10%']);
        $this->coupon($spin, 'ROUE-TEST12', 12.0);

        $r = $this->comptoir()->get('/admin/roue-lot?phone=0611000002')->assertOk();

        $r->assertSee('ROUE-TEST12', false);
        $r->assertSee('minimum 12 €', false);
        // Et le message tranche : ce n'est PAS « déjà remis ».
        $r->assertSee('son lot est une remise', false);
        $r->assertDontSee('déjà remis', false);
        $r->assertDontSee('REMIS AU CLIENT', false);
    }

    /** Deux situations opposées ne peuvent pas partager un message à deux branches. */
    public function test_un_lot_deja_remis_ne_dit_PAS_la_meme_chose_qu_une_remise_a_saisir(): void
    {
        $this->tour('0611000003', ['prize_type' => 'free_item', 'prize_label' => 'Frites offertes',
            'delivered_at' => now()->subDay()]);

        $r = $this->comptoir()->get('/admin/roue-lot?phone=0611000003')->assertOk();

        $r->assertSee('déjà remis', false);
        $r->assertDontSee('à saisir sur le site', false);
    }

    public function test_un_numero_incomplet_le_dit_au_lieu_d_accuser_le_client(): void
    {
        $r = $this->comptoir()->get('/admin/roue-lot?phone=0612')->assertOk();

        $r->assertSee('Numéro incomplet', false);
        $r->assertDontSee('Aucun tour à ce numéro', false);
    }

    // ── 5. LA CONSIGNE DE VALIDATION SUIT LES RÉGLAGES ───────────────────────────────────────

    /**
     * « Il est abonné aux deux comptes » était écrit en dur : l'équipe réclamait Instagram et Snapchat
     * alors que seul Facebook existait dans le parcours du client.
     */
    public function test_la_consigne_ne_nomme_que_les_reseaux_reellement_renseignes(): void
    {
        $r = $this->comptoir()->get('/admin/roue-validation')->assertOk();

        $r->assertSee('notre page Facebook', false);
        $r->assertDontSee('aux deux comptes', false);
        $r->assertDontSee('notre Instagram', false);
        $r->assertDontSee('notre Snapchat', false);

        // Le patron ajoute Instagram : la consigne le suit, sans redéploiement.
        $this->comptoir()->post('/admin/roue-reglages', [
            'instagram_url' => 'https://instagram.com/lecayenne',
            'follow_required' => '1',
        ])->assertOk();

        $this->comptoir()->get('/admin/roue-validation')->assertOk()
            ->assertSee('notre Instagram', false);
    }

    /** L'avis n'est réclamé que s'il est exigé — sinon on demande au client ce qu'on ne lui a pas demandé. */
    public function test_l_avis_n_est_reclame_que_s_il_est_exige(): void
    {
        $this->comptoir()->post('/admin/roue-reglages', [
            'facebook_url' => 'https://www.facebook.com/MaPage',
            'follow_required' => '1',
            // review_required absent = décoché
        ])->assertOk();

        $this->comptoir()->get('/admin/roue-validation')->assertOk()
            ->assertDontSee('avis Google', false);
    }

    /**
     * L'écran reste ouvert des heures au comptoir : il se recharge tout seul pour que le jeton du
     * formulaire ne périme pas. Jamais pendant qu'un client scanne le QR — il disparaîtrait.
     */
    public function test_l_ecran_d_attente_se_recharge_pour_ne_pas_perimer(): void
    {
        $this->comptoir()->get('/admin/roue-validation')->assertOk()
            ->assertSee('http-equiv="refresh"', false);
    }

    // ── 6. LA SESSION EXPIRÉE PARLE FRANÇAIS ─────────────────────────────────────────────────

    /**
     * L'état le plus probable en service, et le seul qui n'avait aucune vue : la plateforme servait
     * « 419 | PAGE EXPIRED », en anglais, fond blanc, sans issue.
     */
    public function test_la_page_de_session_expiree_est_en_francais_avec_une_issue(): void
    {
        $this->assertTrue(view()->exists('errors.419'),
            'aucune vue 419 : l\'équipe retombe sur la page anglaise de la plateforme');

        $html = (string) $this->view('errors.419');

        $this->assertStringContainsString('Recharger l\'écran', $html);
        $this->assertStringContainsString('Rien n\'a été enregistré', $html);
        $this->assertStringNotContainsString('PAGE EXPIRED', $html);
        $this->assertStringContainsString('lang="fr"', $html);
    }

    // ── Outils ───────────────────────────────────────────────────────────────────────────────

    private function tour(string $phone, array $extra = []): WheelSpin
    {
        return WheelSpin::create(array_merge([
            'branch_id' => $this->branchId,
            'campaign_key' => (string) config('wheel.campaign_key'),
            'phone' => preg_replace('/\D+/', '', $phone),
            'email' => null,
            'prize_key' => 'test',
            'prize_label' => 'Lot de test',
            'prize_type' => 'free_item',
            'prize_value' => 0,
            'points_awarded' => 0,
            'unlock_method' => 'staff',
            'unlock_token_hash' => hash('sha256', uniqid('t', true)),
            'created_at' => now(),
        ], $extra));
    }

    private function coupon(WheelSpin $spin, string $code, float $minimum): Coupon
    {
        $coupon = new Coupon();
        $coupon->forceFill([
            'name' => 'Roue — test',
            'code' => $code,
            'discount' => 10,
            'discount_type' => \App\Enums\DiscountType::PERCENTAGE,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'status' => \App\Enums\Status::ACTIVE,
            'max_uses_global' => 1,
            'limit_per_user' => 1,
            'minimum_order' => $minimum,
            'maximum_discount' => 4,
            'usage_count' => 0,
        ])->save();

        $spin->coupon_id = $coupon->id;
        $spin->save();

        return $coupon;
    }
}
