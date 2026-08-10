<?php

namespace App\Services\Wheel;

/**
 * Refus MÉTIER de la roue, avec le code HTTP qui va avec. Distinct d'une exception technique :
 * ces messages sont montrés TELS QUELS au client, ils sont donc écrits en français et disent quoi
 * faire — « tu as déjà tourné », « reviens demain ». Un refus qu'on ne comprend pas est vécu comme
 * une panne, et une panne sur un jeu détruit la confiance qu'on cherchait à construire.
 */
class WheelException extends \RuntimeException
{
    /**
     * `$step` nomme l'étape en cause quand le refus en concerne une (428).
     *
     * [P1 2026-08-10] La page devinait l'étape manquante et renvoyait TOUJOURS le client à l'avis —
     * mesuré : il manquait 5 s d'ABONNEMENT, et on le renvoyait écrire un avis déjà écrit. Deviner à
     * partir du texte du message serait pire : un jour on reformule la phrase et le routage casse en
     * silence. Le serveur sait, il le dit.
     */
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly ?string $step = null
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function step(): ?string
    {
        return $this->step;
    }
}
