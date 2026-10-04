<?php

/*
|--------------------------------------------------------------------------
| [ONB-05 2026-08-28] Reglages du cycle de vie des commandes
|--------------------------------------------------------------------------
|
| Ce fichier N'EXISTAIT PAS.
|
| `app/Jobs/CleanupStalePendingKioskOrders.php:110` appelle
| `config('order.web_stale_unpaid_ttl_minutes', 60)`, et le commentaire juste
| au-dessus annonce une variable `WEB_STALE_UNPAID_TTL_MINUTES`. Sans fichier de
| configuration, l'appel retombait SILENCIEUSEMENT sur 60 et la variable etait
| inerte : le delai etait fige, sans qu'aucune erreur ne le signale.
|
| La valeur posee ici est EXACTEMENT le repli d'alors : rien ne change de
| comportement, la molette devient simplement atteignable.
|
*/

return [

    /*
    | Duree au-dela de laquelle une commande WEB restee impayee est consideree
    | abandonnee et nettoyee. Trop court : on annule un client qui hesite au
    | paiement. Trop long : le stock reste reserve pour rien.
    */
    'web_stale_unpaid_ttl_minutes' => (int) env('WEB_STALE_UNPAID_TTL_MINUTES', 60),

];
