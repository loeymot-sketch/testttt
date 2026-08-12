<?php

namespace App\Services\Uber;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Range une OPTION lue sur un ticket Uber dans la bonne case de
 * la composition, pour que la cuisine la voie exactement comme une commande maison.
 *
 * LE PROBLÈME
 * -----------
 * Sur un ticket Uber, tout ce qui accompagne un produit arrive en vrac, sous forme de lignes de
 * texte : « Viande : Poulet mariné », « Sauce Algérienne », « Coca-Cola 33cl », « Supplément
 * Cheddar (+1,00 €) », « 2x Oignons frits », « Sans oignons ». Le ticket cuisine et l'écran KDS,
 * eux, attendent une STRUCTURE : les viandes et les sauces vont dans la ligne symbolique, les
 * crudités se replient en « STO », les boissons ont leur propre ligne, les suppléments payants
 * restent écrits EN TOUTES LETTRES.
 *
 * L'owner l'a formulé exactement ainsi : « tout traduire en symbole comme il faut, SAUF les
 * suppléments — ça va être complet, les boissons, etc. » C'est déjà, mot pour mot, le
 * comportement du ticket cuisine maison. Il n'y a donc rien à réinventer : il suffit de déposer
 * chaque option lue dans la case que la cuisine lit déjà.
 *
 * LA RÈGLE DE DÉCISION
 * --------------------
 * 1. Une ÉTIQUETTE explicite sur la ligne gagne toujours (« Sauce : … », « Viande : … »). C'est
 *    l'information la plus fiable du ticket : elle vient du menu Uber, pas d'une devinette.
 * 2. Sinon on interroge les tables de la CUISINE elle-même (viandes, crudités, boissons). Ces
 *    tables sont la référence unique du projet — en réutiliser une copie serait la garantie
 *    d'une divergence le jour où l'owner ajoute une sauce.
 * 3. Ce qui n'est reconnu par personne devient un SUPPLÉMENT écrit en toutes lettres. C'est le
 *    repli le plus sûr : le cuisinier lit le texte d'origine et décide lui-même, au lieu de voir
 *    un symbole faux — ou pire, de ne rien voir du tout.
 *
 * Jamais d'invention : une option non reconnue n'est jamais RECLASSÉE en produit du catalogue.
 */
final class UberTicketOptionClassifier
{
    public const VIANDE = 'viande';
    public const SAUCE = 'sauce';
    /**
     * La sauce qui accompagne les FRITES est un canal à part, et c'est une distinction qui se
     * mange : rangée avec les sauces du produit, un « Sauce frites : Ketchup » ferait mettre du
     * ketchup DANS le tacos. La cuisine l'affiche à sa place, sur la ligne du menu (« MENU : KTP »).
     */
    public const SAUCE_FRITES = 'sauce_frites';
    public const SUPPORT = 'support';
    public const CRUDITE = 'crudite';
    public const BOISSON = 'boisson';
    public const MENU = 'menu';
    /** Frites servies en accompagnement, hors formule — une portion de plus au bain de friture. */
    public const FRITES = 'frites';
    public const SUPPLEMENT = 'supplement';
    /**
     * [RETRAIT 2026-08-12] Un RETRAIT : « Sans oignons », « Pas de sauce », « Retirer : Tomate ».
     *
     * Nos canaux maison sont ADDITIFS — on ne coche pas « oignons », donc il n'y en a pas ; le mot
     * « sans » n'y existe même pas. Le ticket Uber, lui, s'écrit en NÉGATIF. Sans cette case, la
     * table des crudités trouvait « oignon » dans « Sans oignons » et le repliait en garniture :
     * la cuisine lisait « O » et en mettait, à quelqu'un qui venait EXPRESSÉMENT de les refuser.
     * Mesuré en production sur une vraie photo : `CHEESE BURGER | O`.
     */
    public const RETRAIT = 'retrait';

    public function __construct(
        private readonly KitchenTicketSymbolicFormatter $formatter = new KitchenTicketSymbolicFormatter,
    ) {
    }

