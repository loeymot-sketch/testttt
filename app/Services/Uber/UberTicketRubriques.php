<?php

namespace App\Services\Uber;

/**
 * [SAUCES FRITES UBER 2026-11-20 · owner] Le titre de RUBRIQUE du ticket Uber est une information,
 * pas un bruit à jeter.
 *
 * LE DÉFAUT
 * ---------
 * Le ticket Uber range les choix d'un produit sous des titres en capitales :
 *
 *     1 x Menu sandwich Cayenne
 *       SAUCE              ← les deux sauces DU SANDWICH
 *       1x Algérienne
 *       1x Harissa
 *       SAUCE FRITES       ← la sauce DES FRITES (une offerte, les autres payantes)
 *       1x Ketchup
 *
 * La lecture jetait les titres (pour qu'ils ne deviennent pas des suppléments fantômes) et ne
 * gardait que les valeurs : « Algérienne, Harissa, Ketchup ». Plus rien ne disait laquelle allait
 * aux frites — le propriétaire, 2026-11-20 : « tu mets toutes les sauces dans le sandwich ».
 * Pire : un titre de deux mots (« SAUCE FRITES ») n'était pas reconnu comme titre et passait
 * lui-même pour une sauce des frites, pendant que le vrai Ketchup partait dans le sandwich.
 *
 * LA RÈGLE
 * --------
 * Un titre de rubrique ne s'imprime toujours pas, mais il ÉTIQUETTE les choix qui le suivent :
 * « 1x Ketchup » sous « SAUCE FRITES » devient « 1x SAUCE FRITES : Ketchup ». Le classifieur
 * reconnaît déjà les étiquettes (« l'information la plus fiable du ticket ») : chaque valeur
 * tombe dans sa case sans aucune devinette. La quantité reste EN TÊTE, là où le classifieur la lit.
 *
 * Le seul discriminant entre un titre et une valeur est la CASSE (le papier imprime les titres en
 * capitales et les choix en casse normale — cf. commande réelle E63F5, où « Pain » est AUSSI un
 * choix de la carte). On exige en plus un mot de rubrique connu, et un titre ne peut pas être une
 * formule (« MENU (FRITES + BOISSON) » écrit en capitales reste un choix).
 */
final class UberTicketRubriques
{
    /**
     * [relecture adverse 2026-11-20] Un titre est FAIT de mots de rubrique, rien d'autre. « SAUCE
     * BLANCHE », « DOUBLE VIANDE », « SANS CRUDITÉS » écrits en capitales sont des CHOIX : les prendre
     * pour des titres les effaçait (une sauce perdue, un refus perdu — la cuisine ajoutait les
     * crudités refusées). Formes admises : « SAUCES », « VOS SAUCES », « CHOIX DE LA BOISSON »,
     * « SAUCE(S) (POUR VOS) FRITES », « DIP FRITES ».
     */
    private const FORME_DE_TITRE = '/^((au\s+)?choix\s+(de\s+(la\s+|du\s+|des\s+|l\s*)?|du\s+|des\s+)?|choisissez\s+(vos\s+|votre\s+|la\s+|le\s+|les\s+)?|vos\s+|votre\s+|tes\s+|ta\s+|ton\s+|type\s+de\s+)?(pains?|sauces?|crudites?|boissons?|supplements?|garnitures?|viandes?|accompagnements?|desserts?|options?|extras?)(\s+\d)?$|^sauces?\s+gratinees?$|^boissons?\s+\d+\s*cl$|^(sauces?|dips?)(\s+(gratuites?|offertes?|incluses?|supplementaires?|payantes?|extras?|en\s+plus))?\s+(pour\s+|avec\s+)?(les\s+|vos\s+|tes\s+|des\s+)?frites?(\s+(gratuites?|offertes?|incluses?|supplementaires?|payantes?|extras?|en\s+plus))?$|^sauces?\s+supplementaires?$|^supplements?\s+sauces?$|^supplements?\s+(?!frites?$)[a-z]+$|^seul\s+ou\s+en\s+menu$/u';

    /** Le titre des sauces des FRITES, reconnu même écrit en casse normale (« Sauce frites »). */
    private const TITRE_SAUCE_FRITES = '/^(sauces?|dips?)(\s+(gratuites?|offertes?|incluses?|supplementaires?|payantes?|extras?|en\s+plus))?\s+(pour\s+|avec\s+)?(les\s+|vos\s+|tes\s+|des\s+)?frites?(\s+(gratuites?|offertes?|incluses?|supplementaires?|payantes?|extras?|en\s+plus))?$/u';

