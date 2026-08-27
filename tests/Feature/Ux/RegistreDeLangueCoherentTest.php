<?php

namespace Tests\Feature\Ux;

use Tests\TestCase;

/**
 * [ONB-11 2026-08-27] Le logiciel s'adresse au commerçant d'une seule façon.
 *
 * Mesure avant correctif : **4 chaînes tutoyaient, 108 vouvoyaient**. Ce n'était pas
 * un choix de ton, c'était quatre oublis — dont le bandeau d'avertissement de la page
 * Conso & Stock, vu à l'écran : « pose les prix ou scanne tes factures pour valoriser
 * ton stock », au milieu d'une interface qui vouvoie partout ailleurs.
 *
 * Le défaut est mineur pris isolément. Il cesse de l'être quand on considère à qui
 * on parle : un restaurateur qui vient d'acheter un logiciel de caisse, et qui sent
 * que le produit ne sait pas s'il s'adresse à un client ou à un collègue.
 *
 * Cette sentinelle balaye le fichier de langue français et échoue si le tutoiement
 * revient. Elle n'impose pas le vouvoiement dans l'absolu — elle impose la
 * COHÉRENCE : si un jour le produit choisit de tutoyer, c'est la liste des 108 qu'il
 * faudra changer, et ce test le rappellera.
 */
class RegistreDeLangueCoherentTest extends TestCase
{
    private function chainesFrancaises(): array
    {
        $chemin = resource_path('js/languages/fr.json');
        $this->assertFileExists($chemin);

        $json = json_decode(file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);

        $plates = [];
        $aplatir = function ($noeud, string $chemin) use (&$aplatir, &$plates): void {
            if (is_array($noeud)) {
                foreach ($noeud as $cle => $valeur) {
                    $aplatir($valeur, $chemin === '' ? (string) $cle : "{$chemin}.{$cle}");
                }

                return;
            }
            if (is_string($noeud)) {
                $plates[$chemin] = $noeud;
            }
        };
        $aplatir($json, '');

        return $plates;
    }

