<?php

namespace Tests\Feature\Wheel;

use App\Http\Middleware\EnsureWheelAccess;
use App\Models\Branch;
use App\Models\Item;
use App\Models\User;
use App\Models\WheelSpin;
use App\Services\Pilotage\InterrupteurService;
use App\Services\Wheel\WheelDeliveryService;
use App\Services\Wheel\WheelService;
use App\Services\Wheel\WheelSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * [ESPACE ROUE 2026-11-19] L'espace commerçant de la roue — ce que le SERVEUR garantit, quel que soit
 * l'écran qui l'appelle.
 *
 *   · la porte : rien sans droit caisse ni code ;
 *   · un « produit offert » ne peut viser qu'un article RÉEL de la carte ;
 *   · une remise en % a toujours un plafond ; la roue a toujours un lot gagnable ;
 *   · un lot retiré mais déjà gagné reste remettable (archive), un lot jamais joué disparaît ;
 *   · remettre un lot exige une preuve (code ou numéro) ;
 *   · liens, ouverture du jeu, validité, nouvelle campagne : écrits ET appliqués.
 */
class WheelMerchantSpaceTest extends TestCase
{
    use RefreshDatabase;

    private int $branchId;
    private User $caissier;
    private Item $boisson;
    private Item $dessert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        $this->branchId = Branch::factory()->create()->id;
        $this->caissier = User::factory()->create(['branch_id' => $this->branchId]);
        $this->caissier->givePermissionTo('pos');
        $this->boisson = Item::factory()->create(['name' => 'Boisson Seule', 'status' => 5]);
        $this->dessert = Item::factory()->create(['name' => 'Tiramisu', 'status' => 5]);