    /**
     * Étiquette chaque choix par le titre de rubrique qui le précède ; les titres disparaissent.
     *
     * Un choix déjà étiqueté (« PAIN: Galette », « Sauce : Harissa ») garde son étiquette. Un titre
     * reconnu disparaît toujours : seul, sans choix derrière lui, il n'apporte rien (gardé, « SAUCE
     * FRITES (1 INCLUSE) » devenait une sauce des frites nommée « FRI »). La fonction est IDEMPOTENTE :
     * la lecture l'applique, le mapper la rejoue sur les lectures anciennes.
     *
     * @param  list<string>  $options
     * @return list<string>
     */
    public static function attacher(array $options): array
    {
        $sortie = [];
        $rubrique = null;

        $options = array_values(array_filter(array_map(static fn ($o): string => trim((string) $o), $options), static fn (string $o): bool => $o !== ''));
        foreach ($options as $i => $option) {

            if (self::estTitre($option) || self::estTitreDeFormule($option, $options[$i + 1] ?? null)) {
                $rubrique = self::libelle($option);

                continue;
            }

            // [relecture adverse 2 · 2026-11-20] Une ligne en CAPITALES, sans quantité, qui n'est pas
            // un titre connu (« SEUL OU EN MENU », « BASE BOL », « SAUCE BLANCHE »…) est une BARRIÈRE :
            // l'étiquette du titre précédent s'arrête là. Sinon elle coulait sur les choix suivants et
            // un menu, une galette ou un supplément devenait une sauce. La ligne reste écrite telle
            // quelle, et les choix qui la suivent sont classés par les tables, comme avant.
            if (self::estBarriere($option) && ! self::choixReconnuSousLaRubrique($option, $rubrique)) {
                $rubrique = null;
                // [relecture adverse 5] On ne la SUPPRIME jamais : une valeur en capitales (« GALETTE »,
                // « POULET ») est indiscernable d'un titre inconnu, et la jeter perdait le choix. Un
                // titre inconnu reste donc une ligne visible, comme avant cette correction.
                $sortie[] = $option;

                continue;
            }

            if ($rubrique === null) {
                $sortie[] = $option;

                continue;
            }

            $sortie[] = self::etiqueter($option, $rubrique);
        }

        return $sortie;
    }

    /**
     * Une ligne d'ARTICLE qui n'est qu'un titre de rubrique (« CRUDITÉS », « SAUCE FRITES (1 INCLUSE) ») :
     * ses choix appartiennent au produit écrit au-dessus.
     */
    public static function estTitreDArticle(string $titre): bool
    {
        $n = self::norm(self::sansQuota($titre));

        return $n !== '' && (bool) preg_match(self::FORME_DE_TITRE, $n);
    }

    /** Le libellé de rubrique, débarrassé de son quota (« SAUCE FRITES - 1 INCLUSE » → « SAUCE FRITES »). */
    public static function libelle(string $titre): string
    {
        // « VIANDE 1 » → « VIANDE » : un chiffre rend l'étiquette illisible pour le classifieur.
        $t = trim((string) preg_replace('/\s+\d+(\s*cl)?$/iu', '', self::sansQuota($titre)));

        return $t !== '' ? $t : trim($titre);
    }

    /**
     * [VRAIS TICKETS 2026-11-20 soir] « FORMULE » (ou « MENU ») en capitales est le TITRE de la rubrique
     * « Seul / Frites seules / En menu » quand un choix chiffré le suit (« FORMULE », « 1x Seul »).
     * Suivi d'autre chose, c'est le CHOIX lui-même (« SEUL OU EN MENU », « FORMULE ») : il reste un menu.
     */
    private static function estTitreDeFormule(string $option, ?string $suivante): bool
    {
        $brut = trim($option);
        if ($suivante === null || $brut !== mb_strtoupper($brut, 'UTF-8')
            // Choix chiffré ET pas encore étiqueté : « 1x BOISSON : Coca » (passe rejouée par le mapper)
            // appartient à une autre rubrique — sans cette garde, la passe n'était plus idempotente.
            || ! preg_match('/^\s*\d{1,2}\s*[x×][^:：]+$/iu', $suivante)) {
            return false;
        }

        return (bool) preg_match('/^(votre\s+|ta\s+|choix\s+de\s+(la\s+)?)?(formules?|menus?)$/u', self::norm(self::sansQuota($brut)));
    }

    /** Titre de rubrique : sans quantité ni prix, fait de mots de rubrique ; jamais une formule. */
    public static function estTitre(string $texte): bool
    {
        $brut = trim($texte);
        if ($brut === '' || mb_strlen($brut) > 60) {
            return false;
        }
        // Quantité en tête (« 1x Ketchup ») = un choix.
        if (preg_match('/^\s*\d{1,2}\s*[x×]/iu', $brut) || preg_match('/^\s*[x×]\s*\d/iu', $brut)) {
            return false;
        }
        // Un prix ou un « + » : un choix chiffré ou une formule (« MENU (FRITES + BOISSON) »).
        if (preg_match('/\d+[.,]\d{1,2}|€|\+/u', $brut)) {
            return false;
        }

        $sans = self::sansQuota($brut);
        // Un « : » qui reste après le quota porte une VALEUR (« SAUCE : Harissa ») : c'est un choix.
        if ($sans === '' || preg_match('/[:：]/u', $sans)) {
            return false;
        }
        $n = self::norm($sans);

        // Le papier imprime les titres en CAPITALES et les choix en casse normale (commande E63F5 :
        // « Pain » est aussi un choix de la carte). Seul le titre des frites est admis en casse
        // normale : « Sauce frites (1 incluse) » n'est le nom d'aucun choix.
        if ($brut !== mb_strtoupper($brut, 'UTF-8')) {
            return (bool) preg_match(self::TITRE_SAUCE_FRITES, $n);
        }

        return (bool) preg_match(self::FORME_DE_TITRE, $n);
    }

