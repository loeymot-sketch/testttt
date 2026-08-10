<?php

namespace Tests\Feature\Wheel;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\WheelSpin;
use App\Mail\WheelPrizeMail;
use App\Models\WheelStepProgress;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * LE PARCOURS EN DEUX TEMPS — tourner d'abord, s'identifier ensuite.
 *
 * ── L'ARBITRAGE ──────────────────────────────────────────────────────────────────────────────
 * « Je veux profiter, lorsqu'il va gagner, d'une dernière étape pour débloquer et voir le code
 * qu'il a gagné […] on va lui créer un compte en même temps, ça va être inscrit avec son numéro et
 * e-mail pour recevoir le code. »
 *
 * Deux champs demandés AVANT le tour, c'est un effort contre une promesse. Les mêmes deux champs
 * demandés APRÈS, c'est un effort contre un lot déjà visible à l'écran. Ce banc prouve que le
 * découpage tient — et surtout qu'il n'a ouvert AUCUNE des portes qu'un tel découpage invite.
 *
 * ── LES QUATRE PORTES QUE LE DÉCOUPAGE POUVAIT OUVRIR ────────────────────────────────────────
 *   1. RE-TIRER — recharger la page pendant l'animation pour retenter jusqu'à gagner gros. Le lot
 *      est donc figé sur la progression au premier tour, et relu tel quel ensuite.
 *   2. RÉCLAMER SANS TOURNER — appeler directement la réclamation. Refusé : sans lot en attente, il
 *      n'y a rien à réclamer.
 *   3. RÉCLAMER À L'INFINI — un jeton photographié, un lot, plusieurs personnes. Refusé en base.
 *   4. SE FAIRE PASSER POUR QUELQU'UN — le compte créé à la réclamation ne doit jamais devenir une
 *      session, ni toucher un compte de l'équipe, ni déplacer l'adresse d'un autre.
 */
