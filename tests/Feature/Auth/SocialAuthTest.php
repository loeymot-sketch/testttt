<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use App\Support\PhoneDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [APPS 2026-08-19] Connexion Apple / Google + téléphone obligatoire.
 *
 * CE QUE CETTE SUITE DOIT PROUVER
 * -------------------------------
 * Un jeton d'identité est un texte que le CLIENT nous envoie. Sa charge utile est
 * lisible en clair : n'importe qui peut en fabriquer un qui affirme « je suis le client
 * n°42 ». Ce qui distingue une connexion sûre d'une porte ouverte, c'est UNIQUEMENT la
 * vérification de la signature et des revendications. Les tests ci-dessous attaquent donc
 * d'abord par le refus : jeton non signé, signé par la mauvaise clé, émis pour une autre
 * application, expiré, ou déclarant « aucun algorithme ». Un chemin heureux qui passe ne
 * prouve rien tant que ces refus-là ne sont pas prouvés aussi.
 *
 * Le second sujet est l'exigence de l'exploitant : le téléphone. On vérifie qu'il est
 * réclamé, que le SERVEUR refuse la commande tant qu'il manque (un écran se contourne en
 * fermant l'application), et qu'un numéro déjà rattaché à un autre compte ne peut pas
 * être capté — sinon deux personnes partageraient un historique et une fidélité.
 */
class SocialAuthTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key';
    private const AUDIENCE = 'fr.lecayenne.app';
    private const KID = 'cle-de-test-1';

    /** Paire RSA de test, générée une seule fois (coûteux : ~100 ms par génération). */
    private static $paire = null;
    private static ?array $details = null;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }
        config(['app.api_key' => self::API_KEY]);
        config(['services.apple.audiences' => [self::AUDIENCE]]);
        config(['services.google.audiences' => [self::AUDIENCE]]);

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

        $this->faireTrousseau();
    }

    // ---------------------------------------------------------------- outillage

    private static function paire()
    {
        if (self::$paire === null) {
            self::$paire = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            self::$details = openssl_pkey_get_details(self::$paire);
        }

        return self::$paire;
    }

    private static function b64url(string $brut): string
    {
        return rtrim(strtr(base64_encode($brut), '+/', '-_'), '=');
    }

    /** Publie un trousseau JWKS contenant NOTRE clé de test, pour les deux fournisseurs. */
    private function faireTrousseau(): void
    {
        self::paire();
        $jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => self::b64url(self::$details['rsa']['n']),
            'e'   => self::b64url(self::$details['rsa']['e']),
        ]]];

        Http::fake([
            'appleid.apple.com/auth/keys'          => Http::response($jwks, 200),
            'www.googleapis.com/oauth2/v3/certs'   => Http::response($jwks, 200),
        ]);
    }

    /** Fabrique un jeton signé par la clé de test. */
    private function jeton(array $charge = [], array $enteteSup = [], bool $signer = true): string
    {
        $entete = array_merge(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $enteteSup);
        $charge = array_merge([
            'iss'            => 'https://appleid.apple.com',
            'aud'            => self::AUDIENCE,
            'sub'            => 'sub-client-001',
            'email'          => 'client@example.test',
            'email_verified' => true,
            'iat'            => time() - 10,
            'exp'            => time() + 600,
        ], $charge);

        $signe = self::b64url(json_encode($entete)) . '.' . self::b64url(json_encode($charge));

        if (! $signer) {
            return $signe . '.' . self::b64url('signature-bidon');
        }

        openssl_sign($signe, $signature, self::paire(), OPENSSL_ALGO_SHA256);

        return $signe . '.' . self::b64url($signature);
    }

    private function connexionApple(array $charge = [], array $entete = [], bool $signer = true)
    {
        return $this->postJson('/api/auth/social/apple', ['id_token' => $this->jeton($charge, $entete, $signer)]);
    }

    // ------------------------------------------------------- refus (le cœur du sujet)

    /** @test */
    public function un_jeton_non_signe_est_refuse(): void
    {
        // Charge utile parfaitement crédible, signature bidon. C'est l'attaque la plus
        // simple qui soit : sans vérification, elle donne le compte de n'importe qui.
        $reponse = $this->connexionApple([], [], false);

        $reponse->assertStatus(422);
        $this->assertSame(0, User::where('apple_sub', 'sub-client-001')->count(),
            'Un jeton non signé ne doit créer AUCUN compte.');
    }

    /** @test */
    public function un_jeton_signe_par_une_autre_cle_est_refuse(): void
    {
        $autre = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        $entete = ['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'];
        $charge = [
            'iss' => 'https://appleid.apple.com', 'aud' => self::AUDIENCE, 'sub' => 'sub-intrus',
            'email' => 'intrus@example.test', 'email_verified' => true,
            'iat' => time() - 10, 'exp' => time() + 600,
        ];
        $signe = self::b64url(json_encode($entete)) . '.' . self::b64url(json_encode($charge));
        openssl_sign($signe, $signature, $autre, OPENSSL_ALGO_SHA256);

        $reponse = $this->postJson('/api/auth/social/apple', [
            'id_token' => $signe . '.' . self::b64url($signature),
        ]);

        $reponse->assertStatus(422);
        $this->assertSame(0, User::where('apple_sub', 'sub-intrus')->count());
    }

    /** @test */
    public function un_jeton_sans_algorithme_est_refuse(): void
    {
        // « alg: none » : l'attaque de confusion d'algorithme la plus connue. Elle réussit
        // dès qu'un code se contente de lire l'en-tête au lieu de l'imposer.
        $entete = ['alg' => 'none', 'kid' => self::KID, 'typ' => 'JWT'];
        $charge = [
            'iss' => 'https://appleid.apple.com', 'aud' => self::AUDIENCE, 'sub' => 'sub-none',
            'iat' => time() - 10, 'exp' => time() + 600,
        ];
        $jeton = self::b64url(json_encode($entete)) . '.' . self::b64url(json_encode($charge)) . '.';

        $this->postJson('/api/auth/social/apple', ['id_token' => $jeton])->assertStatus(422);
        $this->assertSame(0, User::where('apple_sub', 'sub-none')->count());
    }

    /** @test */
    public function un_jeton_emis_pour_une_autre_application_est_refuse(): void
    {
        // Jeton authentique, signature valide… mais émis pour l'application d'un tiers.
        // Sans contrôle du destinataire, ce tiers pourrait rejouer chez nous les jetons
        // de SES utilisateurs et ouvrir des comptes en leur nom.
        $this->connexionApple(['aud' => 'com.autre.application', 'sub' => 'sub-autre-app'])
            ->assertStatus(422);

        $this->assertSame(0, User::where('apple_sub', 'sub-autre-app')->count());
    }

    /** @test */
    public function un_jeton_expire_est_refuse(): void
    {
        $this->connexionApple(['exp' => time() - 3600, 'iat' => time() - 7200, 'sub' => 'sub-expire'])
            ->assertStatus(422);

        $this->assertSame(0, User::where('apple_sub', 'sub-expire')->count());
    }

    /** @test */
    public function aucune_audience_configuree_ferme_la_porte(): void
    {
        // Tant que l'exploitant n'a pas renseigné ses identifiants d'application, mieux
        // vaut une porte fermée qu'une porte qui accepte n'importe quel destinataire.
        config(['services.apple.audiences' => []]);

        $this->connexionApple(['sub' => 'sub-sans-config'])->assertStatus(422);
        $this->assertSame(0, User::where('apple_sub', 'sub-sans-config')->count());
    }

    /** @test */
    public function un_compte_du_personnel_ne_peut_pas_etre_pris_par_connexion_sociale(): void
    {
        // Un compte NON-INVITÉ (personnel, gérant) ne s'ouvre jamais par cette porte,
        // même si l'adresse correspond : une preuve d'identité grand public ne doit
        // jamais donner accès à la caisse.
        $staff = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => 'patron@example.test',
            'password'  => Hash::make('secret-du-patron'),
            'status'    => Status::ACTIVE,
            'is_guest'  => Ask::NO,
        ]);
        $staff->assignRole('Admin');

        $this->connexionApple(['email' => 'patron@example.test', 'sub' => 'sub-usurpateur'])
            ->assertStatus(422);

        $staff->refresh();
        $this->assertNull($staff->apple_sub, 'Le compte du personnel ne doit pas être rattaché.');
    }

    // ------------------------------------------------------------- chemin nominal

    /** @test */
    public function une_connexion_valide_ouvre_une_session_et_reclame_le_telephone(): void
    {
        $reponse = $this->connexionApple();

        $reponse->assertStatus(201)
            ->assertJson(['status' => true, 'phone_required' => true]);

        $this->assertNotEmpty($reponse->json('token'), 'Un jeton d\'accès doit être délivré.');

        $user = User::where('apple_sub', 'sub-client-001')->first();
        $this->assertNotNull($user, 'Le compte doit être créé.');
        // Pas `assertNull($user->phone)` : la colonne est NOT NULL et User::creating y met
        // une sentinelle `PENDING_CREATE_…`. C'est le juge canonique du projet qui dit s'il
        // s'agit d'un vrai numéro — le même que celui utilisé par le filtre et l'API.
        $this->assertNull(PhoneDisplay::safe((string) $user->phone),
            'Une connexion sociale n\'apporte aucun téléphone réel.');
        $this->assertSame('client@example.test', $user->email);
        $this->assertSame(Ask::YES, (int) $user->is_guest);
        $this->assertNotEmpty($user->loyalty_code, 'Sans code fidélité, le client cumulerait zéro point.');
    }

    /** @test */
    public function deux_connexions_du_meme_compte_ne_creent_pas_de_doublon(): void
    {
        $this->connexionApple()->assertStatus(201);
        $this->faireTrousseau();
        $this->connexionApple()->assertStatus(201);

        $this->assertSame(1, User::where('apple_sub', 'sub-client-001')->count(),
            'Le même identifiant fournisseur doit toujours désigner LE MÊME compte.');
    }

    /** @test */
    public function un_client_existant_est_retrouve_par_son_email_verifie(): void
    {
        // Le client commandait déjà par téléphone ; il installe l'application et se
        // connecte avec Apple. Il doit retrouver SON compte — son historique et ses
        // points — et surtout pas en ouvrir un second.
        $existant = User::factory()->create([
            'branch_id'         => 0,
            'phone'             => '0612345678',
            'email'             => 'client@example.test',
            'email_verified_at' => now()->timestamp,
            'status'            => Status::ACTIVE,
            'is_guest'          => Ask::YES,
        ]);
        $existant->assignRole('Customer');

        $reponse = $this->connexionApple();

        $reponse->assertStatus(201)->assertJson(['phone_required' => false]);

        $existant->refresh();
        $this->assertSame('sub-client-001', $existant->apple_sub, 'L\'identité sociale doit être rattachée au compte existant.');
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['client@example.test'])->count());
    }

    /** @test */
    public function un_email_non_verifie_ne_rattache_pas_un_compte_existant(): void
    {
        // Si le fournisseur n'atteste pas l'adresse, s'en servir pour rapprocher deux
        // comptes reviendrait à donner le compte de quelqu'un à qui déclare son adresse.
        $existant = User::factory()->create([
            'branch_id' => 0, 'phone' => '0611111111',
            'email' => 'client@example.test', 'email_verified_at' => now()->timestamp,
            'status' => Status::ACTIVE, 'is_guest' => Ask::YES,
        ]);
        $existant->assignRole('Customer');

        $this->connexionApple(['email_verified' => false, 'sub' => 'sub-non-verifie'])
            ->assertStatus(201);

        $existant->refresh();
        $this->assertNull($existant->apple_sub, 'Un e-mail non attesté ne doit rattacher aucun compte.');
        $this->assertSame(2, User::whereNotNull('id')->where('is_guest', Ask::YES)->count());
    }

    // ------------------------------------------------- téléphone obligatoire (serveur)

    /** @test */
    public function commander_est_refuse_tant_que_le_telephone_manque(): void
    {
        $token = $this->connexionApple()->json('token');

        $reponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/order', []);

        $reponse->assertStatus(422)->assertJson(['code' => 'PHONE_REQUIRED']);
    }

    /** @test */
    public function commander_redevient_possible_une_fois_le_telephone_enregistre(): void
    {
        $token = $this->connexionApple()->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/social/phone', ['phone' => '0612345678', 'code' => '+33'])
            ->assertStatus(200)
            ->assertJson(['status' => true, 'phone_required' => false]);

        $user = User::where('apple_sub', 'sub-client-001')->first();
        $this->assertSame('0612345678', $user->phone);

        // Le filtre ne bloque plus : la requête va jusqu'à la validation du corps, donc
        // l'erreur n'est plus PHONE_REQUIRED. C'est ce changement de motif qui prouve
        // que le verrou s'est bien ouvert (et pas qu'il a disparu).
        $reponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/frontend/order', []);

        $this->assertNotSame('PHONE_REQUIRED', $reponse->json('code'));
    }

    /** @test */
    public function un_numero_deja_rattache_a_un_autre_compte_est_refuse(): void
    {
        User::factory()->create([
            'branch_id' => 0, 'phone' => '0612345678',
            'status' => Status::ACTIVE, 'is_guest' => Ask::YES,
        ])->assignRole('Customer');

        $token = $this->connexionApple()->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/social/phone', ['phone' => '0612345678', 'code' => '+33'])
            ->assertStatus(422)
            ->assertJson(['code' => 'PHONE_EXISTS']);

        $this->assertNull(PhoneDisplay::safe((string) User::where('apple_sub', 'sub-client-001')->first()->phone),
            'Le numéro ne doit pas avoir été capté.');
    }

    /** @test */
    public function le_meme_numero_ecrit_autrement_est_aussi_detecte(): void
    {
        // « 0612345678 » et « +33612345678 » désignent la même ligne. Une comparaison
        // littérale laisserait passer le doublon : deux comptes pour une personne, donc
        // un historique coupé en deux et des points perdus.
        User::factory()->create([
            'branch_id' => 0, 'phone' => '+33612345678',
            'status' => Status::ACTIVE, 'is_guest' => Ask::YES,
        ])->assignRole('Customer');

        $token = $this->connexionApple()->json('token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/social/phone', ['phone' => '0612345678', 'code' => '+33'])
            ->assertStatus(422)
            ->assertJson(['code' => 'PHONE_EXISTS']);
    }

    /** @test */
    public function la_borne_du_restaurant_n_est_jamais_bloquee_par_l_exigence_de_telephone(): void
    {
        // La borne place de vraies commandes sans client identifié. Lui réclamer un
        // numéro casserait la prise de commande sur place — c'est la régression que ce
        // test existe pour rendre impossible.
        //
        // `is_guest = YES` et aucun téléphone : on place délibérément le compte de la
        // borne dans le PIRE cas, celui où plus rien d'autre ne le sauve. Sans cela, le
        // test passait même en retirant la dérogation — il était vert sans rien prouver
        // (constaté en cassant volontairement le code). Le compte support d'une borne
        // dépend de l'installation : on ne peut pas parier sur son `is_guest`.
        $support = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => Status::ACTIVE,
            'is_guest'  => Ask::YES,
            'phone'     => null,
        ]);
        $support->assignRole('Customer');

        $jetonBorne = $support->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;

        $reponse = $this->withHeader('Authorization', 'Bearer ' . $jetonBorne)
            ->postJson('/api/frontend/order', []);

        $this->assertNotSame('PHONE_REQUIRED', $reponse->json('code'),
            'Une borne ne doit jamais se voir réclamer un numéro de téléphone.');
    }
}
