<?php

namespace Tests\Feature\Identity;

use App\Services\Identity\CustomerAccount;
use App\Services\Identity\PhoneIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * « CE NUMÉRO » ET « CE CLIENT » — les deux définitions que tout le monde emprunte.
 *
 * ── POURQUOI CE BANC EXISTE ──────────────────────────────────────────────────────────────────
 * Ces deux questions ont chacune été posées deux fois dans le logiciel, avec deux réponses
 * différentes, et chaque divergence a coûté de l'argent ou de la confiance :
 *
 *   « ce numéro »  — le service qui CRÉAIT le compte connaissait quatre écritures, celui qui
 *                    CRÉDITAIT les points n'en cherchait qu'une, trente lignes plus loin.
 *                    62 comptes sur 348 portent une forme non normalisée : pour eux, le comptoir
 *                    ordonnait « aucun compte à ce numéro, crée-le puis reviens » — impossible.
 *
 *   « ce client »  — le réflexe `is_guest === YES` aurait privé de leurs points les 13 clients
 *                    réellement inscrits (`is_guest = NO` + rôle client).
 *
 * Elles vivent désormais dans un foyer neutre, et la roue comme la caisse délèguent ici.
 */
class PhoneIdentityTest extends TestCase
{
    use RefreshDatabase;

