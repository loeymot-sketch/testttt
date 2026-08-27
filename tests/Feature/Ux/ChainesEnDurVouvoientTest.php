<?php

namespace Tests\Feature\Ux;

use Tests\TestCase;

/**
 * [ONB-10 2026-08-28] Le tutoiement passait par la porte que la sentinelle ne gardait pas.
 *
 * `RegistreDeLangueCoherentTest` balaye `resources/js/languages/fr.json` et échoue si
 * une chaîne tutoie le commerçant. Il était vert. Et pourtant, en ouvrant l'écran
 * « Scan de facture », on lisait en toutes lettres :
 *
 *     « Photographie une facture fournisseur — l'IA propose les entrées en stock,
 *       tu valides d'un tap. »
 *
 * La chaîne n'était pas dans le fichier de langue : elle était écrite EN DUR dans le
 * gabarit du composant. Une sentinelle qui ne garde qu'un fichier ne prouve rien sur
 * les autres — et un test vert sur le mauvais périmètre est plus dangereux qu'un test
 * absent, parce qu'il rassure.
 *
 * Ce banc balaye donc les gabarits `.vue`. Trois autres cas y ont été trouvés du même
 * coup, dont un sur le composeur de produit — l'écran où le restaurateur construit ses
 * parcours de personnalisation : « le parcours que TON client suit ».
 *
 * MÉTHODE ET SES LIMITES, dites franchement :
 *
 *   · on ne retient que les lignes qui RESSEMBLENT à une phrase affichée : un accent
 *     français, ou au moins deux mots-outils français. Le seul critère de l'accent
 *     ne suffisait pas — mon propre contrôle négatif l'a montré en plantant une
 *     phrase qui n'en porte aucun (« Photographie une facture — tu valides d'un
 *     tap. ») et que le banc laissait alors passer ;
 *   · on ignore les COMMENTAIRES. Ce n'est pas une commodité : deux faux positifs
 *     mesurés venaient de commentaires employant « ton » au sens de NUANCE
 *     (« Ton cyan distinct du vert », « n'alarme pas le ton système »). Un
 *     commentaire n'est pas lu par le commerçant ;
 *   · on ne cherche que les pronoms et possessifs. Les verbes conjugués à la 2e
 *     personne du singulier ne sont PAS détectables par motif sans faux positifs
 *     massifs (« il ajoute », « elle pose »). Ceux qui ont été rencontrés sont
 *     listés nommément dans `RegistreDeLangueCoherentTest` ; cette liste grandit
 *     quand un cas est trouvé, ce qui est exactement ainsi que « choisis un
 *     template » l'a été.
 *
 * Ce banc ne prétend donc pas être exhaustif. Il ferme la porte par laquelle un cas
 * réel est effectivement passé.
 */
class ChainesEnDurVouvoientTest extends TestCase
{
    /** Motif : pronoms et possessifs de la 2e personne du singulier, bornés. */
    private const MOTIF_TUTOIEMENT = '/\b(tu|tes|ton|ta)\b/iu';

    /** Un accent français : signal fort qu'on lit une phrase, pas du code. */
    private const ACCENT_FRANCAIS = '/[àâäéèêëîïôöùûüçœÀÂÉÈÊÎÔÙÛÇ]/u';

    /**
     * Mots-outils français. L'accent seul ne suffit pas : mon propre contrôle négatif
     * a montré qu'une phrase française peut n'en porter aucun (« Photographie une
     * facture — tu valides d'un tap. »). On accepte donc aussi une ligne portant au
     * moins DEUX de ces mots — un seul serait trop courant en code.
     */
    private const MOTS_OUTILS = '/\b(le|la|les|un|une|des|du|pour|avec|sur|dans|votre|vous|que|qui|cette|pas|plus|sans)\b/iu';

    /** La ligne ressemble-t-elle à une phrase affichée au commerçant ? */
    private function ressembleAUnePhrase(string $texte): bool
    {
        if (preg_match(self::ACCENT_FRANCAIS, $texte)) {
            return true;
        }

        return preg_match_all(self::MOTS_OUTILS, $texte) >= 2;
    }