class WheelClaimAccountTest extends TestCase
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
        Config::set('wheel.campaign_key', 'test-claim');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.claim_window_minutes', 30);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        // Étapes neutralisées : ce banc éprouve le DÉCOUPAGE et le COMPTE, pas le parcours. Les
        // mêler rendrait chaque échec ambigu (WheelStepsTest couvre les étapes).
        Config::set('wheel.steps', [
            'review' => ['required' => false, 'url' => '', 'dwell_seconds' => 0, 'derive_fallback' => false],
            'follow' => ['required' => false, 'instagram' => '', 'snapchat' => '', 'facebook' => '', 'dwell_seconds' => 0],
        ]);
        Config::set('wheel.segments', [
            ['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 1, 'daily_cap' => 0, 'max_discount' => 5],
            ['key' => 'b', 'label' => 'Une boisson offerte', 'type' => 'free_item', 'value' => 0,
             'weight' => 0, 'daily_cap' => 0],
        ]);
    }

    private function cle(): array
    {
        return ['x-api-key' => (string) config('app.api_key')];
    }

    private function jeton(?int $branchId = null): string
    {
        return app(WheelUnlockService::class)->issue($branchId ?? $this->branchId, 1)['token'];
    }

    private function tourner(string $jeton)
    {
        return $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/spin', [
            'branch_id' => $this->branchId, 'unlock_token' => $jeton,
        ]);
    }

    private function reclamer(string $jeton, string $tel, string $mail, ?string $nom = null)
    {
        return $this->withHeaders($this->cle())->postJson('/api/frontend/wheel/claim', [
            'branch_id' => $this->branchId, 'unlock_token' => $jeton,
            'phone' => $tel, 'email' => $mail, 'name' => $nom,
        ]);
    }

    // ── 1. LE TOUR NE DONNE RIEN ─────────────────────────────────────────────────────────────

    /**
     * Le lot est ANNONCÉ mais pas LIVRÉ. C'est tout l'intérêt du découpage : ce qui donne envie de
     * remplir le formulaire est visible, ce qui a de la valeur ne l'est pas encore.
     */
    public function test_le_tour_annonce_le_lot_mais_ne_livre_NI_code_NI_participation(): void
    {
        $r = $this->tourner($this->jeton())->assertOk();

        $this->assertSame('-10%', $r->json('prize_label'), 'le lot doit être annoncé pour donner envie');
        $this->assertNull($r->json('code'), 'LE CODE A FUITÉ AU TOUR : le formulaire ne sert alors à rien');

        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count(),
            'une participation a été créée sans identité');
        $this->assertSame(0, Coupon::withoutGlobalScopes()->where('code', 'like', 'ROUE-%')->count(),
            'un coupon a été émis avant toute réclamation : celui qui tourne et s\'en va coûterait de l\'argent');
    }

    /** Le lot en attente est bien porté par la progression, prêt pour la réclamation. */
    public function test_le_lot_est_mis_EN_ATTENTE_sur_la_progression(): void
    {
        $this->tourner($this->jeton())->assertOk();

        $p = WheelStepProgress::firstOrFail();
        $this->assertSame('a', $p->prize_key);
        $this->assertNotNull($p->spun_at, 'sans horodatage du tour, la fenêtre de réclamation ne peut pas expirer');
    }

    // ── 2. ON NE RE-TIRE PAS ─────────────────────────────────────────────────────────────────

    /**
     * LA FAILLE CLASSIQUE DU DÉCOUPAGE : recharger la page pendant l'animation pour retenter.
     *
     * La preuve est rendue DÉCISIVE en retournant les poids entre les deux appels : après ce
     * changement, un tirage neuf ne PEUT PLUS donner « a ». Si le second tour rend quand même « a »,
     * c'est qu'il a relu le lot au lieu d'en tirer un nouveau.
     */
    public function test_recharger_pendant_l_animation_ne_RE_TIRE_pas(): void
    {
        $jeton = $this->jeton();
        $premier = $this->tourner($jeton)->assertOk();
        $this->assertSame('a', $premier->json('prize_label') === '-10%' ? 'a' : 'autre');

        // À partir d'ici, tout tirage neuf donne obligatoirement « b ».
        Config::set('wheel.segments', [
            ['key' => 'a', 'label' => '-10%', 'type' => 'coupon_percent', 'value' => 10,
             'weight' => 0, 'daily_cap' => 0, 'max_discount' => 5],
            ['key' => 'b', 'label' => 'Une boisson offerte', 'type' => 'free_item', 'value' => 0,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $second = $this->tourner($jeton)->assertOk();

        $this->assertSame('-10%', $second->json('prize_label'),
            'RE-TIRAGE : recharger la page permet de retenter jusqu\'à gagner le gros lot');
        $this->assertSame(1, WheelStepProgress::count(), 'une seconde progression a été créée');
    }

    // ── 3. RÉCLAMER SANS AVOIR TOURNÉ ────────────────────────────────────────────────────────

    public function test_reclamer_sans_avoir_tourne_est_REFUSE(): void
    {
        $r = $this->reclamer($this->jeton(), '0611000010', 'sanstour@exemple.fr');

        $this->assertSame(410, $r->status(),
            'un lot a été livré sans qu\'aucun tour n\'ait eu lieu');
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    public function test_passe_la_fenetre_le_lot_n_est_plus_reclamable(): void
    {
        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();

        $this->travel(31)->minutes();

        $r = $this->reclamer($jeton, '0611000011', 'tard@exemple.fr');

        $this->assertSame(410, $r->status(),
            'un lot laissé en attente reste réclamable indéfiniment, hors de tout plafond journalier');
        $this->assertMatchesRegularExpression('/expir|rescanne/i', (string) $r->json('message'),
            'le refus doit dire QUOI FAIRE');
    }

    // ── 4. UN JETON, UN LOT, UNE PERSONNE ────────────────────────────────────────────────────

    public function test_le_lot_du_meme_jeton_ne_peut_pas_etre_reclame_DEUX_FOIS(): void
    {
        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $this->reclamer($jeton, '0611000012', 'premier@exemple.fr')->assertOk();

        // Identité TOTALEMENT différente : ni le téléphone ni l'adresse ne peuvent expliquer un
        // refus. Seul l'usage unique du jeton peut. C'est le jeton photographié et partagé.
        $r = $this->reclamer($jeton, '0622000013', 'second@exemple.fr');

        $this->assertNotEquals(200, $r->status(),
            'un jeton photographié a livré DEUX lots : toute la table du restaurant joue avec un seul');
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
    }

    // ── 5. LE COMPTE CRÉÉ À LA RÉCLAMATION ───────────────────────────────────────────────────

    public function test_la_reclamation_CREE_le_compte_du_client(): void
    {
        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();

        $r = $this->reclamer($jeton, '06 11 00 00 20', 'nouveau@exemple.fr', 'Camille')->assertOk();

        $this->assertTrue((bool) $r->json('account_created'));
        $this->assertNotNull($r->json('code'), 'le code doit apparaître À LA RÉCLAMATION');

        $u = User::withoutGlobalScopes()->where('phone', '0611000020')->firstOrFail();

        $this->assertSame('Camille', $u->name);
        $this->assertSame('nouveau@exemple.fr', $u->email, 'sans adresse, il ne peut pas recevoir son code de connexion');
        $this->assertSame((int) Ask::YES, (int) $u->is_guest, 'un compte client doit rester un compte invité');
        $this->assertNotEmpty($u->loyalty_code, 'sans code de fidélité, il cumule zéro point malgré la promesse');
        $this->assertTrue($u->hasRole(\App\Enums\Role::CUSTOMER));
        $this->assertNull($u->email_verified_at,
            'l\'adresse est marquée VÉRIFIÉE alors que personne n\'a prouvé qu\'elle est à lui');
    }

    /**
     * AUCUNE SESSION ÉMISE ICI. La réclamation est un point d'entrée public dont le seul « secret »
     * est un numéro de téléphone. Rendre un jeton d'accès en échange d'un numéro serait un
     * contournement d'authentification complet : il suffirait de réclamer avec le numéro d'un autre.
     */
    public function test_la_reclamation_n_emet_AUCUNE_session(): void
    {
        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();

        $r = $this->reclamer($jeton, '0611000021', 'session@exemple.fr')->assertOk();

        $brut = $r->getContent();
        $this->assertStringNotContainsString('token', $brut,
            'UN JETON D\'ACCÈS EST RENDU : réclamer avec le numéro d\'un autre donnerait sa session');
        $this->assertGuest();
    }

    /** Le client qui a DÉJÀ commandé ne doit pas se retrouver avec deux comptes et deux soldes. */
    public function test_un_client_EXISTANT_ne_recoit_pas_un_second_compte(): void
    {
        // Le numéro est stocké SANS le zéro initial — forme réellement présente en base (constatée :
        // « 600099482 »). C'est le seul fixture qui éprouve la recherche multi-écritures : avec
        // « 0612345678 », la normalisation produit exactement la valeur stockée et une recherche
        // naïve suffirait, si bien que le test passerait sans rien prouver.
        $existant = User::factory()->create([
            'phone' => '612345678', 'branch_id' => 0, 'is_guest' => Ask::YES,
            'name' => 'Dorian', 'email' => null,
        ]);
        $existant->assignRole(\App\Enums\Role::CUSTOMER);

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();

        // Même humain, écriture internationale du numéro.
        $r = $this->reclamer($jeton, '+33612345678', 'dorian@exemple.fr', 'Dorian')->assertOk();

        $this->assertFalse((bool) $r->json('account_created'));
        $this->assertTrue((bool) $r->json('account_ready'));

        $this->assertSame(1, User::withoutGlobalScope(BranchScope::class)
            ->whereIn('phone', ['0612345678', '612345678', '+33612345678'])->count(),
            'DOUBLON : deux comptes pour un seul humain, donc deux soldes de points');
        $this->assertSame((int) $existant->id, (int) User::withoutGlobalScope(BranchScope::class)
            ->whereIn('phone', ['0612345678', '612345678', '+33612345678'])->value('id'),
            'le compte retrouvé n\'est pas celui qui existait');
        $this->assertSame('dorian@exemple.fr', $existant->fresh()->email,
            'l\'adresse doit être rattachée au compte existant — c\'est par là que passe sa connexion');
    }

    /**
     * UN NUMÉRO N'EST PAS UNE PREUVE D'IDENTITÉ. Si le numéro porte un compte de l'équipe, on n'y
     * touche pas : ni création, ni renommage, ni rattachement d'adresse. C'est très probablement le
     * propriétaire qui teste avec son propre numéro — et le lot doit passer quand même.
     */
    public function test_un_numero_de_l_EQUIPE_n_est_pas_transforme_en_compte_client(): void
    {
        $staff = User::factory()->create([
            'phone' => '0699887766', 'branch_id' => $this->branchId, 'is_guest' => Ask::NO,
            'name' => 'Gérant', 'email' => 'gerant@lecayenne.fr',
        ]);
        $staff->assignRole('Admin');

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();

        $r = $this->reclamer($jeton, '0699887766', 'pirate@exemple.fr', 'Pas Le Gerant')->assertOk();

        $this->assertFalse((bool) $r->json('account_created'));
        $this->assertNotNull($r->json('code'), 'le lot doit être livré : c\'est probablement le propriétaire qui teste');

        // LA CONSÉQUENCE QUI COMPTE, et que les gardes internes de `completer()` masquaient : si le
        // compte de l'équipe était DÉSIGNÉ comme celui du gagnant, un lot en points serait crédité
        // sur le compte du gérant. Aucun compte client ne doit être désigné pour ce numéro.
        $this->assertFalse((bool) $r->json('account_ready'),
            'LE COMPTE DE L\'ÉQUIPE A ÉTÉ DÉSIGNÉ comme compte du gagnant : ses points iraient au gérant');

        $frais = $staff->fresh();
        $this->assertSame('Gérant', $frais->name, 'LE COMPTE DE L\'ÉQUIPE A ÉTÉ RENOMMÉ depuis un endpoint public');
        $this->assertSame('gerant@lecayenne.fr', $frais->email, 'L\'ADRESSE DU GÉRANT A ÉTÉ REMPLACÉE');
        $this->assertSame((int) Ask::NO, (int) $frais->is_guest);
    }

    /** Un compte supprimé ne se ressuscite pas avec un numéro : c'est la porte fermée le 4 août. */
    public function test_un_compte_SUPPRIME_n_est_pas_ressuscite(): void
    {
        $mort = User::factory()->create([
            'phone' => '0655554444', 'branch_id' => 0, 'is_guest' => Ask::YES, 'name' => 'Ancien',
        ]);
        $mort->delete();
        $mailOrigine = (string) $mort->email;

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $r = $this->reclamer($jeton, '0655554444', 'ancien@exemple.fr')->assertOk();

        // Pas seulement « il reste supprimé » : on n'ÉCRIT RIEN dessus, et on ne le DÉSIGNE pas.
        // Sans ces deux assertions, retirer la garde ne casse rien — le compte resterait supprimé
        // tout en recevant l'adresse du réclamant et en devenant la cible de ses points.
        $this->assertFalse((bool) $r->json('account_ready'),
            'un compte SUPPRIMÉ a été désigné comme compte du gagnant');
        $this->assertSame($mailOrigine,
            (string) User::withoutGlobalScopes()->withTrashed()->find($mort->id)->email,
            'l\'adresse du réclamant a été écrite sur un compte supprimé');

        $this->assertNotNull(User::withoutGlobalScopes()->withTrashed()->find($mort->id)->deleted_at,
            'un compte supprimé a été ressuscité depuis un endpoint public');
        // `withoutGlobalScopes()` retirerait AUSSI le filtre de suppression douce et compterait le
        // compte mort lui-même : on ne retire que la portée de branche.
        $this->assertSame(0, User::withoutGlobalScope(BranchScope::class)
            ->where('phone', '0655554444')->count(),
            'un compte VIVANT a été créé sur le numéro d\'un compte supprimé');
    }

    /** L'adresse de quelqu'un d'autre ne se déplace pas. Le lot passe quand même. */
    public function test_l_adresse_d_un_AUTRE_compte_n_est_pas_rattachee(): void
    {
        $autre = User::factory()->create([
            'phone' => '0644443333', 'branch_id' => 0, 'is_guest' => Ask::YES,
            'email' => 'convoitee@exemple.fr',
        ]);

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $r = $this->reclamer($jeton, '0611000022', 'convoitee@exemple.fr')->assertOk();

        $this->assertNotNull($r->json('code'), 'le client honnête ne doit pas payer une collision d\'adresse');
        $this->assertSame('convoitee@exemple.fr', $autre->fresh()->email,
            'L\'ADRESSE A ÉTÉ VOLÉE à un autre compte');

        $nouveau = User::withoutGlobalScopes()->where('phone', '0611000022')->firstOrFail();
        $this->assertNull($nouveau->email, 'l\'adresse d\'un autre compte a été recopiée sur le nouveau');
    }

    // ── 6. L'E-MAIL DU LOT ───────────────────────────────────────────────────────────────────

    /**
     * LA PAGE PROMET « on t'envoie ton code par e-mail » — ET C'EST POUR ÇA qu'elle demande
     * l'adresse. Sans envoi réel, cette phrase est un mensonge qui se découvre le lendemain, quand
     * le client cherche son code et ne le trouve pas.
     */
    public function test_le_lot_part_par_EMAIL_avec_ses_conditions(): void
    {
        Mail::fake();

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $this->reclamer($jeton, '0611000030', 'lot@exemple.fr', 'Inès')->assertOk();

        Mail::assertSent(WheelPrizeMail::class, function ($m) {
            return $m->hasTo('lot@exemple.fr');
        });

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $this->assertNotNull($spin->notified_at,
            'sans trace d\'envoi, impossible de distinguer « jamais envoyé » de « envoyé et perdu »');

        // Le contenu doit porter le CODE et les CONDITIONS : un e-mail qui dit « vous avez gagné »
        // sans dire quoi en faire renvoie le client au comptoir poser la question.
    }

    /**
     * LE CONTENU de l'e-mail : le code, et ce qu'on peut en faire. Un e-mail qui dit « vous avez
     * gagné » sans dire quoi en faire renvoie le client au comptoir poser la question.
     *
     * Rendu SANS `Mail::fake()` : le faux mailer n'a pas de méthode de rendu, et vérifier le
     * contenu à travers lui reviendrait à ne rien vérifier du tout.
     */
    public function test_l_email_porte_le_code_et_les_conditions(): void
    {
        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $this->reclamer($jeton, '0611000032', 'contenu@exemple.fr', 'Inès')->assertOk();

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $rendu = (new WheelPrizeMail($spin, 'ROUE-TEST01', 10.0, '09/09/2026', true))->render();

        $this->assertStringContainsString('ROUE-TEST01', $rendu);
        $this->assertStringContainsString('10,00', $rendu, 'le minimum d\'achat doit être écrit');
        $this->assertStringContainsString('09/09/2026', $rendu, 'la date limite doit être écrite');
        $this->assertStringContainsString('panier sur le site', $rendu,
            'une remise se saisit dans le panier : le dire évite un aller-retour au comptoir');
        $this->assertStringContainsString('compte Le Cayenne est cr', $rendu,
            'la façon de se connecter doit être écrite : un compte créé en silence ne sert à personne');
        $this->assertStringContainsString('In', $rendu, 'le prénom saisi doit être utilisé');

        // Un sujet qui annonce un gain est le premier signal de filtrage anti-spam : l'e-mail
        // arriverait là où personne ne le lit.
        $sujet = (string) (new WheelPrizeMail($spin, 'ROUE-TEST01', 10.0, null, false))->build()->subject;
        $this->assertStringNotContainsString((string) $spin->prize_label, $sujet);
        $this->assertStringNotContainsString('ROUE-TEST01', $sujet);
    }

    /**
     * UN SERVEUR DE MESSAGERIE QUI TOUSSE NE DOIT PAS COÛTER SON LOT AU CLIENT. Le code est déjà à
     * l'écran, la participation est déjà en base : refuser la réponse serait lui reprendre ce qu'il
     * vient de gagner.
     */
    public function test_un_echec_d_envoi_ne_fait_PAS_echouer_la_reclamation(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('serveur SMTP injoignable'));

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $r = $this->reclamer($jeton, '0611000031', 'panne@exemple.fr')->assertOk();

        $this->assertNotNull($r->json('code'), 'le lot a été perdu à cause d\'un e-mail');
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)->count());
        $this->assertNull(WheelSpin::withoutGlobalScope(BranchScope::class)->first()->notified_at,
            'l\'envoi est marqué fait alors qu\'il a échoué : on ne saurait plus qui relancer');
    }

    /**
     * UN LOT EN POINTS N'EST PAS ENCORE CRÉDITÉ À LA RÉCLAMATION — il l'est à la REMISE, au
     * comptoir. L'écran ET l'e-mail disaient « crédités » : le client allait vérifier son compte et
     * y trouvait zéro. L'e-mail est le pire des deux, parce qu'il le GARDE.
     */
    public function test_l_email_ne_pretend_PAS_que_les_points_sont_deja_credites(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '50 points', 'type' => 'points', 'value' => 50,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $this->reclamer($jeton, '0611000033', 'points-mail@exemple.fr')->assertOk();

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $rendu = (new WheelPrizeMail($spin, null, 10.0, '09/09/2026', true))->render();

        $this->assertStringNotContainsString('ont été ajoutés', $rendu);
        $this->assertStringNotContainsString('crédités', $rendu);
        $this->assertStringContainsString('comptoir', $rendu,
            'il doit être dit OÙ récupérer les points, sinon le client les cherche sur le site');
        $this->assertStringContainsString('50 points', $rendu);
    }

    // ── 7. LE COMPTE CRÉÉ REND LES POINTS LIVRABLES ──────────────────────────────────────────

    /**
     * LA CONSÉQUENCE HEUREUSE DU DÉCOUPAGE. Un lot en points ne trouvait souvent AUCUN compte à
     * créditer : la roue identifie par téléphone, et la plupart des joueurs n'avaient pas de compte.
     * Le message était honnête (« points en attente ») mais le lot restait mort.
     *
     * Maintenant que la réclamation crée le compte, les points se créditent vraiment.
     */
    public function test_les_points_gagnes_trouvent_le_compte_cree_a_la_reclamation(): void
    {
        Config::set('wheel.segments', [
            ['key' => 'p', 'label' => '50 points', 'type' => 'points', 'value' => 50,
             'weight' => 1, 'daily_cap' => 0],
        ]);

        $jeton = $this->jeton();
        $this->tourner($jeton)->assertOk();
        $this->reclamer($jeton, '0611000023', 'points@exemple.fr', 'Sami')->assertOk();

        $spin = WheelSpin::withoutGlobalScope(BranchScope::class)->firstOrFail();
        $u = User::withoutGlobalScopes()->where('phone', '0611000023')->firstOrFail();
        $avant = (int) $u->loyalty_points;

        $r = app(\App\Services\Wheel\WheelDeliveryService::class)->deliver($spin->id, null);

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['points_credited'],
            'les points sont restés en attente alors qu\'un compte vient d\'être créé pour ce numéro');
        $this->assertSame($avant + 50, (int) $u->fresh()->loyalty_points);
    }
}
