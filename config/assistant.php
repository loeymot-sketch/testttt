<?php

/*
|--------------------------------------------------------------------------
| [ONB-04 2026-08-27] Assistant du commerçant — extraction de carte
|--------------------------------------------------------------------------
|
| Ce fichier n'existait pas. Il porte les réglages de l'extraction de carte
| par photo, sur le MÊME motif que les deux lectures d'image déjà en service
| (facture d'achat, ticket Uber) : un contrat, un bouchon déterministe, une
| implémentation réelle, et un fournisseur de services qui choisit l'un ou
| l'autre. Le bouchon est le DÉFAUT.
|
| Règle qui gouverne tout le reste, et qui vient du propriétaire :
|
|     l'IA PROPOSE, l'humain VALIDE, le système APPLIQUE.
|
| L'IA n'écrit jamais en base et ne calcule jamais un prix. Elle produit une
| proposition ; un écran la montre ; le commerçant corrige et valide ; et
| c'est le code existant du catalogue qui écrit — avec ses règles, dont la
| taxe obligatoire posée par ONB-02.
|
| ⚠️ Aucun appel réel n'est possible tant que le propriétaire n'a pas tranché
| le gate G-IA : fournisseur, clé, et surtout PLAFOND DE DÉPENSE. Aujourd'hui
| le projet n'a aucun compteur de coût — un commerçant qui photographie une
| carte de 200 produits ne saurait pas ce que ça lui coûte.
|
*/

return [

    /*
    | Interrupteur principal. À faux, TOUT est bouchonné : aucune requête ne
    | sort de la machine. Deux verrous plutôt qu'un — celui-ci ET la présence
    | d'une clé — parce qu'un seul drapeau finit toujours par être basculé par
    | erreur.
    */
    'enabled' => filter_var(env('ASSISTANT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'menu_extraction' => [

        /*
        | Nombre maximum de produits qu'une seule lecture peut proposer.
        | Ce n'est pas une limite technique : c'est une limite de RELECTURE
        | HUMAINE. Au-delà, personne ne vérifie vraiment — on clique « tout
        | accepter », et l'intérêt de la validation disparaît.
        */
        'max_items_par_lecture' => (int) env('ASSISTANT_MENU_MAX_ITEMS', 60),

        /*
        | Formats acceptés pour la photo de carte. Reprend la garde du chemin
        | facture (le plus strict des deux existants) : `NoDangerousFileExtension`
        | s'applique côté requête, jamais le motif du chemin Uber qui l'a oubliée.
        */
        'formats' => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'],
        'taille_max_ko' => (int) env('ASSISTANT_MENU_MAX_KB', 12288),

        /*
        | Seuil de confiance en dessous duquel une ligne est marquée « à vérifier »
        | dans l'écran de validation. Elle n'est PAS écartée : elle est signalée.
        | Cacher une ligne douteuse serait pire que la montrer.
        */
        'seuil_confiance' => (float) env('ASSISTANT_MENU_CONFIANCE', 0.75),
    ],

    /*
    | Plafond de dépense. Zéro = aucun appel réel autorisé, quel que soit l'état
    | des autres réglages. C'est volontairement la valeur par défaut : le gate
    | G-IA doit être tranché avant qu'un seul euro puisse partir.
    */
    'budget' => [
        'plafond_mensuel_euros' => (float) env('ASSISTANT_BUDGET_EUR', 0.0),
    ],
];
