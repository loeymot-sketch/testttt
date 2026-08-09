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
    public function __construct(string $message, private readonly int $status = 400)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