    /**
     * [relecture adverse 3-4] « KETCHUP » en capitales sous « SAUCE FRITES » est un CHOIX : il garde la
     * rubrique. Volontairement ÉTROIT : un seul mot, une sauce connue, sous une rubrique de sauce.
     * Plus large, un titre inconnu qui contient un mot des tables (« FROMAGE », « GRATINÉ AU
     * FROMAGE ») gardait l'étiquette précédente et le Cheddar suivant devenait une sauce ou une viande.
     */
    private static function choixReconnuSousLaRubrique(string $option, ?string $rubrique): bool
    {
        if ($rubrique === null || ! preg_match('/\bsauces?\b|\bdips?\b/u', self::norm($rubrique))) {
            return false;
        }
        $mot = self::norm($option);
        if (! preg_match('/^[a-z]+$/u', $mot) || preg_match('/^(fromages?|gratine|supplements?|extras?|options?|choix|sauces?|dips?)$/u', $mot)) {
            return false;
        }

        return (new \App\Services\Hardware\KitchenTicketSymbolicFormatter)
            ->knownSauceSymbol(mb_convert_case(trim($option), MB_CASE_TITLE, 'UTF-8')) !== '';
    }

    /** Ligne en capitales, sans quantité ni « : », qui n'est pas un titre reconnu. */
    private static function estBarriere(string $texte): bool
    {
        $brut = trim($texte);
        if ($brut !== mb_strtoupper($brut, 'UTF-8') || ! preg_match('/\p{Lu}{2,}/u', $brut)) {
            return false;
        }
        if (preg_match('/^\s*\d{1,2}\s*[x×]/iu', $brut) || preg_match('/^\s*[x×]\s*\d/iu', $brut)) {
            return false;
        }

        return ! preg_match('/[:：]/u', self::sansQuota($brut));
    }

    /**
     * Retire la mention de QUOTA d'un titre : « (1 INCLUSE) », « - 2 INCLUSES », « : 1 INCLUSE »,
     * « (MAX 3) », « CHOISISSEZ 2 SAUCES » → « SAUCES ». La cuisine n'en a pas besoin, et un chiffre
     * dans l'étiquette la rendait illisible pour le classifieur (la sauce des frites était perdue).
     */
    private static function sansQuota(string $titre): string
    {
        $t = (string) preg_replace('/\s*\([^)]*\)\s*/u', ' ', $titre);
        $t = (string) preg_replace('/\s*[-–—:：]\s*(\d+|max|jusqu|au\s+choix|obligatoire|facultati|optionnel)[^:：]*$/iu', '', $t);
        $t = (string) preg_replace('/\b(\d+|une?|deux|trois)\s+(au\s+choix|inclus\w*|offert\w*|max\w*)\b/iu', '', $t);
        $t = (string) preg_replace('/\b(choisissez|choisis|choisir|selectionnez|sélectionnez)\s+(\d+|une?|deux|trois)?\s*/iu', 'CHOISISSEZ ', $t);
        $t = (string) preg_replace('/\b(max(imum)?|jusqu\'?à)\s+\d+\b/iu', '', $t);
        $t = (string) preg_replace('/\s+au\s+choix\s*$/iu', '', $t);
        // « SAUCE FRITES : » (deux-points final, sans valeur) est un titre.
        $t = (string) preg_replace('/\s*[:：]\s*$/u', '', $t);

        return trim((string) preg_replace('/\s{2,}/u', ' ', $t), " \t-–—");
    }

    /** « 2x Ketchup » sous « SAUCE FRITES » → « 2x SAUCE FRITES : Ketchup » (quantité en tête). */
    private static function etiqueter(string $option, string $rubrique): string
    {
        $quantite = '';
        $reste = $option;
        if (preg_match('/^\s*(\d{1,2}\s*[x×])\s*(.+)$/iu', $option, $m)) {
            $quantite = trim($m[1]).' ';
            $reste = trim($m[2]);
        } elseif (preg_match('/^\s*([x×]\s*\d{1,2})\s+(.+)$/iu', $option, $m)) {
            $quantite = trim($m[1]).' ';
            $reste = trim($m[2]);
        }

        // Déjà étiqueté (par le ticket, ou par une passe précédente) : on n'y touche pas.
        if (preg_match('/^[\p{L}\s\'’()]{2,40}?\s*[:：]\s*\S/u', $reste)) {
            return $option;
        }

        return $quantite.$rubrique.' : '.$reste;
    }

    /** Minuscules ASCII, ponctuation en espaces : « CHOIX DE L'ACCOMPAGNEMENT » → « choix de l accompagnement ». */
    private static function norm(string $s): string
    {
        $n = mb_strtolower(trim(\Illuminate\Support\Str::ascii($s)));
        $n = (string) preg_replace('/[^a-z0-9\s]+/u', ' ', $n);

        return trim((string) preg_replace('/\s+/u', ' ', $n));
    }
}
