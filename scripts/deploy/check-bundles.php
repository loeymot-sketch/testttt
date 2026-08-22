<?php

/**
 * [SUPERVISION 2026-08-22] LE BUILD A-T-IL VRAIMENT TOUT PRODUIT ?
 *
 * Usage : php scripts/deploy/check-bundles.php <racine-du-depot>
 * Sortie : 0 = tous les bundles annoncés existent et sont non vides ; 1 = il en manque.
 *
 * POURQUOI CE CONTRÔLE EXISTE
 * `deploy.sh` conclut au succès sur un `/api/health` à 200. Or la panne la plus coûteuse
 * rencontrée sur ce projet répond 200 partout : un bundle manquant ou périmé, la SPA en écran
 * blanc, aucune erreur nulle part — webpack attend un morceau qui n'a jamais été enregistré.
 * Un health-check vert ne dit rien de ce qui est SERVI.
 *
 * POURQUOI MAINTENANT
 * Tant que `vendor.js` et consorts étaient versionnés, un build à moitié fait laissait derrière
 * lui un fichier PÉRIMÉ — silencieux, et faux. Détrackés, il ne laisse plus rien : un 404 franc.
 * Encore faut-il que quelqu'un le regarde. C'est ce que fait ce script, AVANT les migrations.
 *
 * POURQUOI UN FICHIER ET PAS UN `php -r '…'` DANS LE SCRIPT
 * La première version était embarquée en ligne dans `deploy.sh`. `bash -n` la déclarait
 * syntaxiquement valide alors que le PHP embarqué était corrompu : la porte aurait échoué à
 * CHAQUE déploiement, et le seul moyen de s'en apercevoir était de l'EXÉCUTER. Un fichier
 * séparé est lisible, testable (`php -l`), et lançable à la main.
 */

$racine = $argv[1] ?? null;

if ($racine === null || ! is_dir($racine)) {
    fwrite(STDERR, "  usage: php scripts/deploy/check-bundles.php <racine-du-depot>\n");
    exit(2);
}

$manifest = rtrim($racine, '/') . '/public/mix-manifest.json';

if (! is_file($manifest)) {
    fwrite(STDERR, "  mix-manifest.json absent — le build n'a rien produit.\n");
    exit(1);
}

$entrees = json_decode((string) file_get_contents($manifest), true);

if (! is_array($entrees) || $entrees === []) {
    fwrite(STDERR, "  mix-manifest.json illisible ou vide.\n");
    exit(1);
}

$manquants = [];

foreach ($entrees as $logique => $versionne) {
    // Le manifeste versionne par requête (`/js/app.js?id=…`) : le fichier sur disque, lui,
    // porte le nom SANS la chaîne de requête.
    $chemin = rtrim($racine, '/') . '/public' . strtok((string) $versionne, '?');

    if (! is_file($chemin) || filesize($chemin) === 0) {
        $manquants[] = $logique;
    }
}

if ($manquants !== []) {
    fwrite(STDERR, '  MANQUANTS OU VIDES : ' . implode(', ', $manquants) . "\n");
    exit(1);
}

echo '  OK — ' . count($entrees) . " bundles présents et non vides.\n";
exit(0);
