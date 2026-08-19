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