    /**
     * Analyse une ligne d'option brute.
     *
     * @return array{kind: string, label: string, quantity: int, price: float, raw: string}
     */
    public function classify(string $raw): array
    {
        $raw = trim($raw);
        $reste = $raw;

        // (1) Quantité en tête : « 2x Cheddar », « 2 × Cheddar », « x2 Cheddar ».
        $quantity = 1;
        if (preg_match('/^\s*(\d{1,2})\s*[x×]\s*(.+)$/iu', $reste, $m)) {
            $quantity = max(1, (int) $m[1]);
            $reste = trim($m[2]);
        } elseif (preg_match('/^\s*[x×]\s*(\d{1,2})\s+(.+)$/iu', $reste, $m)) {
            $quantity = max(1, (int) $m[1]);
            $reste = trim($m[2]);
        }

        // (2) Prix en fin de ligne : « (+1,00 €) », « +1.00€ », « 1,00 € ». Un prix > 0 est le
        //     signe le plus net d'un supplément payant — Uber ne chiffre pas les choix inclus.
        $price = 0.0;
        if (preg_match('/[(\[]?\s*\+?\s*(?:€|EUR)?\s*(\d+[.,]\d{1,2})\s*(?:€|EUR)?\s*[)\]]?\s*$/u', $reste, $m)) {
            $price = (float) str_replace(',', '.', $m[1]);
            // Retrait par LONGUEUR, pas par recherche : le motif est ancré en FIN de chaîne, et
            // un `mb_strpos` couperait à la PREMIÈRE occurrence si le même montant apparaissait
            // plus tôt dans le libellé — amputant le nom du produit au passage.
            $reste = trim(mb_substr($reste, 0, mb_strlen($reste) - mb_strlen($m[0])));
            $reste = trim($reste, " \t-–—:•·");
        }

        // (3) Étiquette de groupe en tête : « Sauce : Algérienne », « Choix de viande - Poulet ».
        $etiquette = '';
        if (preg_match('/^([\p{L}\s\'’()]{2,40}?)\s*[:：]\s*(.+)$/u', $reste, $m)) {
            $etiquette = $this->norm($m[1]);
            $reste = trim($m[2]);
        }

        $label = trim($reste, " \t-–—:•·");
        if ($label === '') {
            // Une ligne réduite à son étiquette (« Sauce : ») n'apporte rien — on rend le brut
            // en supplément pour que le cuisinier voie quand même le texte d'origine.
            return ['kind' => self::SUPPLEMENT, 'label' => $raw, 'quantity' => $quantity, 'price' => $price, 'raw' => $raw];
        }

        $kind = $this->kindFromLabel($etiquette, $label, $price);

        return ['kind' => $kind, 'label' => $label, 'quantity' => $quantity, 'price' => $price, 'raw' => $raw];
    }

    /**
     * Le client refuse-t-il quelque chose ?
     *
     * La négation n'est reconnue qu'en TÊTE de libellé, jamais au milieu : « Sauce sans gluten »
     * est le nom d'un produit qu'on ajoute, pas un retrait. Un `str_contains('sans')` aurait
     * supprimé la sauce demandée — l'erreur inverse, aussi fausse.
     */
    private function estRetrait(string $etiquette, string $valeur): bool
    {
        if ($etiquette !== '' && preg_match('/^(retir|enlev|supprim|sans|no|remove|without)/u', $etiquette)) {
            return true;
        }

        return (bool) preg_match('/^(sans|pas\s+d[eu\']|no|without|w\/o)\b/u', $this->norm($valeur));
    }

