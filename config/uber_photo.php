<?php

return [

    /*
    |---------------------------------------------------------------------------
    | [UBER-PHOTO 2026-08-12 · owner] Commandes Uber prises EN PHOTO sur la tablette
    |---------------------------------------------------------------------------
    |
    | Tant qu'Uber n'accorde pas l'accès production, le restaurant recopie les commandes à la
    | main. Le propriétaire photographie donc le ticket et le système le lit. Ce canal est
    | INDÉPENDANT du webhook : il fonctionne aujourd'hui, sans Uber.
    |
    | POURQUOI UN FICHIER À PART, ET PAS `config/uber.php`
    | ---------------------------------------------------
    | Parce que la production fait tourner sa PROPRE version non committée de `config/uber.php`
    | (travail « menu push » d'une autre session). Y ajouter trois clés aurait rendu chaque
    | déploiement conflictuel : `git pull --ff-only` s'y serait refusé, et « résoudre » à la main
    | un fichier que le serveur est seul à connaître, c'est exactement la manière de perdre le
    | travail de quelqu'un d'autre. Un fichier neuf n'entre en collision avec rien.
    |
    | Ces clés vivent dans un fichier de configuration RÉEL, jamais en `config('x.y', défaut)`
    | sans fichier : sans quoi la variable d'environnement ne serait jamais lue et l'interrupteur
    | resterait décoratif.
    |
    */

    // `true` + clé OpenAI renseignée → lecture RÉELLE du ticket par un modèle de vision.
    // Sinon → doublure locale : le parcours complet fonctionne, mais la lecture rend le ticket
    // d'exemple ; le personnel corrige alors les lignes à l'écran avant d'envoyer.
    'vision_enabled' => (bool) env('UBER_VISION_ENABLED', false),

    // Nombre maximal de photos pour UN même ticket (un long ticket se photographie en 2 ou 3
    // fois ; toutes les photos forment une seule commande).
    'max_files' => (int) env('UBER_PHOTO_MAX_FILES', 6),

    // Taille maximale par photo, en kilo-octets (12 Mo — une photo de tablette pèse 2 à 5 Mo).
    'max_kb' => (int) env('UBER_PHOTO_MAX_KB', 12288),

];
