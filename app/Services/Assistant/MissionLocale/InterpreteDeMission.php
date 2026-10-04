<?php

namespace App\Services\Assistant\MissionLocale;

/**
 * [ONB-04 2026-08-28] Comprendre une phrase du commerçant — SANS modèle de langue.
 *
 * ═══ POURQUOI DÉTERMINISTE, ET POURQUOI C'EST LE BON CHOIX ═══
 *
 * Le mandat demande « un chatbot de missions locales ». La tentation est d'y mettre
 * un modèle. Trois raisons de ne pas le faire ICI :
 *
 *  1. **Le gate G-IA n'est pas tranché**, et il ne porte pas seulement sur un
 *     fournisseur : il porte sur un PLAFOND DE DÉPENSE que ce projet n'a pas
 *     (`assistant.budget.plafond_mensuel_euros` vaut 0 par défaut, délibérément).
 *     Un assistant qui n'appelle rien peut être livré aujourd'hui.
 *
 *  2. **Un refus franc vaut mieux qu'une interprétation plausible.** Ces missions
 *     ÉCRIVENT dans le catalogue. « J'ai compris à peu près » sur cinquante produits
 *     est exactement le genre d'erreur qu'on découvre trois jours plus tard, au
 *     moment où un client commande. Ici, ce qui n'est pas dans la grammaire est
 *     refusé en NOMMANT les formes comprises.
 *
 *  3. Le jour où un modèle prendra le relais, il remplacera UNIQUEMENT cette
 *     classe — la phrase devient une `Mission`, et tout l'aval (plan, confirmation,
 *     application validée) reste identique. C'est la même architecture que
 *     l'extraction de carte : contrat, bouchon, implémentation réelle plus tard.
 *
 * ═══ CE QU'IL COMPREND ═══
 *
 *   « ajoutez la sauce Algérienne à tous les tacos »
 *   « ajoutez la sauce Blanche à tous les tacos pour 0,50 € »
 *   « ajoutez l'option Cheddar à tous les burgers pour 1 € »
 *   « passez tous les tacos à 9,50 € »
 *   « désactivez tous les desserts »   /   « activez tous les desserts »
 *
 * Les formes au TUTOIEMENT (« ajoute », « passe », « désactive ») sont comprises
 * aussi : on ne refuse personne sur la conjugaison qu'il a choisie.
 *
 * Tout le reste est refusé, avec la liste ci-dessus rendue au commerçant.
 */
class InterpreteDeMission
{
    /**
     * Les formes comprises, telles qu'on les montre au commerçant quand on refuse.
     *
     * @return list<string>
     */
    public static function formesComprises(): array
    {
        // Montrees au VOUVOIEMENT, comme tout le reste du produit. Les formes au
        // tutoiement sont comprises aussi : on ne refuse pas quelqu'un sur la
        // conjugaison qu'il a choisie.
        return [
            'ajoutez la sauce <nom> à tous les <catégorie>',
            'ajoutez la sauce <nom> à tous les <catégorie> pour <prix> €',
            "ajoutez l'option <nom> à tous les <catégorie> pour <prix> €",
            'passez tous les <catégorie> à <prix> €',
            'désactivez tous les <catégorie>',
            'activez tous les <catégorie>',
        ];
    }

    /**
     * @return array{mission: Mission|null, refus: string|null}
     */
    public function comprendre(string $phrase): array
    {
        $normalisee = $this->normaliser($phrase);

        if ($normalisee === '') {
            return $this->refuser('Dites-moi ce que vous voulez faire.');
        }

        foreach ([
            'ajoutUneOption',
            'changementDePrix',
            'changementDeDisponibilite',
        ] as $tentative) {
            $mission = $this->{$tentative}($normalisee, $phrase);

            if ($mission !== null) {
                return ['mission' => $mission, 'refus' => null];
            }
        }

        return $this->refuser(
            "Je n'ai pas compris « " . trim($phrase) . " »."
        );
    }

    // ─────────────────────────────────────────────────────────── les trois formes

    private function ajoutUneOption(string $n, string $original): ?Mission
    {
        // `ajoute la sauce X à tous les Y` · `ajoute l'option X aux Y pour 1,50 €`
        // `ajoute` ET `ajoutez` : l'interface vouvoie le commercant, il ecrira
        // spontanement la seconde forme. Ne comprendre que la premiere, ce serait
        // le refuser sur la conjugaison que le produit lui a enseignee.
        $motif = '/^ajout(?:e|ez|es)\s+(?:la\s+|le\s+|l\s*\'?\s*|un\s+|une\s+)?'
            . '(sauce|option|supplement|garniture|boisson|accompagnement)\s+'
            . '(.+?)\s+'
            . '(?:a|aux)\s+(?:tous\s+les\s+|toutes\s+les\s+|tout\s+le\s+|toute\s+la\s+)?'
            . '(.+?)'
            . '(?:\s+pour\s+' . $this->motifDePrix() . ')?$/u';

        if (! preg_match($motif, $n, $c)) {
            return null;
        }

        // Le NOM de l'option et la CATÉGORIE sont repris du texte d'origine, pas de
        // la forme normalisée : « Algérienne » ne doit pas devenir « algerienne » à
        // l'écran ni en base. La normalisation ne sert qu'à reconnaître, jamais à
        // écrire.
        $nomOption = $this->extraireDuTexteOriginal($original, $c[2]);
        $categorie = $this->extraireDuTexteOriginal($original, $c[3]);

        if ($nomOption === '' || $categorie === '') {
            return null;
        }

        return Mission::ajouterUneOption(
            categorie: $categorie,
            nomOption: $nomOption,
            groupe: $this->groupeLisible($c[1]),
            prix: isset($c[4]) && $c[4] !== '' ? $this->prix($c[4]) : 0.0,
        );
    }