    /** Décide la case, étiquette d'abord, tables de la cuisine ensuite. */
    private function kindFromLabel(string $etiquette, string $valeur, float $price): string
    {
        // ── (0) Un REFUS n'est jamais un composant, et se teste AVANT tout le reste.
        // L'ordre n'est pas cosmétique : « Sans oignons » contient « oignons », donc n'importe
        // quelle table interrogée d'abord l'attraperait et transformerait le refus en ajout.
        if ($this->estRetrait($etiquette, $valeur)) {
            return self::RETRAIT;
        }

        // ── (1) Étiquette explicite : l'information la plus fiable du ticket.
        if ($etiquette !== '') {
            // AVANT « sauce » tout court : « Sauce frites » n'est pas la sauce du produit.
            if (preg_match('/sauce\s*(pour\s*(les\s*)?)?frites?|dip/u', $etiquette)) {
                return self::SAUCE_FRITES;
            }
            if (preg_match('/viande|meat|proteine/u', $etiquette)) {
                return self::VIANDE;
            }
            if (preg_match('/sauce/u', $etiquette)) {
                return self::SAUCE;
            }
            if (preg_match('/pain|galette|support|base|bread/u', $etiquette)) {
                return self::SUPPORT;
            }
            if (preg_match('/crudite|garniture|legume|salade/u', $etiquette)) {
                return self::CRUDITE;
            }
            if (preg_match('/boisson|drink|soda/u', $etiquette)) {
                return self::BOISSON;
            }
            if (preg_match('/menu|formule|accompagnement/u', $etiquette)) {
                // « Accompagnement : Frites » = la part frites d'une formule ; « Accompagnement :
                // Salade » n'en est pas une. On ne décide donc que sur la VALEUR.
                return $this->estFormuleAvecFrites($valeur) ? self::MENU : self::SUPPLEMENT;
            }
            if (preg_match('/supplement|extra|option|ajout/u', $etiquette)) {
                // Un supplément peut malgré tout être une viande ou une sauce identifiable : la
                // cuisine préfère « + Viande : Poulet » reconnu qu'un texte opaque. On laisse
                // donc les tables trancher plus bas plutôt que de figer ici.
                $etiquette = '';
            }
        }

        // ── (2) Tables de la CUISINE — référence unique du projet.
        // « Sauce frites Ketchup » sans deux-points reste une sauce frites : on teste le libellé
        // entier avant tout le reste, sinon la table des sauces l'attraperait comme sauce produit.
        if (preg_match('/\bsauce\s*(pour\s*(les\s*)?)?frites?\b/u', $this->norm($valeur))) {
            return self::SAUCE_FRITES;
        }
        // Frites en accompagnement, hors formule (« Frites », « Grande frites »). Testé AVANT les
        // tables : c'est du travail au bain de friture, pas un supplément à recopier.
        if (preg_match('/^(grande?|petite?|maxi|xl|\d+)?\s*frites?$/u', $this->norm($valeur))) {
            return self::FRITES;
        }
        if ($this->formatter->meatSymbol($valeur) !== '') {
            return self::VIANDE;
        }
        if ($this->formatter->knownSauceSymbol($valeur) !== '') {
            return self::SAUCE;
        }
        if ($this->formatter->cruditeSymbol($valeur) !== '' && $price <= 0) {
            // Une crudité PAYANTE (« Oignons frits 0,90 € ») n'est pas une garniture : c'est un
            // supplément, et le ticket cuisine le distingue déjà ainsi. Même règle ici.
            return self::CRUDITE;
        }
        if ($this->estFormuleAvecFrites($valeur)) {
            return self::MENU;
        }
        if ($this->formatter->isDrinkItem($valeur)) {
            return self::BOISSON;
        }
        if (preg_match('/\b(pain|galette)\b/u', $this->norm($valeur))) {
            return self::SUPPORT;
        }

        // ── (3) Repli sûr : écrit en toutes lettres, le cuisinier lit le texte d'origine.
        return self::SUPPLEMENT;
    }

    /**
     * Une formule qui comprend des FRITES ? C'est la seule forme de menu qui change le travail de
     * la cuisine (une portion de plus au bain de friture). « Menu », « Formule », « Frites +
     * Boisson » — dans tous les cas il faut que les frites soient nommées, sinon ce n'est qu'une
     * boisson qu'on aurait rangée au mauvais endroit.
     */
    private function estFormuleAvecFrites(string $valeur): bool
    {
        $n = $this->norm($valeur);

        return (bool) preg_match('/\bfrites?\b/u', $n)
            && (bool) preg_match('/\bmenu\b|\bformule\b|\bboisson\b|\+/u', $n);
    }

    private function norm(string $s): string
    {
        return mb_strtolower(trim(\Illuminate\Support\Str::ascii($s)));
    }
}
