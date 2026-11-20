<?php

namespace App\Services\Uber;

use App\Models\Item;
use App\Models\Scopes\BranchScope;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Transforme un ticket Uber LU SUR PHOTO en une commande interne
 * ordinaire — celle que la cuisine, la caisse et l'imprimante savent déjà traiter.
 *
 * LE PRINCIPE
 * -----------
 * On ne crée AUCUN affichage spécial « commande Uber ». On remplit le `composition_snapshot`,
 * exactement comme le fait la borne, et tout le reste suit gratuitement : la ligne symbolique de
 * l'écran cuisine, le bandeau de cuisson, le ticket imprimé, la vignette verte UBER du KDS, la
 * liste « en cours » de la caisse. C'est aussi la garantie qu'aucun de ces écrans ne pourra
 * « oublier » le canal Uber le jour d'une évolution : il n'y a pas de deuxième chemin à maintenir.
 *
 * CE QUI VA OÙ (règle owner : « tout en symbole, sauf les suppléments »)
 * ---------------------------------------------------------------------
 *   viande, sauce, pain/galette  → `lines`   → ligne 1 symbolique  (« G | TAC | P | STO | ALG »)
 *   crudités gratuites           → `extras`  → repliées dans « STO »
 *   suppléments payants          → `extras`  → « + Cheddar », EN TOUTES LETTRES
 *   boissons, desserts           → `addons`  → EN BAS du ticket cuisine (« 1 Coca-Cola 33cl »)
 *   sauce des frites             → note « Sauce frites : … » → « MENU : KTP » / « FRITES : KTP »
 *   formule avec frites          → `addons`  → « MENU » + 1 frite au bandeau de cuisson
 *   note client                  → instruction, ENTRE CROCHETS (voir plus bas)
 *
 * POURQUOI LA NOTE EST MISE ENTRE CROCHETS
 * ----------------------------------------
 * Les nettoyeurs d'instruction (PHP et JS) suppriment toute ligne entièrement en MAJUSCULES :
 * c'est l'écho du nom de produit qu'écrit le wizard de la caisse. Or une note Uber en capitales
 * — « SANS OIGNONS SVP », très fréquent — disparaîtrait avec. Les crochets sont le canal que ces
 * nettoyeurs préservent, et c'est déjà la parade retenue pour le webhook Uber.
 */
final class UberPhotoOrderMapper
{
    public function __construct(
        private readonly UberOrderMapper $catalog = new UberOrderMapper,
        private readonly UberTicketOptionClassifier $classifier = new UberTicketOptionClassifier,
    ) {
    }

