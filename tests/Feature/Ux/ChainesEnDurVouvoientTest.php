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

    /**
     * [ONB-11 2026-08-28] ÉCRANS COMPTOIR DE LA ROUE — exemption NOMMÉE, en attente
     * d'un arbitrage du propriétaire.
     *
     * Ces quatre gabarits tutoient l'équipe : « Tu vérifies, tu remets, tu appuies »,
     * « Colle tes liens ici », « Ton Instagram ». Dix chaînes au total, vivantes.
     *
     * Je ne les ai PAS réécrites, et c'est délibéré. Leur en-tête les décrit comme des
     * « écrans comptoir » destinés à l'équipe pendant le service — pas au commerçant
     * dans son administration. Un registre familier y est défendable ; rien n'indique
     * toutefois qu'il ait été choisi. C'est une décision de voix produit, pas une
     * décision d'implémentation.
     *
     * ⛔ N'AJOUTE JAMAIS un fichier à cette liste pour faire passer le banc. Une
     *    addition signifie « j'ai livré un écran qui tutoie » — c'est le défaut, pas
     *    la solution. Le seul ajout légitime serait décidé par le propriétaire.
     */
    private const COMPTOIR_EXEMPTE = [
        // [ONB-11 2026-08-28] ECRAN CLIENT, et non ecran commercant. « Tu gagnes a
        // 100 % » s'adresse au client devant la borne, et la ligne porte la citation
        // du PROPRIETAIRE lui-meme, datee du 2026-08-13 (borne.blade.php:608). Le
        // tutoiement y est un choix assume, pas un oubli. C'est la seule exemption de
        // cette liste qui ne soit pas en attente d'arbitrage.
        'admin/wheel/borne.blade.php',
        'admin/wheel/reglages.blade.php',
        'admin/wheel/acces.blade.php',
        'admin/wheel/lot.blade.php',
        'admin/wheel/validation.blade.php',
    ];

    /**
     * @return string[] gabarits à inspecter — Vue ET Blade d'administration
     *
     * [ONB-11 2026-08-28] Les vues BLADE ont été ajoutées après qu'un agent adverse a
     * montré que ce banc avait exactement la maladie qu'il soigne : il ne balayait que
     * `.vue`, pendant que dix tutoiements vivaient dans `resources/views/admin/`. La
     * sentinelle d'origine gardait le fichier de langue, celle-ci gardait le Vue — et
     * la porte Blade n'avait jamais été gardée.
     */
    private function composants(): array
    {
        $fichiers = [];

        foreach ([
            [resource_path('js/components'), 'vue'],
            [resource_path('views/admin'), 'php'],
        ] as [$racine, $extension]) {
            $this->assertDirectoryExists($racine);

            $iterateur = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterateur as $fichier) {
                if (! $fichier->isFile() || $fichier->getExtension() !== $extension) {
                    continue;
                }
                if ($extension === 'php' && ! str_ends_with($fichier->getFilename(), '.blade.php')) {
                    continue;
                }

                $relatif = str_replace(resource_path('views') . '/', '', $fichier->getPathname());
                if (in_array($relatif, self::COMPTOIR_EXEMPTE, true)) {
                    continue;
                }

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
        $dansBlade = false;

        foreach ($lignes as $index => $ligne) {
            $nue = trim($ligne);

            if ($dansBlade) {
                if (str_contains($nue, '--}}')) {
                    $dansBlade = false;
                }

                continue;
            }
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

            // [ONB-11 2026-08-28] Les commentaires BLADE `{{-- --}}` manquaient : quatre
            // faux positifs mesures sur `admin/wheel/borne.blade.php`, tous dans des
            // notes de conception citant le proprietaire. On les suit comme les
            // commentaires HTML, meme machine a etats.
            if (str_starts_with($nue, '{{--')) {
                if (! str_contains($nue, '--}}')) {
                    $dansBlade = true;
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
