<?php

namespace Tests\Feature\Onboarding;

use App\Services\FirebaseService;
use App\Services\PushNotificationService;
use Tests\TestCase;

/**
 * [ONB-09 2026-08-28] Une notification ENREGISTRÉE n'est pas une notification REÇUE.
 *
 * LE MÊME DÉFAUT QUE L'ENVOI AUX ABONNÉS, sur un autre écran — et je ne l'avais pas
 * vu. Mon correctif du 2026-08-28 (`f6a2049ad`, « Email envoyé avec succès sur une
 * liste d'abonnés VIDE ») s'arrêtait à son propre contrôleur. Un agent adverse a
 * signalé que le jumeau n'avait pas été traité.
 *
 * TROIS SILENCES SUPERPOSÉS, du plus profond au plus visible :
 *
 * 1. `FirebaseService::sendNotification()` était déclarée `void` : l'appelant ne
 *    pouvait RIEN savoir, par construction.
 * 2. Chaque envoi par appareil était enveloppé dans `catch (\Throwable $th) { }` —
 *    un bloc VIDE. Jeton expiré, réseau coupé, clé FCM révoquée : rien, pas même
 *    une ligne de journal.
 * 3. L'écran affichait `alertService.successFlip(...)` sans condition.
 *
 * Résultat : le commerçant rédigeait sa promotion, cliquait « Envoyer », lisait un
 * succès en vert — et elle pouvait n'avoir atteint aucun appareil. Il n'avait aucun
 * moyen de le savoir, ni sur le moment, ni après.
 *
 * Ce banc exerce le maillon que personne ne gardait : la remontée du compte rendu.
 * Il ne fait aucun appel réseau — Firebase est remplacé par un double.
 */
class NotificationEnregistreeNEstPasRecueTest extends TestCase
{
    public function test_le_service_firebase_rend_un_compte_rendu(): void
    {
        // Le type de retour `void` rendait le mensonge INEVITABLE : aucun appelant
        // ne pouvait distinguer un envoi reussi d'un echec total.
        $methode = new \ReflectionMethod(FirebaseService::class, 'sendNotification');
        $type = $methode->getReturnType();

        $this->assertNotNull($type, 'sendNotification() ne declare aucun type de retour.');

        $this->assertSame(
            'array',
            $type->getName(),
            "sendNotification() doit rendre un compte rendu. Declaree `void`, elle\n"
            . "empeche par construction tout appelant de savoir ce qui s'est passe."
        );
    }

    public function test_le_bloc_catch_n_est_plus_vide(): void
    {
        // Un `catch` vide est la forme la plus pure du silence : l'echec est
        // explicitement attrape, puis explicitement ignore.
        $source = file_get_contents(app_path('Services/FirebaseService.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/catch\s*\(\s*\\\\?Throwable\s+\$th\s*\)\s*\{\s*\}/',
            $source,
            "Le `catch (\\Throwable \$th) { }` vide est revenu : chaque echec d'envoi\n"
            . 'disparaitrait a nouveau sans laisser de trace.'
        );

        $this->assertStringContainsString(
            '$echecs++',
            $source,
            'Les echecs doivent etre COMPTES pour pouvoir etre dits.'
        );
    }

    public function test_le_service_expose_le_rapport_du_dernier_envoi(): void
    {
        // Porte par le SERVICE et non par le modele : l'attacher au modele en ferait
        // un attribut Eloquent, qu'un `save()` ulterieur tenterait d'ecrire dans une
        // colonne inexistante.
        $this->assertTrue(
            property_exists(PushNotificationService::class, 'rapportDeDernierEnvoi'),
            'Le service doit exposer le compte rendu au controleur.'
        );

        $reflexion = new \ReflectionProperty(PushNotificationService::class, 'rapportDeDernierEnvoi');
        $this->assertTrue($reflexion->isPublic());
    }

    /** @dataProvider casDeFigure */
    public function test_le_message_dit_ce_qui_s_est_reellement_passe(
        ?array $rapport,
        string $attendu,
        string $pourquoi
    ): void {
        $controleur = app(\App\Http\Controllers\Admin\PushNotificationController::class);

        $methode = new \ReflectionMethod($controleur, 'messageDEnvoi');
        $methode->setAccessible(true);

        app()->setLocale('fr');
        $message = $methode->invoke($controleur, $rapport);

        $this->assertStringContainsString($attendu, $message, $pourquoi . "\nReçu : " . $message);
    }

    /** @return array<string, array{0: array<string,mixed>|null, 1: string, 2: string}> */
    public function casDeFigure(): array
    {
        return [
            'aucun appareil vise' => [
                ['destinataires' => 0, 'envoyes' => 0, 'echecs' => 0, 'erreur' => null],
                'AUCUN appareil',
                "Zero destinataire n'est PAS un succes : personne n'a installe "
                . "l'application. Le dire en vert serait le pire des mensonges.",
            ],
            'tous les envois ont echoue' => [
                ['destinataires' => 12, 'envoyes' => 0, 'echecs' => 12, 'erreur' => null],
                'AUCUN des 12',
                'Douze echecs sur douze doivent etre dits, avec la piste a verifier.',
            ],
            'envoi partiel' => [
                ['destinataires' => 12, 'envoyes' => 9, 'echecs' => 3, 'erreur' => null],
                '9',
                "Un envoi partiel est un succes NUANCE : le commercant doit savoir "
                . 'que trois appareils sont restes muets.',
            ],
            'envoi complet' => [
                ['destinataires' => 12, 'envoyes' => 12, 'echecs' => 0, 'erreur' => null],
                '12',
                'Un envoi complet doit annoncer le nombre reellement atteint.',
            ],
            'aucun rapport disponible' => [
                null,
                'enregistr',
                "Sans rapport, on n'affirme que ce qu'on sait : la notification est "
                . 'enregistree. On ne dit PAS qu'
                . "elle est partie.",
            ],
        ];
    }

    public function test_les_cinq_messages_existent_en_francais_ET_en_anglais(): void
    {
        foreach (['fr', 'en'] as $langue) {
            foreach (['push_saved', 'push_no_device', 'push_all_failed', 'push_partial', 'push_sent'] as $cle) {
                $traduit = trans('all.message.' . $cle, [], $langue);

                $this->assertNotSame(
                    'all.message.' . $cle,
                    $traduit,
                    "{$langue} : la cle all.message.{$cle} manque — le commercant lirait "
                    . 'la cle brute.'
                );
            }
        }
    }
}
