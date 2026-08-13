<?php
/**
 * Décodeur QR pour les bancs Playwright — PAS un décodeur pour l'application.
 *
 * [Wave A roue-account-e2e-2026-08-13] La seule preuve qu'un QR affiché avec un logo posé
 * PAR-DESSUS en CSS (borne.blade.php / validation.blade.php, cf. GOAL_ROUE_UX_IDENTITE_2026-08-13
 * §1.1) reste scannable est de le décoder depuis l'artefact RÉELLEMENT RENDU par le navigateur —
 * pas depuis le SVG généré par le contrôleur, qui ne porte pas le recouvrement visuel.
 *
 * Imagick est absent de cette machine (vérifié par exécution, voir le GOAL) : `useImagickIfAvailable`
 * est donc explicitement `false`, mode GD pur, exactement le mode déjà prouvé fonctionnel cette
 * session pour la même bibliothèque.
 *
 * Usage : php decode-qr.php /chemin/vers/capture.png
 *   → stdout : le texte décodé (rien d'autre)
 *   → exit 0 : succès · exit 1 : décodage impossible (image lue mais pas de QR dedans)
 *   → exit 2 : fichier absent · exit 3 : exception du décodeur
 */

require __DIR__ . '/../../../vendor/autoload.php';

$path = $argv[1] ?? null;
if ($path === null || $path === '' || ! file_exists($path)) {
    fwrite(STDERR, "decode-qr: fichier introuvable : " . ($path ?? '(vide)') . "\n");
    exit(2);
}

try {
    $lecteur = new \Zxing\QrReader($path, \Zxing\QrReader::SOURCE_TYPE_FILE, false);
    $texte = $lecteur->text();
    if ($texte === false || $texte === null || $texte === '') {
        fwrite(STDERR, "decode-qr: aucun QR décodable dans $path\n");
        exit(1);
    }
    echo $texte;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'decode-qr: exception ' . get_class($e) . ' — ' . $e->getMessage() . "\n");
    exit(3);
}
