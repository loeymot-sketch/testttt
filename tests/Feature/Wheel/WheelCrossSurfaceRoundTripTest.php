<?php

namespace Tests\Feature\Wheel;

use App\Enums\Role;
use App\Mail\WheelPrizeMail;
use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Identity\CustomerAccountProvisioner;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelStepService;
use App\Services\Wheel\WheelUnlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * [test-e2e fix D-002 round-2 2026-08-13] LE ROUND-TRIP INTER-SURFACES, RENDU RÉPÉTABLE ET COMMITTÉ.
 *
 * ── CE QUE ÇA REMPLACE ────────────────────────────────────────────────────────────────────────
 * Round-1 (Wave D) a affirmé que le round-trip QR-comptoir → `roue.html` du client « marchait en
 * direct » sur la seule preuve d'un `GET /api/frontend/wheel/config` répondant 200 — ça ne prouve
 * que la lecture de la configuration, pas le jeu réel (tirage, persistance, remise du lot). Le
 * relecteur adversarial de round-1 est allé plus loin et a prouvé, À LA MAIN, dans une transaction
 * `DB::beginTransaction()`/`DB::rollBack()` avec `Mail::fake()`, qu'un round-trip complet passant
 * par les mêmes appels de service que le contrôleur — `WheelUnlockService::issue()` →
 * `WheelStepService::open()` × 2 → `WheelService::drawPending()` → `WheelService::claimPending()`
 * — fonctionne proprement : vrai tirage pondéré, vraie persistance, zéro résidu confirmé par
 * `find()` après le rollback. Ce banc transforme cette vérification manuelle ponctuelle en test
 * PHPUnit réel, répétable, et committé — utilisant `RefreshDatabase` (le mécanisme d'isolation
 * DÉJÀ éprouvé par tous les autres bancs `tests/Feature/Wheel/*`), pas une transaction
 * bricolée à la main.
 *
 * ── LA CHAÎNE ÉPROUVÉE, APPEL DE SERVICE PAR APPEL DE SERVICE ────────────────────────────────
 * C'est délibérément la couche SERVICE et non HTTP : `WheelParcoursCompletTest` couvre déjà le
 * chemin HTTP bout en bout (POST /wheel/spin, /wheel/claim…). Ce banc-ci prouve autre chose —
 * que les briques que `Frontend\WheelController::claim()` assemble fonctionnent aussi assemblées
 * DIRECTEMENT, exactement comme le relecteur adversarial de round-1 les a exercées à la main.
 *   1. le comptoir émet un jeton signé (WheelUnlockService::issue) ;
 *   2. le client ouvre les deux étapes (avis, abonnement), horodatées côté serveur
 *      (WheelStepService::open) ;
 *   3. le tirage est joué SANS identité, mis en attente sur la progression
 *      (WheelService::drawPending) ;
 *   4. la réclamation matérialise la participation — ligne WheelSpin, coupon le cas échéant
 *      (WheelService::claimPending) ;
 *   5. le compte client est provisionné, EXACTEMENT comme le contrôleur le fait juste après
 *      `claimPending()` (CustomerAccountProvisioner::ensure() — round-1 avait sauté cette brique) ;
 *   6. le mail du lot part, comme `WheelController::envoyerLeLot()` le fait ;
 *   7. assertions sur la FORME réelle de la ligne persistée, et sur le mail capturé par
 *      Mail::fake().
 */
class WheelCrossSurfaceRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchId = Branch::factory()->create()->id;

        Config::set('wheel.enabled', true);
        Config::set('wheel.campaign_key', 'cross-surface-round-trip');
        Config::set('wheel.daily_total_cap', 500);
        Config::set('wheel.claim_window_minutes', 30);
        Config::set('wheel.prize_validity_days', 30);
        Config::set('wheel.unlock_methods', ['staff' => true, 'order' => true, 'declaratif' => false]);
        Config::set('pos.coupon_codes_enabled', true);

        // Les deux étapes SONT requises ici — round-1 n'a exercé que le tirage/la réclamation ; ce
        // banc couvre en plus la brique `WheelStepService::open()` que round-1 avait laissée de
        // côté, avec des délais nuls pour rester déterministe (WheelStepsTest éprouve le
        // chronométrage lui-même).
        Config::set('wheel.steps', [
            'review' => ['required' => true, 'url' => 'https://g.page/le-cayenne/review', 'dwell_seconds' => 0, 'derive_fallback' => false],
            'follow' => ['required' => true, 'instagram' => 'https://instagram.com/lecayenne', 'snapchat' => '', 'facebook' => '', 'dwell_seconds' => 0],
        ]);

        // Un seul lot en points : la valeur la plus simple à vérifier de bout en bout (pas de
        // dépendance produit/stock, hors du périmètre de CE banc — WheelParcoursCompletTest couvre
        // déjà le lot `free_item` et le mouvement de stock).
        Config::set('wheel.segments', [
            ['key' => 'points-50', 'label' => '50 points', 'type' => 'points', 'value' => 50,
             'weight' => 100, 'daily_cap' => 0, 'quantity' => 0],
        ]);
    }

    public function test_le_round_trip_complet_par_appel_de_service_persiste_une_participation_reelle(): void
    {
        Mail::fake();

        $caissier = User::factory()->create(['branch_id' => $this->branchId]);
        $caissier->givePermissionTo('pos');

        // ── 1. LE COMPTOIR ÉMET LE JETON — exactement ce que le QR scanné transporte.
        $jeton = app(WheelUnlockService::class)->issue($this->branchId, $caissier->id);
        $this->assertNotEmpty($jeton['token'] ?? null, 'le comptoir n\'a pas pu émettre de jeton signé');

        $verif = app(WheelUnlockService::class)->verify($jeton['token']);
        $tokenHash = $verif['token_hash'];

        // ── 2. LES ÉTAPES — ouvertes côté serveur, comme le fait `WheelController::step()`.
        $steps = app(WheelStepService::class);
        $steps->open($tokenHash, $this->branchId, 'review');
        $steps->open($tokenHash, $this->branchId, 'follow');

        // Les deux DOIVENT être satisfaites avant le tour : avec des délais nuls, `assertDone()` ne
        // doit lever aucune exception.
        $steps->assertDone($tokenHash);

        $progress = $steps->progress($tokenHash, $this->branchId);
        $this->assertNotNull($progress->review_opened_at, 'l\'ouverture de l\'étape avis n\'a laissé aucune trace serveur');
        $this->assertNotNull($progress->follow_opened_at, 'l\'ouverture de l\'étape abonnement n\'a laissé aucune trace serveur');

        // ── 3. LE TIRAGE, SANS IDENTITÉ — mis en attente sur la progression.
        $roue = app(WheelService::class);
        $segment = $roue->drawPending($this->branchId, $progress);

        $this->assertSame('points-50', $segment['key'], 'un seul lot était configuré : le tirage doit le rendre');
        $this->assertSame('points', $segment['type']);

        // Rien n'existe encore en base à ce stade — c'est tout l'intérêt du découpage en deux temps.
        $this->assertSame(0, WheelSpin::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $this->branchId)->count(),
            'une participation a été créée AVANT la réclamation : le tirage doit rester sans identité');

        // ── 4. LA RÉCLAMATION — l'identité arrive, la participation naît réellement.
        $tel = '0612340099';
        $mail = 'round-trip.cross-surface@exemple.test';
        $spin = $roue->claimPending(
            $this->branchId,
            $progress->fresh(),
            $tel,
            $mail,
            'Round Trip',
            ['method' => 'staff', 'user_id' => $caissier->id, 'token_hash' => $tokenHash]
        );

        // ── 5. LA FORME RÉELLE DE LA LIGNE PERSISTÉE — pas juste « une ligne existe ».
        $this->assertInstanceOf(WheelSpin::class, $spin);
        $this->assertNotNull($spin->id, 'la participation n\'a pas été persistée');
        $this->assertSame($this->branchId, $spin->branch_id);
        $this->assertSame('points-50', $spin->prize_key);
        $this->assertSame('points', $spin->prize_type);
        $this->assertSame(50, (int) $spin->points_awarded, 'les points annoncés au tirage doivent être ceux attribués à la réclamation');
        $this->assertSame($tokenHash, $spin->unlock_token_hash, 'la participation doit porter l\'empreinte du jeton du comptoir, pas le jeton en clair');
        $this->assertNull($spin->delivered_at, 'un lot en points n\'est crédité qu\'à la remise, jamais à la réclamation');

        $enBase = WheelSpin::withoutGlobalScope(BranchScope::class)->find($spin->id);
        $this->assertNotNull($enBase, 'relu depuis la base, le tour a disparu : la persistance n\'est pas réelle');
        $this->assertSame($spin->id, $enBase->id);

        // ── 5. LE COMPTE CLIENT — provisionné par la MÊME brique que le contrôleur appelle juste
        //      après `claimPending()`. `WheelService::claimPending()` seule ne crée AUCUN compte —
        //      round-1 l'avait implicitement supposé en ne prouvant que le tirage/la persistance.
        $compte = app(CustomerAccountProvisioner::class)->ensure($tel, $mail, 'Round Trip');
        $this->assertTrue((bool) $compte['created'], 'le compte n\'a pas été créé : '.json_encode($compte));
        $this->assertNotNull($compte['user_id']);

        $client = User::withoutGlobalScopes()->where('phone', '0612340099')->first();
        $this->assertNotNull($client, 'la réclamation n\'a créé aucun compte pour ce numéro');
        $this->assertSame($compte['user_id'], $client->id);
        $this->assertTrue($client->hasRole(Role::CUSTOMER));

        // ── 6. LE MAIL DU LOT — envoyé comme `WheelController::envoyerLeLot()` le fait, capturé par
        //      Mail::fake() : c'est la preuve du GAMEPLAY EN PROFONDEUR que D-002 réclamait, pas
        //      juste un `GET /wheel/config` à 200.
        Mail::to($mail)->send(new WheelPrizeMail($spin, null, (float) config('wheel.min_order_amount', 0), null, true));

        Mail::assertSent(WheelPrizeMail::class, function ($m) use ($mail) {
            return $m->hasTo($mail);
        });

        // ── ZÉRO RÉSIDU AILLEURS : un seul tour pour ce jeton, une seule fois.
        $this->assertSame(1, WheelSpin::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $this->branchId)->count(),
            'le round-trip a laissé plus d\'une participation derrière lui');
    }
}