    private PhoneIdentity $tel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tel = app(PhoneIdentity::class);
    }

    // ── « CE NUMÉRO » ────────────────────────────────────────────────────────────────────────

    /** Les quatre écritures du même humain se ramènent à la forme nationale. */
    public function test_les_ecritures_d_un_meme_numero_se_ramenent_a_une_seule(): void
    {
        foreach (['0612345678', '06 12 34 56 78', '06.12.34.56.78', '+33 6 12 34 56 78', '33612345678'] as $ecriture) {
            $this->assertSame('0612345678', $this->tel->normalize($ecriture), "« {$ecriture} »");
        }
    }

    /**
     * LA RELATION EST SYMÉTRIQUE — chaque écriture doit retrouver toutes les autres.
     *
     * L'asymétrie a été mesurée le 10 août : « 0612345678 » listait bien « 612345678 », mais
     * « 612345678 » ne listait pas « 0612345678 ». Un caissier qui tape le numéro sans le zéro ne
     * trouvait donc personne, alors que le compte existait.
     */
    public function test_chaque_ecriture_retrouve_toutes_les_autres(): void
    {
        $ecritures = ['0612345678', '612345678', '33612345678', '+33612345678'];

        foreach ($ecritures as $depart) {
            $variantes = $this->tel->variants($depart);

            foreach ($ecritures as $cible) {
                $this->assertContains($cible, $variantes,
                    "en partant de « {$depart} », on ne retrouve pas « {$cible} » : "
                    . 'le même humain resterait invisible selon la façon dont on le cherche');
            }
        }
    }

    /**
     * ON NE DEVINE AUCUN INDICATIF ÉTRANGER. Mieux vaut un compte de plus qu'un lot ou des points
     * crédités au mauvais humain.
     */
    public function test_aucun_indicatif_etranger_n_est_devine(): void
    {
        // Belgique (+32), Suisse (+41), Maroc (+212) : on les laisse tels quels, sans variantes.
        foreach (['+32470123456', '+41791234567', '+212612345678'] as $etranger) {
            $variantes = $this->tel->variants($etranger);

            $this->assertCount(1, $variantes, "« {$etranger} » a produit des variantes inventées");
            $this->assertSame([$this->tel->normalize($etranger)], $variantes);
        }
    }

    /** Une frappe partielle n'est pas un numéro : elle ne doit pas déclencher de recherche. */
    public function test_une_frappe_partielle_n_est_pas_un_numero(): void
    {
        foreach (['', '0', '06', '0612', '06123456'] as $partiel) {
            $this->assertFalse($this->tel->looksComplete($partiel), "« {$partiel} » jugé complet");
        }

        // 9 chiffres (zéro de tête oublié) et 10 chiffres sont tous deux acceptables.
        $this->assertTrue($this->tel->looksComplete('612345678'));
        $this->assertTrue($this->tel->looksComplete('0612345678'));
        $this->assertTrue($this->tel->looksComplete('06 12 34 56 78'));
    }

    /** Le masque laisse confirmer le bon client sans exposer le numéro devant la file d'attente. */
    public function test_le_masque_confirme_sans_exposer(): void
    {
        $this->assertSame('06 •• •• •• 78', $this->tel->masked('0612345678'));
        $this->assertSame('06 •• •• •• 78', $this->tel->masked('+33 6 12 34 56 78'),
            'un même humain doit se masquer de la même façon quelle que soit l\'écriture');
        $this->assertStringNotContainsString('1234', $this->tel->masked('0612345678'));

        // Une valeur trop courte ne doit pas produire un masque trompeur ni une erreur.
        $this->assertSame('••', $this->tel->masked('06'));
        $this->assertSame('••', $this->tel->masked(''));
    }

    // ── « CE CLIENT » ────────────────────────────────────────────────────────────────────────

    /**
     * LE PIÈGE PRINCIPAL. Un client réellement inscrit porte `is_guest = NO` : le critère
     * `is_guest === YES` aurait effacé les 13 clients inscrits de la base.
     */
    public function test_un_client_inscrit_est_un_client_meme_avec_is_guest_a_non(): void
    {
        $this->seedSpatieRoles();
        $comptes = app(CustomerAccount::class);

        $inscrit = \App\Models\User::factory()->create(['is_guest' => \App\Enums\Ask::NO]);
        $inscrit->assignRole(CustomerAccount::ROLE_NAME);

        $this->assertTrue($comptes->isCustomer($inscrit->fresh()),
            '13 clients réels de la base sont dans cet état');
        $this->assertFalse($comptes->isStaff($inscrit->fresh()));
    }

    /** Un invité de passage est un client aussi : il commande, il cumule. */
    public function test_un_invite_de_passage_est_un_client(): void
    {
        $comptes = app(CustomerAccount::class);
        $invite  = \App\Models\User::factory()->create(['is_guest' => \App\Enums\Ask::YES]);

        $this->assertTrue($comptes->isCustomer($invite));
    }

    /** L'ÉQUIPE n'est pas une clientèle : un caissier ne doit pas se créditer lui-même. */
    public function test_l_equipe_n_est_pas_une_clientele(): void
    {
        $this->seedSpatieRoles();
        $comptes = app(CustomerAccount::class);

        foreach (['Admin', 'Branch Manager', 'POS Operator', 'Chef', 'Stuff'] as $role) {
            $membre = \App\Models\User::factory()->create(['is_guest' => \App\Enums\Ask::NO]);
            $membre->assignRole($role);

            $this->assertTrue($comptes->isStaff($membre->fresh()), "« {$role} » n'est pas vu comme équipe");
        }
    }

    /**
     * LE CRITÈRE NE DOIT PAS DÉPENDRE DE L'ORDRE D'INSERTION DES RÔLES.
     *
     * `App\Enums\Role::CUSTOMER` vaut 2 — un IDENTIFIANT. En production id=2 est bien « Customer »,
     * mais rien ne l'impose : sur une base réinstallée, un autre rôle peut prendre le 2, et tous les
     * clients disparaîtraient alors du comptoir. On éprouve donc le cas explicitement.
     */
    public function test_le_critere_client_tient_meme_si_l_identifiant_2_est_un_autre_role(): void
    {
        $this->seedSpatieRoles();
        $comptes = app(CustomerAccount::class);

        $roleAuDeux = DB::table('roles')->where('id', 2)->value('name');
        $this->assertNotSame('Customer', $roleAuDeux,
            'le harnais sème un autre rôle sur l\'identifiant 2 — c\'est la situation qu\'on éprouve');

        $client = \App\Models\User::factory()->create(['is_guest' => \App\Enums\Ask::NO]);
        $client->assignRole('Customer');

        $this->assertTrue($comptes->isCustomer($client->fresh()),
            'le client est reconnu par son NOM de rôle, pas par un identifiant qui peut glisser');
    }
}
