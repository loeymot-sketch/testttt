<?php

namespace App\Libraries;

use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class QueryExceptionLibrary
{
    /**
     * Types d'exception dont le message est TECHNIQUE par nature et ne doit jamais
     * atteindre un commerçant : ils portent des chemins de fichiers, des noms de
     * classes, des fragments de requête, parfois des identifiants de connexion.
     *
     * [ONB-13 F-13 2026-08-27] Cette liste est le cœur du correctif. Elle est
     * volontairement fermée plutôt qu'ouverte : on assainit ce qu'on sait technique,
     * et on laisse passer le reste. L'inverse — tout assainir sauf une liste
     * d'exceptions métier — aurait masqué des messages utiles que le projet
     * construit exprès pour l'utilisateur, comme « Le taux de TVA « 33 » de votre
     * fichier ne correspond à aucune taxe enregistrée ».
     */
    private const TECHNIQUES = [
        \PDOException::class,
        \ErrorException::class,
        \TypeError::class,
        \ValueError::class,
        \ArgumentCountError::class,
        \DivisionByZeroError::class,
        \JsonException::class,
        \ReflectionException::class,
    ];

    /**
     * @param Exception $e
     * @return string
     */
    public static function message(Exception $e): string
    {
        if ($e instanceof QueryException && isset($e->errorInfo[1])) {
            if ($e->errorInfo[1] === 1451) {
                return trans('all.message.resource_already_used');
            }

            return config('app.debug') ? $e->getMessage() : trans('all.message.database_error_message');
        }

        // [ONB-13 F-13 2026-08-27] Avant, cette branche rendait `$e->getMessage()` TEL
        // QUEL, quelle que soit l'exception.
        //
        // Conséquence : les services appelaient cette bibliothèque en CROYANT assainir,
        // et tout ce qui n'était pas une erreur de base de données partait au client —
        // nom de classe, chemin de fichier, trace d'une bibliothèque tierce. L'audit a
        // recensé 502 occurrences de `getMessage()` renvoyées au client depuis les
        // contrôleurs, dont 86 fichiers sous `Admin/`.
        //
        // On ferme les types manifestement techniques, et eux seuls. Le détail part au
        // journal — il n'est pas perdu, il change simplement de destinataire.
        foreach (self::TECHNIQUES as $type) {
            if ($e instanceof $type) {
                Log::error('[erreur technique masquée au client] ' . get_class($e) . ' : ' . $e->getMessage(), [
                    'fichier' => $e->getFile(),
                    'ligne'   => $e->getLine(),
                ]);

                return config('app.debug')
                    ? $e->getMessage()
                    : trans('all.message.database_error_message');
            }
        }

        // Tout le reste : message métier, construit pour être lu par un humain.
        return $e->getMessage();
    }
}