    /** @return string[] chemins relatifs des gabarits à inspecter */
    private function composants(): array
    {
        $racine = resource_path('js/components');
        $this->assertDirectoryExists($racine);

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)
        );

        $fichiers = [];
        foreach ($iterateur as $fichier) {
            if ($fichier->isFile() && $fichier->getExtension() === 'vue') {
                $fichiers[] = $fichier->getPathname();
            }
        }

        sort($fichiers);

        return $fichiers;
    }

    /**
     * Retire les commentaires HTML et bloc, en suivant leur état sur plusieurs lignes.
     * Un commentaire n'est jamais lu par le commerçant.
     *
     * @param string[] $lignes
     * @return array<int, string> numéro de ligne (1-indexé) => contenu hors commentaire
     */
    private function lignesVisibles(array $lignes): array
    {
        $visibles = [];
        $dansHtml = false;
        $dansBloc = false;

        foreach ($lignes as $index => $ligne) {
            $nue = trim($ligne);

            if ($dansHtml) {
                if (str_contains($nue, '-->')) {
                    $dansHtml = false;
                }

                continue;
            }
            if ($dansBloc) {
                if (str_contains($nue, '*/')) {
                    $dansBloc = false;
                }

                continue;
            }

            if (str_starts_with($nue, '<!--')) {
                if (! str_contains($nue, '-->')) {
                    $dansHtml = true;
                }

                continue;
            }
            if (str_starts_with($nue, '/*') || str_starts_with($nue, '*')) {
                if (str_starts_with($nue, '/*') && ! str_contains($nue, '*/')) {
                    $dansBloc = true;
                }

                continue;
            }
            if (str_starts_with($nue, '//')) {
                continue;
            }

            if ($nue !== '') {
                $visibles[$index + 1] = $nue;
            }
        }

        return $visibles;
    }

    public function test_aucun_gabarit_ne_tutoie_le_commercant(): void
    {
        $coupables = [];

        foreach ($this->composants() as $chemin) {
            $lignes = explode("\n", (string) file_get_contents($chemin));
            $relatif = str_replace(resource_path('js/components') . '/', '', $chemin);

            $visibles = $this->lignesVisibles($lignes);
            $numeros = array_keys($visibles);

            foreach ($numeros as $position => $numero) {
                $texte = $visibles[$numero];

                if (! preg_match(self::MOTIF_TUTOIEMENT, $texte)) {
                    continue;
                }

                // [ONB-10 2026-08-28] La phrase-likeness s'évalue sur la ligne ET ses
                // voisines. Une phrase de gabarit est souvent coupée en deux par le
                // formatage : « … l'IA propose les entrées en stock, » puis « tu valides
                // d'un tap. ». La seconde moitié seule ne ressemble plus à du français,
                // et le banc la laissait passer — vérifié en restaurant le défaut,
                // qui n'a d'abord PAS été attrapé.
                $contexte = trim(implode(' ', array_filter([
                    $visibles[$numeros[$position - 1] ?? -1] ?? '',
                    $texte,
                    $visibles[$numeros[$position + 1] ?? -1] ?? '',
                ])));

                if (! $this->ressembleAUnePhrase($contexte)) {
                    continue;
                }

                $coupables[] = "{$relatif}:{$numero}\n      " . mb_substr($texte, 0, 120);
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Des chaînes écrites EN DUR dans les gabarits tutoient le commerçant, alors\n"
            . "que l'interface le vouvoie partout ailleurs. Le fichier de langue est déjà\n"
            . "gardé par RegistreDeLangueCoherentTest ; ces chaînes-là lui échappent parce\n"
            . "qu'elles ne passent pas par lui.\n\n"
            . "Si la ligne signalée est un COMMENTAIRE, c'est ce banc qu'il faut corriger :\n"
            . "il suit déjà les commentaires HTML et bloc, mais pas toutes les formes.\n\n"
            . implode("\n", $coupables)
        );
    }

    /**
     * Contrôle négatif, dans les deux sens : le banc doit attraper un tutoiement
     * planté dans un gabarit, et NE PAS attraper « ton » au sens de nuance dans un
     * commentaire — les deux faux positifs réellement mesurés.
     */
    public function test_le_banc_mord_et_ne_mord_pas_a_tort(): void
    {
        $gabarit = [
            '<template>',
            '    <!-- Ton cyan distinct du vert « prêts » -->',
            '    /* Ton ambre pour l\'encaissement */',
            '    <p>Photographie une facture — tu valides d\'un tap.</p>',
            '</template>',
        ];

        $visibles = $this->lignesVisibles($gabarit);

        $attrapes = [];
        foreach ($visibles as $numero => $texte) {
            if (preg_match(self::MOTIF_TUTOIEMENT, $texte)
                && $this->ressembleAUnePhrase($texte)) {
                $attrapes[] = $numero;
            }
        }

        $this->assertSame(
            [4],
            $attrapes,
            "Le banc doit signaler la ligne 4 (le tutoiement affiché) et SEULEMENT elle.\n"
            . 'Les lignes 2 et 3 emploient « ton » au sens de nuance, dans des commentaires.'
        );
    }
}