    private function changementDePrix(string $n, string $original): ?Mission
    {
        $motif = '/^(?:passe|passez|passes|mets|mettez|met|change|changez|changes)\s+'
            . '(?:tous\s+les\s+|toutes\s+les\s+|le\s+prix\s+(?:de|des)\s+(?:tous\s+les\s+)?)?'
            . '(.+?)\s+(?:a|à)\s+' . $this->motifDePrix() . '$/u';

        if (! preg_match($motif, $n, $c)) {
            return null;
        }

        $categorie = $this->extraireDuTexteOriginal($original, $c[1]);

        return $categorie === ''
            ? null
            : Mission::changerLePrix($categorie, $this->prix($c[2]));
    }

    private function changementDeDisponibilite(string $n, string $original): ?Mission
    {
        $motif = '/^(desactive|desactivez|desactives|active|activez|actives'
            . '|masque|masquez|masques|affiche|affichez|affiches)\s+'
            . '(?:tous\s+les\s+|toutes\s+les\s+)?(.+?)$/u';

        if (! preg_match($motif, $n, $c)) {
            return null;
        }

        $categorie = $this->extraireDuTexteOriginal($original, $c[2]);

        // On compare sur le RADICAL : `active`, `activez` et `actives` disent la
        // meme chose, et la liste courte d'origine n'en reconnaissait qu'une.
        $active = str_starts_with($c[1], 'active') || str_starts_with($c[1], 'affiche');

        return $categorie === ''
            ? null
            : Mission::changerLaDisponibilite($categorie, $active);
    }

    // ───────────────────────────────────────────────────────────────── outillage

    /** `9,50 €` · `9.50 euros` · `1 €` — la virgule décimale française comprise. */
    private function motifDePrix(): string
    {
        return '(\d+(?:[.,]\d{1,2})?)\s*(?:€|euros?|eur)?';
    }

    private function prix(string $brut): float
    {
        return (float) str_replace(',', '.', $brut);
    }

    private function groupeLisible(string $motCle): string
    {
        return match ($motCle) {
            'sauce'           => 'Sauce',
            'boisson'         => 'Boisson',
            'garniture'       => 'Garniture',
            'accompagnement'  => 'Accompagnement',
            'supplement'      => 'Supplément',
            default           => 'Option',
        };
    }

    /**
     * Minuscules, accents retirés, ponctuation et espaces multiples réduits.
     *
     * On normalise pour RECONNAÎTRE, jamais pour écrire : « Algérienne » saisie par
     * le commerçant doit ressortir « Algérienne », pas « algerienne ».
     */
    private function normaliser(string $phrase): string
    {
        $n = mb_strtolower(trim($phrase));

        $n = strtr($n, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'û' => 'u', 'ü' => 'u', 'ù' => 'u',
            'ç' => 'c',
        ]);

        $n = preg_replace('/[.!?;]+$/u', '', $n) ?? $n;

        return trim(preg_replace('/\s+/u', ' ', $n) ?? $n);
    }

    /**
     * Retrouve, dans la phrase d'origine, le fragment qui correspond au fragment
     * normalisé — pour rendre la casse et les accents saisis par le commerçant.
     *
     * On travaille par POSITION : la normalisation ne change pas la longueur des
     * mots (elle remplace un caractère par un caractère), donc les décalages sont
     * conservés. Si jamais ce n'était plus vrai, on retombe sur le fragment
     * normalisé plutôt que de rendre n'importe quoi.
     */
    private function extraireDuTexteOriginal(string $original, string $fragmentNormalise): string
    {
        $normaliseeComplete = $this->normaliser($original);
        $position = mb_strpos($normaliseeComplete, $fragmentNormalise);

        if ($position === false || mb_strlen($normaliseeComplete) !== mb_strlen(trim($original))) {
            // Les longueurs diffèrent (ponctuation retirée, espaces réduits) :
            // on ne peut plus se fier aux positions.
            return trim($fragmentNormalise);
        }

        return trim(mb_substr(trim($original), $position, mb_strlen($fragmentNormalise)));
    }

    /** @return array{mission: null, refus: string} */
    private function refuser(string $pourquoi): array
    {
        return [
            'mission' => null,
            'refus'   => $pourquoi . " Voici ce que je sais faire :\n· "
                . implode("\n· ", self::formesComprises()),
        ];
    }
}
