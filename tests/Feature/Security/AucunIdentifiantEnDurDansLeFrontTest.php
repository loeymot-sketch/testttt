<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * [ONB-12 2026-08-27] Aucun identifiant de compte réel dans le code livré au navigateur.
 *
 * Le défaut trouvé : `LoginComponent.vue` utilisait l'adresse et le mot de passe du
 * compte administrateur seedé comme REPLI à la configuration d'exécution. Ce compte
 * existe réellement, et les chaînes partaient telles quelles dans `public/js/app.js` —
 * un fichier servi à tout visiteur.
 *
 * Que les boutons de démonstration soient masqués hors mode démo ne changeait rien :
 * on n'a pas besoin du bouton, on a besoin des identifiants, et ils étaient lisibles
 * dans un fichier public.
 *
 * Cette sentinelle balaye les SOURCES plutôt que le bundle : le bundle est ignoré par
 * git et n'existe qu'après compilation, donc un test qui le lirait passerait au vert
 * par accident sur une machine où personne n'a bâti. Les sources, elles, sont toujours là.
 */
class AucunIdentifiantEnDurDansLeFrontTest extends TestCase
{
    /**
     * Motifs interdits. On vise des identifiants de COMPTES RÉELS — pas les adresses
     * d'exemple (`@example.com`), qui ne mènent nulle part et servent de gabarit.
     */
    private const MOTIFS_INTERDITS = [
        'admin@lecayenne.fr',
        'pos@lecayenne.fr',
        'chef@lecayenne.fr',
        'kiosk123',
    ];

    private function fichiersSources(): array
    {
        $racine = resource_path('js');
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)
        );

        $fichiers = [];
        foreach ($iterateur as $f) {
            if (! $f->isFile()) {
                continue;
            }
            if (! in_array($f->getExtension(), ['vue', 'js'], true)) {
                continue;
            }
            $fichiers[] = $f->getPathname();
        }

        return $fichiers;
    }

    public function test_aucun_identifiant_de_compte_reel_dans_les_sources_du_front(): void
    {
        $coupables = [];

        foreach ($this->fichiersSources() as $chemin) {
            $contenu = file_get_contents($chemin);

            foreach (self::MOTIFS_INTERDITS as $motif) {
                if (str_contains($contenu, $motif)) {
                    $relatif = str_replace(base_path() . '/', '', $chemin);
                    $coupables[] = "{$relatif} contient « {$motif} »";
                }
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Des identifiants de comptes réels sont écrits dans le code livré au navigateur.\n"
            . "Ils finissent dans public/js/*.js, servi à tout visiteur — que le bouton qui les\n"
            . "utilise soit masqué ou non ne protège rien.\n"
            . "Les identifiants de démonstration doivent venir de la configuration d'exécution\n"
            . "injectée par le serveur, sans aucune valeur de repli.\n\n"
            . implode("\n", $coupables)
        );
    }

    public function test_la_sentinelle_mord(): void
    {
        // Un contrôle négatif qui ne mord pas ne prouve rien : on vérifie que la
        // recherche trouverait effectivement un identifiant si on en plantait un.
        $faux = "const email = 'admin@lecayenne.fr';";

        $trouve = false;
        foreach (self::MOTIFS_INTERDITS as $motif) {
            if (str_contains($faux, $motif)) {
                $trouve = true;
            }
        }

        $this->assertTrue($trouve, 'La liste de motifs doit détecter un identifiant réel.');
    }
}
