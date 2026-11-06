<?php

/*
 * [HORAIRES 2026-11-06 · audit abusif W3-P2-1] Réglages techniques des horaires d'ouverture (les horaires eux-mêmes se
 * règlent dans l'admin : Paramètres › Horaires, App\Services\Horaires\HorairesService).
 */
return [
    // Le SERVEUR refuse une commande web IMMÉDIATE en retrait quand le restaurant ne sert pas, et toute commande immédiate
    // un jour de fermeture exceptionnelle (le site le faisait seul). Coupé dans l'environnement de test (phpunit.xml) : les
    // tests ne dépendent pas de l'heure de la machine ; tests/Feature/Horaires/HorairesTest l'active explicitement.
    'refuser_hors_service' => filter_var(env('HORAIRES_REFUSER_HORS_SERVICE', true), FILTER_VALIDATE_BOOLEAN),
];