        Config::set('wheel.access.pin', '');
        Config::set('wheel.campaign_key', 'depart');
        Config::set('wheel.prize_validity_days', 30);
        Config::set('wheel.record_cost_on_claim', false);
        Config::set('wheel.segments', [
            ['key' => 'boisson', 'label' => 'Boisson', 'type' => 'free_item', 'value' => 0, 'weight' => 10, 'daily_cap' => 0, 'quantity' => 0, 'cost_item_id' => $this->boisson->id],
            ['key' => 'tiramisu', 'label' => 'Tiramisu', 'type' => 'free_item', 'value' => 0, 'weight' => 5, 'daily_cap' => 0, 'quantity' => 0, 'cost_item_id' => $this->dessert->id],
        ]);
    }

    private function moi()
    {
        return $this->actingAs($this->caissier, 'web')->withHeaders(['Accept' => 'application/json']);
    }

    private function lot(array $l): array
    {
        return $l + ['type' => 'free_item', 'weight' => 5, 'quantity' => 0, 'daily_cap' => 0];
    }

    private function gain(string $cle, string $label, string $tel, ?\DateTimeInterface $quand = null): WheelSpin
    {
        $s = new WheelSpin();
        $s->forceFill([
            'branch_id' => $this->branchId, 'campaign_key' => (string) config('wheel.campaign_key'),
            'phone' => $tel, 'prize_key' => $cle, 'prize_label' => $label, 'prize_type' => 'free_item',
            'prize_value' => 0, 'points_awarded' => 0, 'unlock_method' => 'staff',
        ]);
        $s->created_at = $quand ?? now();
        $s->save();

        return $s;
    }

    // ── La porte ─────────────────────────────────────────────────────────────────────────────

    public function test_sans_droit_caisse_ni_code_rien_ne_s_ouvre(): void
    {
        $quidam = User::factory()->create(['branch_id' => $this->branchId]);
        $this->actingAs($quidam, 'web')->getJson('/admin/roue/espace/donnees')->assertStatus(403);
        $this->actingAs($quidam, 'web')->postJson('/admin/roue/espace/roue', ['lots' => []])->assertStatus(403);
    }

    public function test_les_donnees_reunissent_lots_produits_et_lots_a_remettre_valables(): void
    {
        $this->gain('boisson', 'Boisson', '0600000001');
        $this->gain('boisson', 'Boisson', '0600000002', now()->subDays(60)); // expiré

        $d = $this->moi()->getJson('/admin/roue/espace/donnees')->assertOk()->json('data');

        $this->assertSame(['boisson', 'tiramisu'], array_column($d['lots'], 'key'));
        $this->assertEqualsWithDelta(66.7, $d['lots'][0]['chance'], 0.1);
        $this->assertContains('Tiramisu', array_column($d['produits'], 'name'));
        // Le lot expiré n'est PAS « à remettre » : la remise le refuserait.
        $this->assertSame(1, $d['aRemettre']);
    }

    // ── Le catalogue ─────────────────────────────────────────────────────────────────────────

    public function test_un_produit_offert_doit_viser_un_article_reel_de_la_carte(): void
    {
        $r = $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['label' => 'Inventé', 'cost_item_id' => 999999]),
        ]])->assertStatus(422);
        $this->assertStringContainsString('choisis le produit', $r->json('message'));
        $this->assertNull(app(WheelSettingsService::class)->catalogueStocke());
    }

    public function test_une_remise_en_pourcentage_sans_plafond_est_refusee(): void
    {
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['label' => '-10 %', 'type' => 'coupon_percent', 'value' => 10, 'max_discount' => 0]),
        ]])->assertStatus(422)->assertJsonPath('status', false);
    }

    public function test_une_roue_sans_aucun_lot_gagnable_est_refusee(): void
    {
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id, 'weight' => 0]),
            $this->lot(['key' => 'tiramisu', 'label' => 'Tiramisu', 'cost_item_id' => $this->dessert->id, 'weight' => 0]),
        ]])->assertStatus(422);
    }

    public function test_publier_change_la_roue_que_voit_le_client_et_le_tirage(): void
    {
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id, 'weight' => 40, 'quantity' => 25]),
            $this->lot(['label' => 'Tiramisu offert', 'cost_item_id' => $this->dessert->id, 'weight' => 3, 'kitchen_prep' => true]),
            $this->lot(['label' => '50 points', 'type' => 'points', 'value' => 50, 'weight' => 0]),
        ]])->assertOk()->assertJsonPath('status', true);

        $roue = app(WheelService::class);
        $labels = array_column($roue->publicSegments(), 'label');
        $this->assertSame(['Boisson', 'Tiramisu offert', '50 points'], $labels);

        $seg = collect($roue->segments())->keyBy('label');
        $this->assertSame(40, $seg['Boisson']['weight']);
        $this->assertSame(25, $seg['Boisson']['quantity']);
        $this->assertSame('Tiramisu', $seg['Tiramisu offert']['cost_item_name'], 'le nom de coût vient de la carte, pas de la saisie');
        $this->assertTrue($seg['Tiramisu offert']['kitchen_prep']);
        $this->assertMatchesRegularExpression('/^tiramisu_offert_[a-z0-9]{4}$/', $seg['Tiramisu offert']['key']);
        // Probabilité nulle = affiché, jamais tiré.
        $this->assertNotContains($seg['50 points']['key'], $roue->spinnableKeys($this->branchId));
        // L'ancien écran lit les mêmes valeurs (pas deux vérités).
        $this->assertSame(['weight' => 40, 'quantity' => 25], app(WheelSettingsService::class)->prizeOverrides()['boisson']);
    }

    public function test_un_lot_deja_gagne_puis_retire_reste_remettable_un_lot_jamais_joue_disparait(): void
    {
        $gagne = $this->gain('tiramisu', 'Tiramisu', '0611223344');

        // On envoie une roue SANS le tiramisu (déjà gagné) et on retire explicitement un nouveau lot.
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['label' => 'Frites', 'cost_item_id' => $this->boisson->id]),
        ]])->assertOk();
        $cleFrites = collect(app(WheelService::class)->segments())->firstWhere('label', 'Frites')['key'];
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['key' => 'nouveau_x', 'label' => 'Autre', 'cost_item_id' => $this->dessert->id]),
            $this->lot(['key' => $cleFrites, 'label' => 'Frites', 'cost_item_id' => $this->boisson->id, 'archived' => true]),
        ]])->assertOk();

        $roue = app(WheelService::class);
        $this->assertNotContains('tiramisu', array_column($roue->segments(), 'key'), 'retiré : plus tiré ni montré');
        $tous = collect($roue->catalogueComplet())->keyBy('key');
        $this->assertTrue($tous['tiramisu']['archived'], 'déjà gagné : gardé en archive');
        $this->assertFalse($tous->has($cleFrites), 'jamais joué : effacé, pas archivé');
        // Le gagnant du tiramisu peut toujours le recevoir, adossé au bon produit.
        $this->assertSame($this->dessert->id, app(WheelDeliveryService::class)->costItemId('tiramisu'));

        $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['phone' => '06 11 22 33 44'])
            ->assertOk()->assertJsonPath('status', true);
        $this->assertNotNull($gagne->fresh()->delivered_at);
    }

    // ── La remise ────────────────────────────────────────────────────────────────────────────

    public function test_remettre_exige_une_preuve_et_refuse_le_mauvais_client(): void
    {
        $gagne = $this->gain('boisson', 'Boisson', '0699000001');

        $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", [])->assertStatus(422);
        $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['code' => 'ROUE-ZZZZZZ'])->assertStatus(422);
        $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['phone' => '0699000002'])->assertStatus(422);
        $this->assertNull($gagne->fresh()->delivered_at);

        $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['phone' => '0699000001'])
            ->assertOk()->assertJsonPath('message', 'Remis : Boisson. Bon service !');
        $frais = $gagne->fresh();
        $this->assertNotNull($frais->delivered_at);
        $this->assertSame($this->caissier->id, $frais->delivered_by_user_id);

        // Deux fois ? Non.
        $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['phone' => '0699000001'])->assertStatus(422);
    }

    public function test_la_liste_des_lots_porte_l_identifiant_et_masque_le_numero(): void
    {
        $gagne = $this->gain('boisson', 'Boisson', '0699123456');
        $l = $this->moi()->getJson('/admin/roue/espace/lots')->assertOk()->json('data.0');

        $this->assertSame($gagne->id, $l['id']);
        $this->assertSame('3456', $l['tel_fin']);
        $this->assertSame('du', $l['etat']);
        $this->assertStringNotContainsString('0699123456', json_encode($l));
    }

    // ── Liens, jeu, campagne ─────────────────────────────────────────────────────────────────

    public function test_les_liens_sont_valides_en_francais_puis_enregistres(): void
    {
        $this->moi()->postJson('/admin/roue/espace/liens', ['instagram_url' => 'ftp://x'])
            ->assertStatus(422)->assertJsonPath('status', false);

        $this->moi()->postJson('/admin/roue/espace/liens', [
            'review_url' => 'https://g.page/r/Cabc/review', 'instagram_url' => 'https://www.instagram.com/lecayenne',
            'snapchat_url' => '', 'facebook_url' => '', 'review_required' => true, 'follow_required' => false,
            'review_dwell' => 15, 'follow_dwell' => 5, 'min_order' => 12,
        ])->assertOk();

        $r = app(WheelSettingsService::class);
        $this->assertSame('https://g.page/r/Cabc/review', $r->reviewUrl());
        $this->assertSame('https://www.instagram.com/lecayenne', $r->instagramUrl());
        $this->assertFalse($r->followRequired());
        $this->assertSame(12.0, $r->minOrder());
    }

    public function test_ouvrir_le_jeu_et_regler_la_validite_s_appliquent_et_survivent_au_redemarrage(): void
    {
        Config::set('wheel.enabled', false);
        $this->moi()->postJson('/admin/roue/espace/jeu', ['ouvert' => true, 'prize_validity_days' => 12, 'daily_total_cap' => 80])->assertOk();

        $this->assertTrue(app(InterrupteurService::class)->valeur('wheel'));
        $this->assertTrue((bool) config('wheel.enabled'));

        // « Redémarrage » : la configuration repart du fichier, le démarrage réapplique la base.
        Config::set('wheel.prize_validity_days', 30);
        Config::set('wheel.daily_total_cap', 0);
        app(WheelSettingsService::class)->appliquerAuDemarrage();
        $this->assertSame(12, config('wheel.prize_validity_days'));
        $this->assertSame(80, config('wheel.daily_total_cap'));

        $this->moi()->postJson('/admin/roue/espace/jeu', ['prize_validity_days' => 0])->assertStatus(422);
    }

    public function test_une_nouvelle_campagne_remet_les_compteurs_a_zero_sans_effacer_l_historique(): void
    {
        $this->gain('boisson', 'Boisson', '0600000009');
        $avant = collect($this->moi()->getJson('/admin/roue/espace/donnees')->json('data.lots'))->firstWhere('key', 'boisson');
        $this->assertSame(1, $avant['donnes']);

        $this->moi()->postJson('/admin/roue/espace/campagne')->assertOk();
        $this->assertStringStartsWith('c-', (string) config('wheel.campaign_key'));

        $apres = collect($this->moi()->getJson('/admin/roue/espace/donnees')->json('data.lots'))->firstWhere('key', 'boisson');
        $this->assertSame(0, $apres['donnes']);
        $this->assertSame(1, WheelSpin::withoutGlobalScopes()->count());
    }

    // ── L'accès depuis la caisse ─────────────────────────────────────────────────────────────

    public function test_la_passe_de_la_caisse_ouvre_l_espace_sur_les_avis_et_attribue_la_remise_au_caissier(): void
    {
        $url = $this->actingAs($this->caissier, 'sanctum')
            ->postJson('/api/admin/wheel/screen-pass', ['ecran' => 'avis'])->assertOk()->json('url');

        // Nouveau navigateur : aucune session, seulement la passe.
        $this->app['auth']->forgetGuards();
        Config::set('wheel.access.pin', '481526');
        $this->withHeaders(['Accept' => 'text/html'])->get($url)
            ->assertRedirect(route('admin.wheel.espace').'#avis');
        $this->assertSame($this->caissier->id, session(EnsureWheelAccess::PASS_USER_KEY));

        $gagne = $this->gain('boisson', 'Boisson', '0677000001');
        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['phone' => '0677000001'])->assertOk();
        $this->assertSame($this->caissier->id, $gagne->fresh()->delivered_by_user_id);
    }

    // ── Relecture adverse 2026-11-19 : un test par défaut trouvé ─────────────────────────────

    /** P1 : un « numéro » sans chiffres se normalisait en vide et le service sautait sa comparaison. */
    public function test_un_numero_sans_chiffres_ne_remet_rien(): void
    {
        $gagne = $this->gain('boisson', 'Boisson', '0699000077');
        foreach (['-', 'abc', '  ', '+'] as $faux) {
            $this->moi()->postJson("/admin/roue/espace/lots/{$gagne->id}/remettre", ['phone' => $faux])->assertStatus(422);
        }
        $this->assertNull($gagne->fresh()->delivered_at);
    }

    /** P1 : 0 « sans plafond » fermait la roue (le moteur lit « parties du jour >= plafond »). */
    public function test_un_plafond_journalier_a_zero_est_refuse(): void
    {
        $this->moi()->postJson('/admin/roue/espace/jeu', ['daily_total_cap' => 0])->assertStatus(422);
        $this->assertNotSame(0, (int) config('wheel.daily_total_cap'));
    }

    /** P1 : les archives (jamais effaçables) ne doivent pas finir par bloquer l'enregistrement. */
    public function test_les_archives_ne_comptent_pas_dans_les_seize_lots(): void
    {
        $lots = [];
        for ($i = 0; $i < 16; $i++) {
            $lots[] = $this->lot(['label' => "Lot $i", 'cost_item_id' => $this->boisson->id]);
        }
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => $lots])->assertOk();
        $cles = array_column(app(WheelService::class)->segments(), 'key');
        foreach (array_slice($cles, 0, 4) as $j => $cle) {
            $this->gain($cle, "Lot $j", '06100000'.str_pad((string) $j, 2, '0', STR_PAD_LEFT));
        }

        // 4 lots joués retirés (→ archives) + 16 affichés : 20 envoyés, doit passer.
        $envoi = [];
        foreach (app(WheelService::class)->segments() as $k => $seg) {
            $envoi[] = $this->lot(['key' => $seg['key'], 'label' => $seg['label'], 'cost_item_id' => $this->boisson->id, 'archived' => $k < 4]);
        }
        for ($i = 0; $i < 4; $i++) {
            $envoi[] = $this->lot(['label' => "Neuf $i", 'cost_item_id' => $this->dessert->id]);
        }
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => $envoi])->assertOk();
        $this->assertCount(16, app(WheelService::class)->segments());
        $this->assertCount(20, app(WheelService::class)->catalogueComplet());

        // 17 AFFICHÉS : refusé.
        $envoi[] = $this->lot(['label' => 'De trop', 'cost_item_id' => $this->dessert->id]);
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => $envoi])->assertStatus(422);
    }

    /** P1 : un lot TIRÉ (coordonnées pas encore laissées) puis retiré garde son plafond et son produit. */
    public function test_un_lot_tire_en_attente_puis_retire_reste_dans_le_catalogue(): void
    {
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['key' => 'tiramisu', 'label' => 'Tiramisu', 'cost_item_id' => $this->dessert->id]),
            $this->lot(['label' => '-15 %', 'type' => 'coupon_percent', 'value' => 15, 'max_discount' => 6]),
        ]])->assertOk();
        $cle = collect(app(WheelService::class)->segments())->firstWhere('label', '-15 %')['key'];
        \Illuminate\Support\Facades\DB::table('wheel_step_progress')->insert([
            'unlock_token_hash' => str_repeat('a', 64), 'branch_id' => $this->branchId,
            'prize_key' => $cle, 'prize_label' => '-15 %', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['key' => 'tiramisu', 'label' => 'Tiramisu', 'cost_item_id' => $this->dessert->id]),
        ]])->assertOk();

        $garde = collect(app(WheelService::class)->catalogueComplet())->firstWhere('key', $cle);
        $this->assertNotNull($garde, 'tiré en attente : ne doit pas être effacé');
        $this->assertTrue($garde['archived']);
        $plafond = (new \ReflectionMethod(WheelService::class, 'maxDiscountFor'));
        $plafond->setAccessible(true);
        $this->assertSame(6.0, $plafond->invoke(app(WheelService::class), $cle), 'la remise garde son plafond');
    }

    /** P2 : changer le PRODUIT d'un lot déjà gagné ne réécrit pas ce que ses gagnants recevront. */
    public function test_changer_le_produit_d_un_lot_gagne_cree_un_nouveau_lot(): void
    {
        $this->gain('boisson', 'Boisson', '0688000001');
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->dessert->id]),
            $this->lot(['key' => 'tiramisu', 'label' => 'Tiramisu', 'cost_item_id' => $this->dessert->id]),
        ]])->assertOk();

        $this->assertSame($this->boisson->id, app(WheelDeliveryService::class)->costItemId('boisson'), 'les gagnants d\'hier gardent leur produit');
        $nouveau = collect(app(WheelService::class)->segments())->firstWhere('label', 'Boisson');
        $this->assertNotSame('boisson', $nouveau['key']);
        $this->assertSame($this->dessert->id, $nouveau['cost_item_id']);

        // Changer seulement les chances garde la clé.
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'tiramisu', 'label' => 'Tiramisu', 'cost_item_id' => $this->dessert->id, 'weight' => 77]),
            $this->lot(['key' => $nouveau['key'], 'label' => 'Boisson', 'cost_item_id' => $this->dessert->id]),
        ]])->assertOk();
        $this->assertSame(77, collect(app(WheelService::class)->segments())->firstWhere('key', 'tiramisu')['weight']);
    }

    /** P2 : un lot archivé dont le produit a quitté la carte ne bloque pas l'enregistrement. */
    public function test_une_archive_dont_le_produit_a_disparu_ne_bloque_rien(): void
    {
        $this->gain('tiramisu', 'Tiramisu', '0688000002');
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => [
            $this->lot(['key' => 'boisson', 'label' => 'Boisson', 'cost_item_id' => $this->boisson->id]),
            $this->lot(['label' => 'Frites', 'cost_item_id' => $this->boisson->id]),
        ]])->assertOk(); // tiramisu → archive
        $this->dessert->delete();

        $envoi = array_map(fn ($s) => $s + ['type' => 'free_item'], app(WheelService::class)->catalogueComplet());
        $this->moi()->postJson('/admin/roue/espace/roue', ['lots' => array_map(fn ($s) => [
            'key' => $s['key'], 'label' => $s['label'], 'type' => $s['type'], 'cost_item_id' => $s['cost_item_id'] ?? null,
            'weight' => $s['weight'], 'quantity' => 0, 'daily_cap' => 0, 'archived' => (bool) ($s['archived'] ?? false),
        ], $envoi)])->assertOk();
        $this->assertTrue(collect(app(WheelService::class)->catalogueComplet())->firstWhere('key', 'tiramisu')['archived']);
    }
}

