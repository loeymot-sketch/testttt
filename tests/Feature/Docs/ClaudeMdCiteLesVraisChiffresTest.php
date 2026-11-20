<?php

namespace Tests\Feature\Docs;

use Tests\TestCase;

/**
 * Sentinelle — les compteurs cités dans `CLAUDE.md` doivent être ceux du code.
 *
 * LE DÉFAUT, relevé le 2026-10-01 : `CLAUDE.md` §9 annonçait
 * « RETURN_TRUE_BASELINE = 64 vérifié dans le code le 2026-08-15 » alors que la constante
 * valait **52**. Une dérive de douze.
 *
 * Pourquoi ça compte plus qu'un chiffre dans un fichier : `CLAUDE.md` est la mémoire
 * stable, relue au démarrage de CHAQUE session par CHAQUE agent (§11). Un nombre faux ici
 * ne reste pas local — il fait croire qu'il reste douze FormRequests à traiter qui l'ont
 * déjà été, et il se recopie de session en session parce qu'il porte la mention
 * « vérifié dans le code », qui inspire confiance.
 *
 * Le cliquet ne descend que vers le bas, à chaque vague de refactoring : le document sera
 * donc périmé par construction, encore et encore. D'où ce banc, qui échoue le jour où il
 * dérive au lieu d'attendre qu'un audit le remarque six semaines plus tard.
 */
class ClaudeMdCiteLesVraisChiffresTest extends TestCase
{
    /** @test */
    public function le_cliquet_authz_annonce_dans_claude_md_est_celui_du_code(): void
    {
        $sentinelle = base_path('tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php');
        $memoire = base_path('CLAUDE.md');

        $this->assertFileExists($sentinelle);
        $this->assertFileExists($memoire);

        $this->assertSame(
            1,
            preg_match(
                '/private const RETURN_TRUE_BASELINE\s*=\s*(\d+)/',
                (string) file_get_contents($sentinelle),
                $m,
            ),
            'Constante RETURN_TRUE_BASELINE introuvable : ce banc doit être mis à jour avec elle.',
        );
        $reel = (int) $m[1];

        // Ce que CLAUDE.md affirme — la forme « RETURN_TRUE_BASELINE = <n> ».
        $texte = (string) file_get_contents($memoire);
        $this->assertSame(
            1,
            preg_match('/RETURN_TRUE_BASELINE\s*=\s*(\d+)/', $texte, $d),
            'CLAUDE.md doit citer RETURN_TRUE_BASELINE une fois, avec sa valeur.',
        );
        $annonce = (int) $d[1];

        $this->assertSame(
            $reel,
            $annonce,
            "CLAUDE.md annonce RETURN_TRUE_BASELINE = {$annonce} ; le code est à {$reel}.\n"
            . "Cette mémoire est relue au démarrage de chaque session par chaque agent : un "
            . "chiffre faux ici se propage partout, et la mention « vérifié dans le code » le "
            . "rend crédible.\n"
            . "Le cliquet vient probablement d'être resserré — mettre CLAUDE.md §9 à jour "
            . 'avec la nouvelle valeur et la date de relecture.',
        );
    }
}
