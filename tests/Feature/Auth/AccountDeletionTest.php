<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\PhoneDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [APPS 2026-08-19] Suppression de compte — exigence de publication.
 *
 * POURQUOI CETTE SUITE EXISTE
 * ---------------------------
 * Apple (règle 5.1.1 v) et Google Play imposent qu'une application permettant de créer
 * un compte permette aussi de le SUPPRIMER depuis l'application. Le site affichait
 * « pour supprimer ton compte, demande en caisse ou appelle le restaurant » : c'est un
 * refus de publication, pas un détail.
 *
 * Mais rebrancher un bouton ne suffisait pas. La méthode existante faisait une
 * suppression DOUCE en laissant le nom, l'e-mail et le téléphone dans la ligne : le
 * compte disparaissait de l'écran, les données restaient. Pire, le parcours
 * d'inscription RESSUSCITE un compte invité supprimé qui porte le même téléphone —
 * « supprimé » revenait donc à la vie, historique et points compris, dès que la personne
 * redonnait son numéro.
 *
 * Ces tests vérifient donc ce qui compte vraiment : que les données personnelles ont
 * DISPARU, et que le compte ne peut plus être retrouvé par aucun des trois chemins qui
 * le retrouvaient avant (téléphone, e-mail, identité Apple/Google).
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        config(['app.api_key' => self::API_KEY]);

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();

        $table = config('settings.repositories.database.table', 'settings');
        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                ['key' => 'site_default_branch', 'group' => 'site'],
                ['payload' => json_encode((string) $this->branch->id), 'created_at' => now(), 'updated_at' => now()]
            );
        }

        $this->withHeaders([
            'x-api-key' => self::API_KEY,
            'Accept'    => 'application/json',
        ]);
    }

    /** Un client comme il en existe vraiment : téléphone, e-mail, identité Apple, points. */
    private function client(array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'branch_id'         => 0,
            'name'              => 'Kossay B.',
            'phone'             => '0612345678',
            'email'             => 'client@example.test',
            'email_verified_at' => now()->timestamp,
            'status'            => Status::ACTIVE,
            'is_guest'          => Ask::YES,
        ], $extra));
        $user->assignRole('Customer');
        $user->apple_sub = 'sub-apple-client';
        $user->google_sub = 'sub-google-client';
        $user->loyalty_code = 'ABCD1234';
        // Numéro DÉCLARÉ après une connexion Apple/Google : une donnée personnelle au même
        // titre que les autres, dans une colonne distincte — donc facile à oublier.
        $user->contact_phone = '0699999999';
        $user->save();

        return $user;
    }

    private function jeton(User $user): string
    {
        return $user->createToken('auth_token', ['kiosk:order'])->plainTextToken;
    }

    /** @test */
    public function un_client_peut_supprimer_son_compte_depuis_l_application(): void
    {
        $user = $this->client();

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')
            ->assertStatus(200)
            ->assertJson(['status' => true]);

        $this->assertNotNull(
            User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->find($user->id)->deleted_at,
            'Le compte doit être supprimé.'
        );
    }

    /** @test */
    public function les_donnees_personnelles_sont_reellement_effacees(): void
    {
        // Le cœur du sujet. Une suppression qui laisse le nom, l'e-mail et le téléphone
        // dans la ligne n'est pas une suppression : c'est une désactivation qui en a l'air.
        $user = $this->client();

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')->assertStatus(200);

        $apres = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->find($user->id);

        $this->assertNotSame('Kossay B.', $apres->name, 'Le nom doit avoir disparu.');
        $this->assertNull($apres->email, 'L\'e-mail doit avoir disparu.');
        $this->assertNull($apres->apple_sub, 'L\'identité Apple doit avoir disparu.');
        $this->assertNull($apres->google_sub, 'L\'identité Google doit avoir disparu.');
        $this->assertNull($apres->loyalty_code, 'Le code fidélité doit avoir disparu.');
        $this->assertNotSame('0612345678', $apres->phone, 'Le téléphone doit avoir disparu.');
        // Le numéro DÉCLARÉ vit dans une autre colonne : c'est exactement le genre de champ
        // qu'une suppression « complète » oublie. Constaté en cassant volontairement le code :
        // sans cette ligne, retirer l'effacement ne faisait échouer AUCUN test.
        $this->assertNull($apres->contact_phone, 'Le numéro déclaré doit avoir disparu aussi.');
        $this->assertNull($apres->numeroJoignable(), 'Plus aucun numéro ne doit rester joignable.');

        // Le téléphone ne peut pas être null (colonne NOT NULL) : il porte une sentinelle.
        // Elle doit être masquée par le juge canonique, sinon elle FUIRAIT à l'écran d'une
        // caisse ou d'un ticket — on aurait remplacé une donnée personnelle par un déchet
        // visible.
        $this->assertNull(PhoneDisplay::safe((string) $apres->phone),
            'La sentinelle de suppression ne doit jamais s\'afficher comme un numéro.');
    }

    /** @test */
    public function le_complement_d_adresse_des_commandes_passees_est_efface(): void
    {
        // [test-e2e stores F9-004] « 2e étage, code 1234 » restait attaché à la commande après la suppression du compte.
        $user = $this->client();
        $commande = Order::factory()->create(['user_id' => $user->id, 'branch_id' => $this->branch->id, 'status' => OrderStatus::DELIVERED]);
        $id = DB::table('order_addresses')->insertGetId([
            'order_id' => $commande->id, 'user_id' => $user->id, 'label' => 'Maison',
            'address' => '10 Place Jean Jaurès 62110 Hénin-Beaumont', 'apartment' => '2e étage, code 1234',
            'latitude' => '50.42', 'longitude' => '2.95', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')->assertStatus(200);

        $ligne = DB::table('order_addresses')->find($id);
        $this->assertNull($ligne->apartment, 'Le code d\'entrée doit avoir disparu.');
        $this->assertSame('Livraison', $ligne->label, 'Le libellé personnel doit avoir disparu.');
        $this->assertSame('10 Place Jean Jaurès 62110 Hénin-Beaumont', $ligne->address, 'L\'adresse de livraison de la commande reste (tournée, conservation).');
    }

    /** @test */
    public function les_courses_uber_direct_perdent_telephone_nom_et_consignes(): void
    {
        // [test-e2e stores P-012] La course gardait téléphone, nom et code d'entrée du destinataire après la suppression.
        $user = $this->client();
        $commande = Order::factory()->create(['user_id' => $user->id, 'branch_id' => $this->branch->id, 'status' => OrderStatus::DELIVERED]);
        $id = DB::table('uber_direct_deliveries')->insertGetId([
            'branch_id' => $this->branch->id, 'order_id' => $commande->id, 'status' => 'delivered', 'quote_fee_cents' => 742,
            'dropoff_phone' => '+33612345678', 'dropoff_instructions' => '2e étage, code 1234',
            'delivery_payload' => json_encode(['id' => 'del_1', 'dropoff' => ['name' => 'Kossay B.', 'phone_number' => '+33612345678', 'notes' => '2e étage, code 1234', 'address' => '10 Place Jean Jaurès']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')->assertStatus(200);

        $ligne = DB::table('uber_direct_deliveries')->find($id);
        $brut = json_decode($ligne->delivery_payload, true);
        $this->assertNull($ligne->dropoff_phone);
        $this->assertNull($ligne->dropoff_instructions);
        $this->assertArrayNotHasKey('phone_number', $brut['dropoff']);
        $this->assertArrayNotHasKey('name', $brut['dropoff']);
        $this->assertArrayNotHasKey('notes', $brut['dropoff']);
        $this->assertSame(742, (int) $ligne->quote_fee_cents, 'Le prix de la course reste.');
        $this->assertSame('del_1', $brut['id'], 'Le suivi de la course reste.');
    }

    /**
     * @test
     * [test-e2e stores S17-003 · 2026-11-13] Un compte client supprimé AVANT les correctifs (simple suppression douce : nom,
     * e-mail, téléphone intacts ; complément d'adresse et téléphone de course encore là) est rattrapé par la commande — en
     * simulation rien ne bouge ; avec --appliquer tout est effacé ; un compte d'exploitation supprimé n'est jamais touché.
     */
    public function la_commande_de_rattrapage_efface_les_anciens_comptes_supprimes_et_seulement_eux(): void
    {
        $ancien = $this->client(['email' => 'ancien@example.test', 'phone' => '0611111111', 'name' => 'Ancien Client']);
        $commande = Order::factory()->create(['user_id' => $ancien->id, 'branch_id' => $this->branch->id, 'status' => OrderStatus::DELIVERED]);
        $adresse = DB::table('order_addresses')->insertGetId([
            'order_id' => $commande->id, 'user_id' => $ancien->id, 'label' => 'Maison',
            'address' => '10 Place Jean Jaurès 62110 Hénin-Beaumont', 'apartment' => 'code 1234',
            'latitude' => '50.42', 'longitude' => '2.95', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $course = DB::table('uber_direct_deliveries')->insertGetId([
            'branch_id' => $this->branch->id, 'order_id' => $commande->id, 'status' => 'delivered', 'quote_fee_cents' => 742,
            'dropoff_phone' => '+33611111111', 'dropoff_instructions' => 'code 1234',
            'delivery_payload' => json_encode(['id' => 'del_9', 'dropoff' => ['name' => 'Ancien', 'phone_number' => '+33611111111']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ancien->delete();   // l'ancienne suppression : douce, sans effacement

        $employe = User::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Employé Gardé', 'email' => 'staff@example.test', 'phone' => '0622222222']);
        $employe->assignRole('POS Operator');
        $employe->delete();

        $this->artisan('comptes:rattraper-suppressions')->assertSuccessful();
        $this->assertSame('ancien@example.test', User::withTrashed()->find($ancien->id)->email, 'Simulation : rien n\'est écrit.');
        $this->assertSame('code 1234', DB::table('order_addresses')->find($adresse)->apartment);

        $this->artisan('comptes:rattraper-suppressions --appliquer')->assertSuccessful();
        $apres = User::withTrashed()->find($ancien->id);
        $this->assertNull($apres->email);
        $this->assertSame('Compte supprimé', $apres->name);
        $this->assertStringStartsWith('PENDING_DELETED_', (string) $apres->phone);
        $this->assertNull(DB::table('order_addresses')->find($adresse)->apartment);
        $this->assertSame('10 Place Jean Jaurès 62110 Hénin-Beaumont', DB::table('order_addresses')->find($adresse)->address, 'L\'adresse de la commande reste.');
        $ligne = DB::table('uber_direct_deliveries')->find($course);
        $this->assertNull($ligne->dropoff_phone);
        $this->assertArrayNotHasKey('phone_number', json_decode($ligne->delivery_payload, true)['dropoff']);
        $this->assertSame('staff@example.test', User::withTrashed()->find($employe->id)->email, 'Un compte d\'exploitation n\'est jamais touché.');

        // Rejouable sans effet.
        $telephone = $apres->phone;
        $this->artisan('comptes:rattraper-suppressions --appliquer')->assertSuccessful();
        $this->assertSame($telephone, User::withTrashed()->find($ancien->id)->phone);
    }

    /**
     * @test
     * [test-e2e stores S18-001 · 2026-11-13] Supprimer un client depuis l'administration efface réellement son identité et ses
     * traces (même service que la suppression par le client), au lieu d'une simple suppression douce.
     */
    public function supprimer_un_client_depuis_l_administration_efface_ses_donnees(): void
    {
        $client = $this->client(['email' => 'admin-supprime@example.test', 'phone' => '0633333333', 'name' => 'Client Admin']);
        \App\Models\Subscriber::create(['email' => 'admin-supprime@example.test']);
        $commande = Order::factory()->create(['user_id' => $client->id, 'branch_id' => $this->branch->id, 'status' => OrderStatus::DELIVERED]);
        $adresse = DB::table('order_addresses')->insertGetId([
            'order_id' => $commande->id, 'user_id' => $client->id, 'label' => 'Maison',
            'address' => '1 Rue Test 62110 Hénin-Beaumont', 'apartment' => 'code 9999',
            'latitude' => '50.42', 'longitude' => '2.95', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(\App\Services\CustomerService::class)->destroy($client);

        $apres = User::withTrashed()->find($client->id);
        $this->assertNotNull($apres->deleted_at);
        $this->assertNull($apres->email);
        $this->assertSame('Compte supprimé', $apres->name);
        $this->assertStringStartsWith('PENDING_DELETED_', (string) $apres->phone);
        $this->assertNull($apres->apple_sub);
        $this->assertSame(0, \App\Models\Subscriber::whereRaw('LOWER(email) = ?', ['admin-supprime@example.test'])->count(), 'Plus d\'offres par e-mail.');
        $this->assertNull(DB::table('order_addresses')->find($adresse)->apartment);
    }

    /** @test [S18-002] Le rattrapage voit (et efface) un nom/téléphone restés seulement dans la réponse brute d'Uber. */
    public function le_rattrapage_voit_les_traces_uber_restees_dans_la_reponse_brute(): void
    {
        $client = $this->client(['email' => 'brut@example.test', 'phone' => '0644444444']);
        $commande = Order::factory()->create(['user_id' => $client->id, 'branch_id' => $this->branch->id, 'status' => OrderStatus::DELIVERED]);
        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($client))->postJson('/api/auth/delete-account')->assertStatus(200);
        // Un webhook arrivé APRÈS la suppression remet nom et téléphone dans la réponse brute (colonnes vides).
        $course = DB::table('uber_direct_deliveries')->insertGetId([
            'branch_id' => $this->branch->id, 'order_id' => $commande->id, 'status' => 'delivered', 'quote_fee_cents' => 500,
            'dropoff_phone' => null, 'dropoff_instructions' => null,
            'delivery_payload' => json_encode(['id' => 'del_w', 'dropoff' => ['name' => 'Brut', 'phone_number' => '+33644444444']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('comptes:rattraper-suppressions')->expectsOutputToContain('courses Uber avec téléphone/consignes : 1')->assertSuccessful();
        $this->artisan('comptes:rattraper-suppressions --appliquer')->assertSuccessful();
        $brut = json_decode(DB::table('uber_direct_deliveries')->find($course)->delivery_payload, true);
        $this->assertArrayNotHasKey('phone_number', $brut['dropoff']);
        $this->assertArrayNotHasKey('name', $brut['dropoff']);
        $this->artisan('comptes:rattraper-suppressions')->expectsOutputToContain('courses Uber avec téléphone/consignes : 0')->assertSuccessful();
    }

    /** @test */
    public function un_compte_supprime_ne_peut_plus_etre_ressuscite_par_son_telephone(): void
    {
        // Le parcours d'inscription cherche un compte invité par TÉLÉPHONE, y compris
        // supprimé, et le restaure. C'est ce chemin qui faisait revenir à la vie un compte
        // « supprimé ». On vérifie que la requête qu'il exécute ne trouve plus rien.
        $user = $this->client();

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')->assertStatus(200);

        $retrouve = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()
            ->where('phone', '0612345678')->first();

        $this->assertNull($retrouve, 'Le numéro ne doit plus désigner aucun compte, même supprimé.');
    }

    /** @test */
    public function un_compte_supprime_ne_peut_plus_etre_retrouve_par_email_ni_par_identite_sociale(): void
    {
        $user = $this->client();

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')->assertStatus(200);

        $q = fn () => User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed();

        $this->assertNull($q()->whereRaw('LOWER(email) = ?', ['client@example.test'])->first(),
            'L\'e-mail ne doit plus désigner aucun compte.');
        $this->assertNull($q()->where('apple_sub', 'sub-apple-client')->first(),
            'L\'identité Apple ne doit plus désigner aucun compte.');
        $this->assertNull($q()->where('google_sub', 'sub-google-client')->first(),
            'L\'identité Google ne doit plus désigner aucun compte.');
    }

    /** @test */
    public function toutes_les_sessions_sont_revoquees_pas_seulement_celle_du_telephone(): void
    {
        // Un client qui supprime son compte depuis son téléphone ne doit pas laisser une
        // session vivante sur la tablette où il s'était connecté le mois dernier.
        $user = $this->client();
        $jetonTelephone = $this->jeton($user);
        $user->createToken('auth_token', ['kiosk:order']);   // « autre appareil »
        $user->createToken('auth_token', ['kiosk:order']);   // « encore un autre »

        $this->assertSame(3, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer ' . $jetonTelephone)
            ->postJson('/api/auth/delete-account')->assertStatus(200);

        $this->assertSame(0, DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)->count(),
            'Aucune session ne doit survivre à la suppression du compte.');
    }

    /** @test */
    public function la_suppression_est_refusee_tant_qu_une_commande_est_en_cours(): void
    {
        // Refus temporaire assumé : supprimer le client d'une commande que la cuisine est
        // en train de préparer laisserait la caisse sans personne à appeler au retrait.
        $user = $this->client();

        Order::factory()->create([
            'user_id'   => $user->id,
            'branch_id' => $this->branch->id,
            'status'    => OrderStatus::PENDING,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->jeton($user))
            ->postJson('/api/auth/delete-account')
            ->assertStatus(422)
            ->assertJson(['status' => false]);

        $apres = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->withTrashed()->find($user->id);
        $this->assertNull($apres->deleted_at, 'Le compte ne doit pas avoir été supprimé.');
        $this->assertSame('0612345678', $apres->phone, 'Un refus ne doit RIEN effacer.');
    }
}
