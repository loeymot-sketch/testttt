<?php


return [
    'title' => 'Installateur Le Cayenne',
    'next'  => 'Étape suivante',
    'welcome' => [
        'templateTitle' => 'Bienvenue',
        'title'         => 'Installateur Le Cayenne',
        'message'       => 'Assistant d’installation et de configuration.',
        'next'          => 'Vérifier les prérequis',
    ],
    'requirement' => [
        'templateTitle' => 'Étape 1 | Prérequis serveur',
        'title'         => 'Prérequis serveur',
        'next'          => 'Vérifier les permissions',
        'version'       => 'version',
        'required'      => 'requis'
    ],
    'permission' => [
        'templateTitle'       => 'Étape 2 | Permissions',
        'title'               => 'Permissions',
        'next'                => 'Configurer la licence',
        'permission_checking' => 'Vérification des permissions'
    ],
    'license' => [
        'templateTitle'       => 'Étape 3 | Licence',
        'title'               => 'Configuration de la licence',
        'next'                => 'Configurer le site',
        'active_process'      => 'Procédure d’activation',
        'step_1'              => 'Étape 1 :',
        'step_2'              => 'Étape 2 :',
        'step_3'              => 'Étape 3 :',
        'go_to_inilabs'       => 'Aller sur iNilabs',
        'login_to_inilabs'    => 'Se connecter à iNilabs',
        'active_license_code' => 'Activer le code de licence',
        'activation_help'     => 'Vous pouvez récupérer le code d’activation et l’utiliser pour installer le produit.',
        'label'               => [
            'license_key' => 'Clé de licence',
            'license_code' => 'Code de licence'
        ]
    ],
    'site'     => [
        'templateTitle' => 'Étape 4 | Configuration du site',
        'title'         => 'Configuration du site',
        'next'          => 'Configurer la base de données',
        'label'         => [
            'app_name' => 'Nom de l’application',
            'app_url'  => 'URL de l’application',
        ]
    ],
    'database' => [
        'templateTitle' => 'Étape 5 | Base de données',
        'title'         => 'Configuration de la base de données',
        'next'          => 'Configuration finale',
        'fail_message'  => 'Impossible de se connecter à la base de données.',
        'label'         => [
            'database_connection' => 'Connexion base de données',
            'database_host'       => 'Hôte base de données',
            'database_port'       => 'Port base de données',
            'database_name'       => 'Nom de la base',
            'database_username'   => 'Utilisateur base de données',
            'database_password'   => 'Mot de passe base de données',
        ]
    ],
    'final'    => [
        'templateTitle'   => 'Étape 6 | Finalisation',
        'title'           => 'Configuration finale',
        'success_message' => 'L’application a été installée avec succès.',
        'login_info'      => 'Informations de connexion',
        'email'           => 'Email',
        'password'        => 'Mot de passe',
        'email_info'      => 'admin@example.com',
        'password_info'   => '123456',
        'next'            => 'Terminer',
    ],
    'installed' => [
        'success_log_message' => 'Installateur Le Cayenne installé avec succès le ',
        'update_log_message'  => 'Installateur Le Cayenne mis à jour avec succès le ',
    ],
];