    public function test_aucune_chaine_ne_tutoie_le_commercant(): void
    {
        $coupables = [];

        foreach ($this->chainesFrancaises() as $cle => $valeur) {
            // On vise les possessifs et pronoms de la 2e personne du singulier.
            // `ta` et `ton` sont volontairement bornés par \b pour ne pas attraper
            // « tableau », « tonalité » ou un mot qui les contient.
            if (preg_match('/\b(tes|ton|ta|tu)\b/iu', $valeur)) {
                $coupables[] = "{$cle} = " . mb_substr($valeur, 0, 80);
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Des chaînes tutoient le commerçant alors que l'interface le vouvoie partout\n"
            . "ailleurs (108 chaînes mesurées). Un produit qui hésite entre deux registres\n"
            . "donne l'impression de ne pas savoir à qui il parle.\n\n"
            . implode("\n", $coupables)
        );
    }

    /**
     * [ONB-11 2026-08-27] Le produit appelle sa borne « borne », pas « Kiosk ».
     *
     * Mesure avant correctif : **37 chaînes disaient « Borne », 4 disaient « Kiosk »**.
     * Même motif que le tutoiement : ce n'était pas un choix de vocabulaire, c'étaient
     * quatre oublis. L'une d'elles cumulait trois défauts — « Kiosk », un accent
     * manquant sur « immediatement », et « scope » au milieu d'une phrase française.
     *
     * Ce test ne bannit pas l'anglais du produit : « wizard » y reste, parce que c'est
     * le mot que le propriétaire emploie lui-même. Il garde la COHÉRENCE d'un terme
     * que le produit a déjà tranché en français, 37 fois contre 4.
     */
    public function test_la_borne_ne_s_appelle_pas_kiosk(): void
    {
        $coupables = [];

        foreach ($this->chainesFrancaises() as $cle => $valeur) {
            if (preg_match('/\bkiosks?\b/iu', $valeur)) {
                $coupables[] = "{$cle} = " . mb_substr($valeur, 0, 80);
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Des chaînes disent « Kiosk » alors que le produit dit « Borne » partout\n"
            . "ailleurs (37 chaînes mesurées). Un commerçant ne devrait pas avoir à\n"
            . "deviner que les deux mots désignent le même appareil.\n\n"
            . implode("\n", $coupables)
        );
    }

    /**
     * [ONB-10 2026-08-28] Le tutoiement ne passe pas que par les pronoms.
     *
     * Le test ci-dessus ne cherche que « tu / tes / ton / ta ». Il était vert alors
     * que `label.composer.guidance_zero_steps_option_template` disait « Préférable :
     * **choisis** un template » — un impératif à la 2e personne du singulier, entre
     * deux chaînes voisines qui vouvoient. Trouvé à l'écran, pas par le banc.
     *
     * Les verbes conjugués ne sont pas détectables par motif sans faux positifs
     * massifs. Deux garde-fous, tous deux issus de mesures et non de suppositions :
     *
     *   1. la liste est NOMMÉE, et volontairement étroite. Un premier jet incluait
     *      « clôture », « valide », « pose », « photographie » : neuf faux positifs
     *      immédiats, tous sur le NOM (« Total clôture », « PDF Clôture du jour »).
     *      Toute forme qui est aussi un nom courant du métier en est exclue ;
     *   2. la forme doit être en DÉBUT DE PHRASE, là où vit un impératif. C'est ce
     *      qui distingue « Vérifie le stock » de « Je vérifie » — un autre faux
     *      positif mesuré.
     *
     * La liste grandit quand un cas est rencontré. C'est un cliquet, pas un filet :
     * elle ne prétend pas tout attraper, elle empêche de reperdre ce qui a été vu.
     *
     * ⛔ N'enlève jamais une forme de cette liste pour faire passer le banc : cela
     *    voudrait dire qu'on vient de réintroduire le tutoiement qu'elle garde.
     */
    private const IMPERATIFS_TUTOYANTS = [
        'choisis', 'saisis', 'remplis',
        'ajoute', 'sélectionne', 'selectionne', 'renseigne',
        'scanne', 'clique', 'appuie', 'vérifie', 'verifie',
        'complète', 'complete', 'modifie', 'supprime',
        // Rencontré le 2026-08-28 dans « Modifie la période ou réinitialise les
        // filtres » : la seconde forme d'une phrase à deux ordres échappait au
        // premier passage, qui ne regarde que le début de phrase.
        'réinitialise', 'reinitialise',
    ];

    public function test_aucune_chaine_ne_donne_d_ordre_au_singulier(): void
    {
        $coupables = [];
        // Début de chaîne, ou après une fin de phrase / un deux-points / un tiret :
        // les seules positions où un impératif se tient.
        $motif = '/(?:^|(?<=[.!?:—–]\s)|(?<=^«\s))\s*('
            . implode('|', self::IMPERATIFS_TUTOYANTS) . ')\b/iu';

        foreach ($this->chainesFrancaises() as $cle => $valeur) {
            if (preg_match($motif, $valeur, $m)) {
                $coupables[] = "{$cle} — « " . trim($m[1]) . ' » : ' . mb_substr($valeur, 0, 90);
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Des chaînes donnent un ordre au commerçant à la 2e personne du SINGULIER,\n"
            . "alors que l'interface le vouvoie partout ailleurs. La forme attendue est\n"
            . "« choisissez », « ajoutez », « vérifiez ».\n\n"
            . "Si la forme signalée est en réalité un présent de 3e personne (« le client\n"
            . "valide »), c'est la liste IMPERATIFS_TUTOYANTS qu'il faut affiner — pas la\n"
            . "chaîne qu'il faut laisser.\n\n"
            . implode("\n", $coupables)
        );
    }

    public function test_la_sentinelle_mord(): void
    {
        // Un contrôle négatif : la recherche doit effectivement attraper un tutoiement
        // si on en plante un. Sans ça, un test vert ne prouverait rien.
        $faux = 'Pense à scanner tes factures.';

        $this->assertMatchesRegularExpression(
            '/\b(tes|ton|ta|tu)\b/iu',
            $faux,
            'Le motif doit détecter un tutoiement.'
        );

        // Et il ne doit PAS se déclencher sur un mot qui contient les mêmes lettres.
        $this->assertDoesNotMatchRegularExpression(
            '/\b(tes|ton|ta|tu)\b/iu',
            'Ouvrez le tableau de bord pour consulter la tonalité.',
            'Le motif ne doit pas attraper « tableau » ni « tonalité ».'
        );
    }
}
