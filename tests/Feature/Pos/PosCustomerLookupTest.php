<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Models\Branch;
use App\Models\User;
use App\Services\Loyalty\LoyaltyQrSigner;
use App\Services\Loyalty\PosCustomerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * RETROUVER LE CLIENT AU COMPTOIR — le maillon qui manquait à toute la fidélité de caisse.
 *
 * ── CE QUE CE BANC PROTÈGE ───────────────────────────────────────────────────────────────────
 * La mécanique de points était complète : crédit automatique, débit, champ de rattachement dans la
 * commande de caisse. Il n'y avait aucun moyen de dire QUI est le client — d'où 2 lignes de gain
 * « surface caisse » dans toute la base.
 *
 * Le danger n'est pas de ne pas trouver : c'est de trouver le MAUVAIS humain. 5 numéros de la base
 * sont portés par plusieurs comptes, l'un par 5. Un service qui choisit à la place du caissier
 * crédite ou débite le solde de quelqu'un d'autre — et personne ne s'en aperçoit avant la plainte.
 */
class PosCustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    private PosCustomerLookupService $recherche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('loyalty.qr.secret', 'test-qr-secret-'.str_repeat('b', 40));

        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro'            => 10,
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points'          => 1000,
        ]);

        $this->recherche = app(PosCustomerLookupService::class);
    }

    /**
     * Un client réel : rôle client, un code, un solde.
     *
     * PIÈGE : `App\Enums\Role::CUSTOMER` vaut **2** — c'est un IDENTIFIANT, pas un nom. En base
     * réelle id=2 est bien « Customer », mais `seedSpatieRoles()` crée les rôles dans un autre ordre
     * et « Chef » y prend le 2. `assignRole(Role::CUSTOMER)` en test attribue donc CHEF, et le
     * compte devient un compte d'ÉQUIPE, invisible au comptoir. On attribue par le NOM.
     */
    private function client(string $phone, int $points, string $code, array $extra = []): User
    {
        $u = User::factory()->create(array_merge([
            'phone'    => $phone,
            'is_guest' => Ask::NO,
        ], $extra));
        $u->assignRole('Customer');
        DB::table('users')->where('id', $u->id)->update(['loyalty_code' => $code, 'loyalty_points' => $points]);

        return $u->fresh();
    }

    // ── LE TÉLÉPHONE, LE MOYEN QUE LE PROPRIÉTAIRE PRÉFÈRE ───────────────────────────────────

    /**
     * « L'accumulation avec le numéro de téléphone, c'est préférable. » Et les QUATRE écritures du
     * même numéro doivent le retrouver : 62 comptes sur 348 portent une forme non normalisée.
     */
    public function test_le_meme_humain_est_retrouve_quelle_que_soit_l_ecriture_de_son_numero(): void
    {
        $this->client('0612345678', 1500, 'TEL0001');

        foreach (['0612345678', '06 12 34 56 78', '+33612345678', '33612345678', '612345678'] as $saisie) {
            $r = $this->recherche->byPhone($saisie);

            $this->assertSame(PosCustomerLookupService::TROUVE, $r['status'], "écriture « {$saisie} » non reconnue");
            $this->assertSame('TEL0001', $r['customer']['loyalty_code']);
        }
    }

    /**
     * LE CŒUR DU DANGER. Plusieurs comptes sur un numéro : le service ne choisit PAS. Il rend la
     * liste avec de quoi trancher, et c'est l'humain qui tranche.
     */
    public function test_plusieurs_comptes_sur_un_numero_ne_sont_JAMAIS_tranches_par_la_machine(): void
    {
        $this->client('0600000777', 1200, 'JUM0001', ['name' => 'Karim B']);
        $this->client('0600000777', 300,  'JUM0002', ['name' => 'Sophie M']);

        $r = $this->recherche->byPhone('0600000777');

        $this->assertSame(PosCustomerLookupService::AMBIGU, $r['status'],
            'la machine a choisi un compte : elle peut débiter le solde de quelqu\'un d\'autre');
        $this->assertCount(2, $r['candidates']);

        // De quoi trancher, sans exposer le numéro complet devant la file d'attente.
        $noms = array_column($r['candidates'], 'name');
        $this->assertContains('Karim B', $noms);
        $this->assertContains('Sophie M', $noms);
        foreach ($r['candidates'] as $c) {
            $this->assertSame('06 •• •• •• 77', $c['phone_masked']);
            $this->assertArrayHasKey('balance', $c);
        }
    }

    /** Une saisie partielle n'est pas un numéro : on ne lance pas une recherche sur trois chiffres. */
    public function test_une_frappe_partielle_ne_declenche_pas_de_recherche(): void
    {
        $this->client('0612345678', 1500, 'TEL0002');

        foreach (['06', '0612', '06123456'] as $partiel) {
            $r = $this->recherche->byPhone($partiel);
            $this->assertSame(PosCustomerLookupService::INVALIDE, $r['status'], "« {$partiel} » a été cherché");
            $this->assertSame('PHONE_TOO_SHORT', $r['error_code']);
        }
    }

    // ── CE QUE LE COMPTOIR NE DOIT PAS VOIR ──────────────────────────────────────────────────

    /** Un caissier qui cherche un numéro ne doit pas tomber sur un COLLÈGUE et le créditer. */
    public function test_un_compte_de_l_equipe_est_invisible_au_comptoir(): void
    {
        $branche = Branch::factory()->create();
        $collegue = User::factory()->create(['phone' => '0699000111', 'branch_id' => $branche->id]);
        $collegue->assignRole('POS Operator');
        DB::table('users')->where('id', $collegue->id)->update(['loyalty_code' => 'STAFF01', 'loyalty_points' => 9000]);

        $this->assertSame(PosCustomerLookupService::INTROUVABLE, $this->recherche->byPhone('0699000111')['status']);
        $this->assertSame(PosCustomerLookupService::INTROUVABLE, $this->recherche->byCode('STAFF01')['status']);
    }

    /**
     * Un compte SUPPRIMÉ ne revient pas d'entre les morts. La base en contient 4.
     *
     * Le piège est `withoutGlobalScopes()` sans argument, qui retire aussi le soft-delete —
     * rencontré deux fois le 10 août, une fois dans un tableau de bord d'argent.
     */
    public function test_un_compte_supprime_ne_ressuscite_pas(): void
    {
        $parti = $this->client('0655443322', 5000, 'PARTI01');
        $parti->delete();

        $this->assertSame(PosCustomerLookupService::INTROUVABLE, $this->recherche->byPhone('0655443322')['status']);
        $this->assertSame(PosCustomerLookupService::INTROUVABLE, $this->recherche->byCode('PARTI01')['status']);
    }

    /** Un compte sans code fidélité n'est pas un adhérent : il n'a rien à cumuler. */
    public function test_un_compte_sans_code_fidelite_n_est_pas_un_adherent(): void
    {
        $u = User::factory()->create(['phone' => '0644332211', 'is_guest' => Ask::NO]);
        $u->assignRole('Customer');

        $this->assertSame(PosCustomerLookupService::INTROUVABLE, $this->recherche->byPhone('0644332211')['status']);
    }

    /** Un invité de passage EST un client : `is_guest = YES` ne l'exclut pas. */
    public function test_un_invite_de_passage_est_bien_un_client(): void
    {
        $u = User::factory()->create(['phone' => '0611223344', 'is_guest' => Ask::YES]);
        DB::table('users')->where('id', $u->id)->update(['loyalty_code' => 'INVIT01', 'loyalty_points' => 1000]);

        $r = $this->recherche->byPhone('0611223344');

        $this->assertSame(PosCustomerLookupService::TROUVE, $r['status'],
            '13 clients réels de la base sont dans cet état — les exclure les priverait de leurs points');
    }

    // ── CE QUE L'ÉCRAN AFFICHE ───────────────────────────────────────────────────────────────

    /**
     * Le comptoir doit lire ce qui est UTILISABLE, pas le solde brut. Avec le seuil du propriétaire
     * (1000 points = 10 €), un client à 900 points ne peut rien utiliser — et il faut le DIRE.
     */
    public function test_l_ecran_dit_ce_qui_est_utilisable_et_ce_qui_manque(): void
    {
        $this->client('0612000001', 900, 'SOUS001');
        $this->client('0612000002', 2350, 'ASSEZ01');

        $sous = $this->recherche->byPhone('0612000001')['customer'];
        $this->assertSame(900, $sous['balance']);
        $this->assertSame(0, $sous['usable_points'], '900 points ne sont pas utilisables sous un seuil de 1000');
        $this->assertFalse($sous['can_use']);
        $this->assertSame(100, $sous['missing_points'], 'il manque 100 points : le dire fait revenir le client');

        $assez = $this->recherche->byPhone('0612000002')['customer'];
        $this->assertSame(2350, $assez['balance']);
        $this->assertSame(23.50, $assez['balance_eur']);
        $this->assertSame(2300, $assez['usable_points'], 'le reste de 50 points n\'est pas offert');
        $this->assertSame(23.00, $assez['usable_eur']);
        $this->assertTrue($assez['can_use']);
        $this->assertSame(0, $assez['missing_points']);
        $this->assertSame(1000, $assez['effective_floor']);
    }

    /** Ni numéro ni e-mail en clair : la file d'attente lit par-dessus l'épaule du caissier. */
    public function test_ni_numero_ni_email_en_clair_sur_l_ecran(): void
    {
        $this->client('0612345678', 1500, 'PII0001', ['email' => 'karim.bensalah@example.com']);

        $c = $this->recherche->byCode('PII0001')['customer'];

        $this->assertSame('06 •• •• •• 78', $c['phone_masked']);
        $this->assertSame('k•••@example.com', $c['email_masked']);
        $this->assertStringNotContainsString('0612345678', json_encode($c));
        $this->assertStringNotContainsString('karim.bensalah', json_encode($c));
    }

    /** Un compte créé au comptoir peut n'avoir aucun nom : pas de ligne fantôme sur la tablette. */
    public function test_un_client_sans_nom_reste_affichable(): void
    {
        $u = User::factory()->create(['phone' => '0688776655', 'is_guest' => Ask::YES, 'name' => '']);
        DB::table('users')->where('id', $u->id)->update(['loyalty_code' => 'ANON123456', 'loyalty_points' => 1000]);

        $c = $this->recherche->byPhone('0688776655')['customer'];

        $this->assertSame('Client ANON12', $c['name']);
        $this->assertNotSame('', trim($c['name']));
    }

    // ── LE QR SCANNÉ À LA TABLETTE ───────────────────────────────────────────────────────────

    /** Le QR signé du client : la preuve la plus forte, et on dit qu'elle a servi. */
    public function test_un_qr_signe_identifie_le_client(): void
    {
        $client = $this->client('0612345699', 1500, 'QRSIGN1');
        $jeton  = app(LoyaltyQrSigner::class)->sign($client->id, 'QRSIGN1')['token'];

        $r = $this->recherche->byQr($jeton);

        $this->assertSame(PosCustomerLookupService::TROUVE, $r['status']);
        $this->assertSame('QRSIGN1', $r['customer']['loyalty_code']);
        $this->assertSame('qr_signed', $r['via'], 'le comptoir a le droit de savoir quelle preuve a servi');
    }

    /**
     * À USAGE UNIQUE. Un deuxième scan du même QR est refusé, avec un message que le caissier peut
     * lire au client — pas un code technique.
     */
    public function test_le_meme_qr_ne_sert_pas_deux_fois(): void
    {
        $client = $this->client('0612345698', 1500, 'QRONCE1');
        $jeton  = app(LoyaltyQrSigner::class)->sign($client->id, 'QRONCE1')['token'];

        $this->assertSame(PosCustomerLookupService::TROUVE, $this->recherche->byQr($jeton)['status']);

        $second = $this->recherche->byQr($jeton);
        $this->assertSame(PosCustomerLookupService::INVALIDE, $second['status']);
        $this->assertStringContainsString('réafficher', $second['message'],
            'le caissier doit pouvoir dire au client quoi faire');
    }

    /**
     * Un QR en clair ne vaut pas mieux qu'un code dicté — et on ne prétend PAS le contraire.
     * Le distinguer permet un jour de retirer ce format sur preuve d'usage.
     */
    public function test_un_qr_en_clair_est_traite_comme_un_code_et_annonce_comme_tel(): void
    {
        $this->client('0612345697', 1500, 'QRPLAIN');

        foreach (['FK:QRPLAIN', 'QRPLAIN', 'fk:qrplain'] as $brut) {
            $r = $this->recherche->byQr($brut);
            $this->assertSame(PosCustomerLookupService::TROUVE, $r['status'], "« {$brut} » non reconnu");
            $this->assertSame('qr_plaintext', $r['via'], 'un QR en clair ne doit pas passer pour une preuve signée');
        }
    }

    /** Un QR abîmé ou un scan de n'importe quoi ne fait pas tomber l'écran. */
    public function test_un_qr_illisible_est_refuse_proprement(): void
    {
        foreach (['', '   ', 'lqr.nimportequoi.pasunesignature', str_repeat('x', 600)] as $brut) {
            $r = $this->recherche->byQr($brut);
            $this->assertContains($r['status'], [PosCustomerLookupService::INVALIDE, PosCustomerLookupService::INTROUVABLE]);
            $this->assertArrayHasKey('error_code', $r);
        }
    }
}
