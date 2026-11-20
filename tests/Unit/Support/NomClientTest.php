<?php

namespace Tests\Unit\Support;

use App\Support\NomClient;
use PHPUnit\Framework\TestCase;

/**
 * [REVUE ADVERSE 2026-10-19] Une seule définition du nettoyage d'un nom client (borne ET caisse).
 */
class NomClientTest extends TestCase
{
    public function test_un_nom_normal_est_garde_tel_quel(): void
    {
        $this->assertSame('Karim', NomClient::nettoyer('Karim'));
        $this->assertSame('Éloïse Martin-Dupont', NomClient::nettoyer('Éloïse Martin-Dupont'));
        $this->assertSame("N'Diaye", NomClient::nettoyer("N'Diaye"));
    }

    public function test_espaces_replies_tabulations_et_sauts_de_ligne_neutralises(): void
    {
        $this->assertSame('Ka rim Ben', NomClient::nettoyer("  Ka\trim \n  Ben  "));
        $this->assertStringNotContainsString("\x1b", (string) NomClient::nettoyer("Ka\x1brim"));
    }

    public function test_les_noms_invisibles_valent_aucun_nom(): void
    {
        foreach (["\u{2800}\u{2800}", "\u{3164}\u{3164}", "\u{200B}\u{200B}", "  ", "\x07\x1b", "", "\u{115F}\u{1160}"] as $invisible) {
            $this->assertNull(NomClient::nettoyer($invisible), 'doit être refusé : '.bin2hex($invisible));
        }
    }

    public function test_un_nom_sans_aucune_lettre_n_est_pas_un_nom(): void
    {
        foreach (['12', '--', '..', '1 2 3', '😀😀', '_-_'] as $faux) {
            $this->assertNull(NomClient::nettoyer($faux), $faux);
        }
        $this->assertSame('Karim 2', NomClient::nettoyer('Karim 2'), 'un chiffre APRÈS des lettres reste légitime');
    }

    public function test_les_caracteres_de_format_sont_retires_pas_remplaces_par_une_espace(): void
    {
        // joiner U+200D (famille émoji) et ZWNJ U+200C (persan) : le mot reste entier
        $this->assertSame('میخواهم', NomClient::nettoyer("می\u{200C}خواهم"));
        $this->assertSame('AKarim', NomClient::nettoyer("A\u{202E}Karim"), 'bidi RLO retiré sans insérer d\'espace');
    }

    public function test_la_longueur_est_bornee_a_60(): void
    {
        $this->assertSame(60, mb_strlen((string) NomClient::nettoyer(str_repeat('a', 200))));
    }

    public function test_les_noms_de_substitution_sont_generiques(): void
    {
        foreach (['Guest User', 'guest user', ' Walking Customer ', 'Client'] as $g) {
            $this->assertTrue(NomClient::estGenerique($g), $g);
        }
        $this->assertFalse(NomClient::estGenerique('Sofia Martin'));
    }
}
