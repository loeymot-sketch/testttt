<?php

namespace Tests\Feature\Onboarding;

use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [ONB-09 2026-08-28] « Email envoyé avec succès » sur une liste vide.
 *
 * `SubscriberService::sendEmail()` sortait sans rien faire quand aucun abonné
 * n'existait, et `SubscriberController::sendEmail()` répondait `status: true` +
 * « Email envoyé avec succès » SANS CONDITION.
 *
 * Mesuré sur la base de travail : **0 abonné**. Donc 100 % des envois depuis cet écran
 * étaient des faux succès. Le commerçant rédige son message, l'envoie, voit une
 * confirmation verte — et personne ne l'a reçu. Il n'a aucun moyen de s'en apercevoir.
 *
 * C'est la même maladie que le bouton de notification push du tableau de bord, relevée
 * par le même audit : une promesse d'envoi affichée sans qu'aucun envoi n'ait lieu. Un
 * faux succès est pire qu'une erreur — il ferme la question.
 *
 * Zéro destinataire n'est PAS une erreur : c'est une information. Le service rend
 * désormais le nombre, et le contrôleur le dit.
 */
class EnvoiAbonnesDitLaVeriteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // Note de méthode : forcer la locale ici ne sert à rien — l'intergiciel
        // `localization` la réimpose au moment de la requête HTTP. Les assertions de ce
        // banc sont donc écrites pour être INSENSIBLES À LA LANGUE. C'est aussi ce qui
        // a mis au jour un défaut voisin : les clés `all.message.*` n'existaient qu'en
        // FRANÇAIS, si bien qu'un utilisateur anglophone recevait la clé technique
        // brute (« all.message.email_send »). Elles sont désormais dans les deux
        // langues, la clé historique comprise.
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Permission::findOrCreate('subscribers', 'sanctum');
        $admin->givePermissionTo('subscribers');

        return $admin;
    }

    private function envoyer(User $admin)
    {
        return $this->actingAs($admin, 'sanctum')->postJson('/api/admin/subscriber/send-email', [
            'subject' => 'Nouvelle carte',
            'message' => 'Venez découvrir nos nouveautés.',
        ]);
    }

    public function test_sans_aucun_abonne_le_message_le_dit(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $reponse = $this->envoyer($admin)->assertOk();

        $this->assertSame(
            0,
            $reponse->json('count'),
            'Le compte de destinataires doit être rendu à l\'appelant.'
        );
        // Assertion insensible a la langue : l'intergiciel de localisation impose la
        // langue de la requete, et le message existe desormais dans les deux.
        $message = (string) $reponse->json('message');

        $this->assertTrue(
            str_contains($message, 'Aucun abonné') || str_contains($message, 'No subscribers'),
            "Sans abonné, l'écran doit le DIRE. « Email envoyé avec succès » ferme la\n"
            . "question et le commerçant n'a aucun moyen de s'apercevoir que personne\n"
            . "n'a rien reçu. Message obtenu : {$message}"
        );

        $this->assertStringNotContainsString(
            'succès',
            $message,
            "Le message de succès générique ne doit plus apparaître quand rien n'est parti."
        );

        Mail::assertNothingSent();
    }

    public function test_avec_des_abonnes_le_compte_est_annonce(): void
    {
        Mail::fake();
        $admin = $this->admin();

        Subscriber::query()->create(['email' => 'client1@exemple.test']);
        Subscriber::query()->create(['email' => 'client2@exemple.test']);
        Subscriber::query()->create(['email' => 'client3@exemple.test']);

        $reponse = $this->envoyer($admin)->assertOk();

        $this->assertSame(3, $reponse->json('count'));
        $this->assertStringContainsString(
            '3',
            (string) $reponse->json('message'),
            'Le commerçant doit lire à combien de personnes son message est parti.'
        );

        Mail::assertSent(\App\Mail\SubscriberMail::class);
    }

    /**
     * Contrôle négatif : rendre le compte ne doit pas transformer « zéro abonné » en
     * erreur. Le commerçant n'a rien fait de mal ; sa liste est vide, voilà tout.
     */
    public function test_zero_abonne_reste_un_succes_pas_une_erreur(): void
    {
        Mail::fake();

        $this->envoyer($this->admin())
            ->assertOk()
            ->assertJsonPath('status', true);
    }
}