    /**
     * @param  array{customer_name?:string, display_id?:string, order_type?:string, items?:array, total?:float|null}  $ticket
     * @return array{display_id:string, queue_number:?string, customer_name:string, order_type:string, total:float, items:array<int,array>, unmapped:int}
     */
    public function map(array $ticket): array
    {
        $items = [];
        $unmapped = 0;

        foreach ((array) ($ticket['items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $mapped = $this->mapLine($line);
            if ($mapped['unmapped']) {
                $unmapped++;
            }
            $items[] = $mapped['line'];
        }

        $displayId = trim((string) ($ticket['display_id'] ?? ''));

        return [
            'display_id' => $displayId,
            'queue_number' => $this->queueNumber($displayId),
            'customer_name' => trim((string) ($ticket['customer_name'] ?? '')),
            'order_type' => strtolower(trim((string) ($ticket['order_type'] ?? ''))),
            'total' => (float) ($ticket['total'] ?? 0),
            'items' => $items,
            'unmapped' => $unmapped,
        ];
    }

    /**
     * @param  array{title?:string, quantity?:int, options?:array<int,string>, note?:string}  $line
     * @return array{line: array<string,mixed>, unmapped: bool}
     */
    private function mapLine(array $line): array
    {
        $title = trim((string) ($line['title'] ?? ''));
        $quantity = max(1, (int) ($line['quantity'] ?? 1));

        [$itemId, $estMenu] = $this->resoudreArticle($title);
        $unmapped = ($itemId === null);
        // Le libellé d'Uber reste la référence pour tout ce qui décrit la FORMULE (« grande »
        // frites), que le nom de notre carte ne porte pas.
        $titreUber = $title;
        // Reconnu → la ligne prend le nom de NOTRE carte : c'est lui que la cuisine sait lire, et
        // c'est ce qui met l'écran de validation et le papier d'accord (voir nomDeLaCarte()).
        $title = $this->nomDeLaCarte($itemId, $title);
        if ($unmapped) {
            // Dégradation gracieuse, identique au webhook : une commande déjà payée ne se perd
            // JAMAIS parce qu'un nom de produit n'a pas été reconnu. Elle s'ancre sur un article
            // technique inactif, hors carte, et le titre réel reste lisible en cuisine.
            $itemId = $this->catalog->fallbackItemId();
        }

        $lines = [];
        $extras = [];
        $addons = [];
        $notes = [];
        $saucesFrites = [];

        $nbViandes = 0;
        $nbSauces = 0;
        // [SAUCES FRITES UBER 2026-11-20 · owner] Le ticket Uber imprime les sauces du produit, PUIS
        // la formule, PUIS la sauce des frites. Quand la rubrique des frites a perdu son titre (ou
        // porte le même titre « SAUCE »), c'est cet ORDRE qui reste : une sauce qui revient après la
        // formule, alors que celles du produit sont déjà passées, appartient aux frites. On ne
        // tranche qu'à la fin de la ligne, une fois sûr qu'elle porte bien des frites.
        $sauceDuProduitVue = false;
        $formuleApresLesSauces = false;
        $saucesTardives = [];
        $formuleFritesChoisie = false;

        // Un titre de rubrique encore présent (« SAUCE FRITES », lecture ancienne ou titre composé)
        // étiquette les choix qui le suivent, au lieu de passer pour un choix lui-même.
        foreach (UberTicketRubriques::attacher(array_map('strval', (array) ($line['options'] ?? []))) as $option) {
            $option = trim((string) $option);
            if ($option === '' || self::estChoixSansFormule($option)) {
                continue;
            }
            $o = $this->classifier->classify($option);

            switch ($o['kind']) {
                case UberTicketOptionClassifier::VIANDE:
                    // Les emplacements sont numérotés : c'est leur NOMBRE qui détermine la portion
                    // de cuisson (une viande = portion pleine, deux = une demie chacune).
                    for ($i = 0; $i < $o['quantity']; $i++) {
                        $nbViandes++;
                        $lines[] = ['attribute_name' => 'Viande '.$nbViandes, 'variation_name' => $o['label']];
                    }
                    break;

                case UberTicketOptionClassifier::SAUCE:
                    // Une sauce se range par sa place, SAUF sous « SUPPLÉMENTS » (ou extra, option,
                    // ajout) : c'est alors une sauce en plus DU PRODUIT, payée comme telle.
                    // [relecture adverse 3] … ni sous une rubrique de sauce NOMMÉE (« SAUCE GRATINÉE »
                    // d'un tacos reste au produit). Une sauce égarée sous « BOISSON » (titre des frites
                    // absent de la photo), elle, se range par sa place.
                    $etq = (string) ($o['etiquette'] ?? '');
                    $rangeeParSaPlace = ! preg_match('/suppl|extra|option|ajout/u', $etq)
                        && (! str_contains($etq, 'sauce')
                            || preg_match('/^((au\s+)?choix\s+(de\s+(la\s+|l\s*)?|des\s+|du\s+)?|vos\s+|votre\s+|tes\s+|ta\s+)?sauces?$/u', $etq));
                    if ($formuleApresLesSauces && $rangeeParSaPlace) {
                        for ($i = 0; $i < $o['quantity']; $i++) {
                            $saucesTardives[] = $o['label'];
                        }
                        break;
                    }
                    $sauceDuProduitVue = true;
                    for ($i = 0; $i < $o['quantity']; $i++) {
                        $nbSauces++;
                        $lines[] = ['attribute_name' => 'Sauce '.$nbSauces, 'variation_name' => $o['label']];
                    }
                    break;

                case UberTicketOptionClassifier::SUPPORT:
                    $lines[] = ['attribute_name' => 'Pain', 'variation_name' => $o['label']];
                    break;

                case UberTicketOptionClassifier::CRUDITE:
                    // Prix ZÉRO obligatoire : c'est ce qui fait replier la crudité dans « STO »
                    // au lieu de sortir une ligne « + Salade ». Une crudité payante a déjà été
                    // classée en supplément par le classifieur.
                    $extras[] = [
                        'extra_name' => $o['label'], 'quantity' => $o['quantity'],
                        'unit_price' => 0.0, 'line_total' => 0.0,
                    ];
                    break;

                case UberTicketOptionClassifier::DESSERT:
                    $formuleApresLesSauces = $formuleApresLesSauces || $sauceDuProduitVue;
                    $addons[] = [
                        'role' => 'dessert', 'addon_name' => $o['label'],
                        'quantity' => $o['quantity'], 'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                    ];
                    break;

                case UberTicketOptionClassifier::BOISSON:
                    $formuleApresLesSauces = $formuleApresLesSauces || $sauceDuProduitVue;
                    $addons[] = [
                        'role' => 'drink', 'addon_name' => $o['label'],
                        'quantity' => $o['quantity'], 'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                    ];
                    break;

                case UberTicketOptionClassifier::MENU:
                    $formuleApresLesSauces = $formuleApresLesSauces || $sauceDuProduitVue;
                    // [relecture adverse 6] UNE formule par ligne : « FORMULE » puis « 1x Menu (Grande
                    // Frites + Boisson) » ne fait pas deux menus (le bain comptait 3F au lieu de 2F). La
                    // dernière lue, la plus précise (« Grande »), remplace la précédente.
                    $addons = array_values(array_filter($addons, static fn (array $a): bool => ($a['role'] ?? '') !== 'menu_full'));
                    $addons[] = [
                        'role' => 'menu_full', 'addon_name' => $this->menuAddonName($o['label']),
                        'quantity' => $o['quantity'], 'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                    ];
                    break;

                case UberTicketOptionClassifier::FRITES:
                    $formuleApresLesSauces = $formuleApresLesSauces || $sauceDuProduitVue;
                    // [2026-11-20] « Frites seules » est le CHOIX DE FORMULE de la
                    // carte Uber (seul / en menu / frites seules) : la sauce qui suit est celle des frites.
                    $formuleFritesChoisie = $formuleFritesChoisie || (bool) preg_match('/seule|avec/u', mb_strtolower($o['label']));
                    // Frites seules : la cuisine affiche « FRITES » (et non « MENU », qui lui
                    // ferait servir aussi la boisson — une fuite de marchandise), et le bandeau
                    // de cuisson compte la portion à plonger. Le nom conserve « Grande » : une
                    // grande frite vaut deux portions au bain.
                    $addons[] = [
                        'role' => 'menu_frites', 'addon_name' => $o['label'],
                        'quantity' => $o['quantity'], 'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                    ];
                    break;

                case UberTicketOptionClassifier::RETRAIT:
                    // [RETRAIT 2026-08-12] Un refus se lit EN TOUTES LETTRES, sur la ligne de
                    // note — jamais en symbole. On garde le texte BRUT (« Retirer : Tomate »),
                    // pas le libellé nettoyé (« Tomate ») : amputé de sa négation, il dirait
                    // exactement le contraire de ce que le client a demandé.
                    $notes[] = $o['raw'];
                    break;

                case UberTicketOptionClassifier::SAUCE_FRITES:
                    // Canal dédié : la cuisine la rend sur la ligne du menu (« MENU : KTP »),
                    // jamais dans la sauce du produit. Elle ne vit que dans le texte libre —
                    // c'est le contrat que lisent déjà le ticket et l'écran.
                    // « 2x Ketchup » = deux ketchups : la quantité compte (une seule était annoncée).
                    for ($i = 0; $i < $o['quantity']; $i++) {
                        $saucesFrites[] = $o['label'];
                    }
                    break;

                default:
                    $extras[] = [
                        'extra_name' => $o['label'], 'quantity' => $o['quantity'],
                        // Le montant appartient à Uber, qui a déjà encaissé : on recopie ce qui
                        // était écrit, zéro sinon. Un supplément à zéro reste bien affiché
                        // « + Cheddar » — seules les CRUDITÉS gratuites se replient dans « STO »,
                        // et le classifieur les a déjà rangées ailleurs.
                        'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                        'uber_supplement' => true,
                    ];
                    break;
            }
        }

        // [CARTE UBER 2026-08-12] Le titre annonce une FORMULE (« Menu sandwich Cayenne ») mais
        // le ticket Uber ne liste pas les frites — seulement la boisson. Sans cette ligne, deux
        // menus passaient au bandeau de cuisson sans AUCUNE frite à plonger. On ne l'ajoute que
        // si les options n'en ont pas déjà apporté une : le double comptage servirait deux
        // portions pour une vendue.
        if ($estMenu && ! $this->porteDejaUneFormule($addons)) {
            $addons[] = [
                'role' => 'menu_full', 'addon_name' => $this->menuAddonName($titreUber),
                'quantity' => 1, 'unit_price' => 0.0, 'line_total' => 0.0,
            ];
        }

        // Sauces revenues après la formule : celles des frites si la ligne est un vrai MENU ; sinon
        // elles restent au produit — on ne jette jamais une sauce. [relecture adverse 2] De simples
        // frites ne suffisent pas : un bol à base de frites garde sa sauce (« BASE BOL : Frites »).
        if ($saucesTardives !== []) {
            $roles = array_column($addons, 'role');
            // Un vrai menu : formule complète, ou des frites PLUS une boisson / un titre « Menu … ».
            $menu = in_array('menu_full', $roles, true)
                || (in_array('menu_frites', $roles, true) && ($estMenu || $formuleFritesChoisie || in_array('drink', $roles, true)));
            if ($menu) {
                array_push($saucesFrites, ...$saucesTardives);
            } else {
                foreach ($saucesTardives as $sauce) {
                    $nbSauces++;
                    $lines[] = ['attribute_name' => 'Sauce '.$nbSauces, 'variation_name' => $sauce];
                }
            }
        }

        // La sauce des frites s'écrit dans le format EXACT que lisent le ticket et l'écran
        // (`fritesSauceSymbol`) : « Sauce frites : Ketchup, Mayonnaise ». Un autre libellé ne
        // serait tout simplement pas vu.
        if ($saucesFrites !== []) {
            $notes[] = 'Sauce frites : '.implode(', ', $saucesFrites);
        }

        $note = trim((string) ($line['note'] ?? ''));
        if ($note !== '') {
            $notes[] = $this->safeNote($note);
        }
        if ($unmapped) {
            array_unshift($notes, '[UBER NON MAPPÉ: '.$title.']');
        }

        return [
            'unmapped' => $unmapped,
            'line' => [
                'item_id' => $itemId,
                'name' => $title,
                'quantity' => $quantity,
                // Les montants appartiennent à Uber (déjà encaissés, canal non fiscalisé) : on
                // n'en invente aucun à partir d'une photo. Le prix de vente interne reste à zéro.
                'unit_price' => 0.0,
                'total' => 0.0,
                'instruction' => trim(implode("\n", $notes)),
                'composition_snapshot' => [
                    'schema_version' => 1,
                    'source' => 'uber_eats_photo',
                    'lines' => $lines,
                    'extras' => $extras,
                    'addons' => $addons,
                    'uber_title' => $title,
                ],
            ],
        ];
    }

    /**
     * Le conteneur de menu porte le nom du catalogue quand la formule comprend des frites : c'est
     * ce nom que lisent le bandeau de cuisson et la ligne « MENU » de l'écran cuisine. On garde
     * cependant la mention « Grande » du ticket, parce qu'une grande frite compte double au bain.
     */
    /**
     * [CARTE UBER 2026-08-12] Retrouve l'article de NOTRE carte derrière le nom d'Uber.
     *
     * La carte Uber préfixe ses produits par leur RAYON : elle vend « Menu sandwich Cayenne » là
     * où notre catalogue s'appelle « Cayenne ». Le résolveur ne testait que le titre entier :
     * mesuré sur une vraie commande, 2 lignes sur 3 tombaient sur l'article bouche-trou, le ticket
     * imprimait « ART », aucune frite n'était comptée et aucun stock décompté.
     *
     * On retire donc les mots de tête un par un, en essayant à chaque fois le reste — donc le nom
     * le PLUS LONG d'abord. Cet ordre n'est pas un détail : « Menu galette Cayenne » doit trouver
     * « Galette Cayenne », pas « Cayenne ». Servir un sandwich à qui a commandé une galette serait
     * une erreur de plus, pas une correction.
     *
     * On ne descend jamais jusqu'au dernier mot seul en dessous de 3 caractères, et un titre déjà
     * reconnu tel quel n'est jamais réduit.
     *
     * @return array{0: int|null, 1: bool}  [id de l'article, le titre annonce-t-il une formule]
     */
    private function resoudreArticle(string $title): array
    {
        $estMenu = (bool) preg_match('/\b(menus?|formules?)\b/iu', $title);

        $direct = $this->catalog->resolveItemId($title);
        if ($direct !== null) {
            return [$direct, $estMenu];
        }

        // [2026-11-20] La carte Uber ajoute un slogan après une virgule ou un
        // tiret : « Sandwich Cayenne, Ultra GOURMAND et Original ». Le produit est AVANT.
        $avant = trim((string) preg_split('/\s*[,–—]\s*|\s+-\s+/u', $title)[0]);
        if ($avant !== '' && $avant !== trim($title)) {
            [$id] = $this->resoudreArticle($avant);
            if ($id !== null) {
                return [$id, $estMenu];
            }
        }

        $mots = preg_split('/\s+/u', trim($title)) ?: [];
        for ($i = 1; $i < count($mots); $i++) {
            $candidat = trim(implode(' ', array_slice($mots, $i)));
            if (mb_strlen($candidat) < 3) {
                break;
            }
            // [VRAIS TICKETS 2026-11-20 soir] « maison » seul trouvait « Sauce Fromagère maison » (recherche
            // « contient ») : le tiramisu partait en cuisine sous le nom d'une sauce. Un mot qui qualifie
            // sans nommer ne désigne jamais un produit.
            if (self::queDesQualificatifs($candidat)) {
                continue;
            }
            $id = $this->catalog->resolveItemId($candidat);
            if ($id !== null) {
                return [$id, $estMenu];
            }
        }

        // Le produit EN TÊTE, qualificatif derrière : « Tiramisu maison » → « Tiramisu ».
        for ($i = count($mots) - 1; $i >= 1; $i--) {
            $candidat = trim(implode(' ', array_slice($mots, 0, $i)));
            if (mb_strlen($candidat) < 3 || self::queDesQualificatifs($candidat)) {
                continue;
            }
            $id = $this->catalog->resolveItemId($candidat);
            if ($id !== null) {
                return [$id, $estMenu];
            }
        }

        return [null, $estMenu];
    }

    /**
     * [CARTE UBER 2026-08-12] Le nom porté par la ligne est celui de NOTRE carte, pas celui d'Uber.
     *
     * C'est ce nom qui produit le symbole lu en cuisine. Tant que la ligne gardait « Menu sandwich
     * Cayenne », le moteur en tirait « SAN » — un code qui ne désigne rien. Pire : l'écran de
     * validation lisait ce titre (« SAN ») pendant que le papier lisait le nom de l'ARTICLE
     * (« ART »), si bien que l'aperçu validé n'était pas ce que la cuisine recevait. Aligner les
     * deux sur le nom de la carte referme l'écart d'un seul geste.
     */
    private function nomDeLaCarte(?int $itemId, string $defaut): string
    {
        if ($itemId === null) {
            return $defaut;
        }

        // [AUDIT-5SYS 2026-08-12 P2 — WithoutGlobalScopesAuditSentinelTest] `withoutGlobalScopes()`
        // (pluriel) supprimait AUSSI le filtre de suppression douce sans le dire : un item retiré de
        // la carte (soft-deleted) aurait quand même affiché son vrai nom sur le ticket cuisine. Item
        // n'a pas de BranchScope (pas de portée par branche sur le catalogue), donc ce bypass est un
        // no-op sur le branch-fence — seul le SoftDeletingScope reste actif, comme voulu : un item
        // supprimé retombe sur `$defaut`, déjà géré juste en dessous.
        $nom = Item::query()->withoutGlobalScope(BranchScope::class)->whereKey($itemId)->value('name');

        return is_string($nom) && $nom !== '' ? $nom : $defaut;
    }

    /** Les options ont-elles déjà apporté une formule (menu complet ou frites) ? */
    private function porteDejaUneFormule(array $addons): bool
    {
        foreach ($addons as $a) {
            if (in_array($a['role'] ?? '', ['menu_full', 'menu_frites'], true)) {
                return true;
            }
        }

        return false;
    }

    private function menuAddonName(string $label): string
    {
        return preg_match('/\bgrande?\b|\bxl\b|\blarge\b/iu', $label)
            ? 'Menu (Grande Frites + Boisson)'
            : 'Menu (Frites + Boisson)';
    }

    /** Note client → canal SÛR : les crochets survivent aux nettoyeurs d'instruction. */
    private function safeNote(string $note): string
    {
        $note = trim($note);
        if ($note === '' || str_starts_with($note, '[')) {
            return $note;
        }

        return '['.$note.']';
    }

    /**
     * Numéro d'appel court, lisible par le cuisinier et le caissier. Préfixe « U » pour que
     * personne ne confonde une commande Uber avec un ticket de comptoir.
     */
    private function queueNumber(string $displayId): ?string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $displayId) ?? '';

        return $clean !== '' ? 'U'.mb_strtoupper(mb_substr($clean, -4)) : null;
    }

    /**
     * [VRAIS TICKETS 2026-11-20 soir] « FORMULE : Seul » (Terminator, commande réelle) = le client a pris
     * le produit SANS menu. Ce choix n'apporte rien à préparer : il ne s'imprime pas (il sortait en
     * supplément « Seul » sous un faux « MENU »).
     */
    private static function estChoixSansFormule(string $option): bool
    {
        $n = strtolower(trim((string) preg_replace('/[^a-z0-9:]+/i', ' ', \Illuminate\Support\Str::ascii($option))));
        $n = trim((string) preg_replace('/^\d{1,2}\s*x\s*/', '', $n));
        $n = trim((string) preg_replace('/^(formules?|menus?|seul ou en menu)\s*:\s*/', '', $n));

        return (bool) preg_match('/^(seule?s?|sans (menu|formule)|a l unite)$/', $n);
    }

    /** Mots qui qualifient un produit sans le nommer (« maison », « gourmand », « original »…). */
    private static function queDesQualificatifs(string $candidat): bool
    {
        $mots = preg_split('/\s+/', strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', ' ', \Illuminate\Support\Str::ascii($candidat))))) ?: [];
        $qualificatifs = ['maison', 'gourmand', 'gourmande', 'ultra', 'original', 'originale', 'classique', 'nouveau',
            'nouvelle', 'special', 'speciale', 'signature', 'extra', 'xl', 'maxi', 'et', 'le', 'la', 'les',
            'du', 'de', 'des', 'au', 'aux', 'a', 'avec', 'en', 'fait', 'faite', 'frais', 'fraiche', 'croustillant', 'fondant'];

        return array_diff(array_filter($mots, static fn (string $m): bool => $m !== ''), $qualificatifs) === [];
    }
}
