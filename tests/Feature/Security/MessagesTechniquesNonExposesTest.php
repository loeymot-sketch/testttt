<?php

namespace Tests\Feature\Security;

use App\Libraries\QueryExceptionLibrary;
use Tests\TestCase;

/**
 * [ONB-13 F-13 2026-08-27] Un message technique ne doit pas atteindre le commerçant.
 *
 * `QueryExceptionLibrary::message()` existe pour assainir. Elle le faisait pour les
 * erreurs de base de données — et rendait `getMessage()` TEL QUEL pour tout le reste.
 * Les services l'appelaient donc en croyant assainir, et un nom de classe, un chemin
 * de fichier ou une trace de bibliothèque tierce partait au client. L'audit a recensé
 * 502 occurrences de `getMessage()` renvoyées depuis les contrôleurs.
 *
 * Le correctif ferme les types manifestement techniques, et eux seuls. Ce test garde
 * les DEUX sens, et le second compte autant que le premier :
 *  - une erreur technique est masquée ;
 *  - un message métier passe intact.
 *
 * Masquer trop large serait un autre défaut : le projet construit exprès des messages
 * destinés à l'utilisateur, comme celui de l'import Excel qui nomme le taux de TVA
 * introuvable et dit où le créer. Les cacher rendrait l'import muet — le défaut
 * qu'on vient justement de corriger ailleurs.
 */
class MessagesTechniquesNonExposesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
    }

    /** @dataProvider erreursTechniques */
    public function test_une_erreur_technique_est_masquee(\Throwable $e, string $secret): void
    {
        if (! $e instanceof \Exception) {
            $this->markTestSkipped('La bibliothèque ne traite que les Exception.');
        }

        $message = QueryExceptionLibrary::message($e);

        $this->assertStringNotContainsString(
            $secret,
            $message,
            'Le détail technique ne doit pas atteindre le client : ' . get_class($e)
        );
    }

    public static function erreursTechniques(): array
    {
        return [
            'PDO' => [
                new \PDOException('SQLSTATE[HY000] [1045] Access denied for user root@localhost'),
                'Access denied',
            ],
            'JSON' => [
                new \JsonException('Syntax error at /var/www/app/Services/Secret.php:42'),
                '/var/www/app',
            ],
            'Réflexion' => [
                new \ReflectionException('Class App\Services\Interne\Secret does not exist'),
                'App\Services\Interne',
            ],
        ];
    }

    public function test_un_message_metier_passe_intact(): void
    {
        // Celui-ci est écrit POUR le commerçant : il nomme le taux introuvable et dit
        // où le créer. Le masquer rendrait l'import Excel muet.
        $metier = new \InvalidArgumentException(
            'Le taux de TVA « 33 » de votre fichier ne correspond à aucune taxe enregistrée. '
            . 'Créez-la d\'abord dans Réglages → Taxes.'
        );

        $this->assertSame(
            $metier->getMessage(),
            QueryExceptionLibrary::message($metier),
            "Un message construit pour l'utilisateur doit passer intact."
        );
    }

    public function test_un_message_metier_de_domaine_passe_intact(): void
    {
        $metier = new \Exception('Interrupteur inconnu : roue_de_la_fortune');

        $this->assertSame(
            $metier->getMessage(),
            QueryExceptionLibrary::message($metier)
        );
    }

    public function test_le_mode_debogage_rend_le_detail_aux_developpeurs(): void
    {
        // Le détail n'est pas perdu : il change de destinataire. En développement on
        // le veut à l'écran ; en production il part au journal.
        config(['app.debug' => true]);

        $e = new \PDOException('SQLSTATE[HY000] Access denied for user root@localhost');

        $this->assertStringContainsString('Access denied', QueryExceptionLibrary::message($e));
    }
}
