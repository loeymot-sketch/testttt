<?php

namespace App\Services\Uber;

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
 *   boissons                     → `addons`  → « 1 Coca-Cola 33cl »
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

        $itemId = $this->catalog->resolveItemId($title);
        $unmapped = ($itemId === null);
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

        foreach ((array) ($line['options'] ?? []) as $option) {
            $option = trim((string) $option);
            if ($option === '') {
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

                case UberTicketOptionClassifier::BOISSON:
                    $addons[] = [
                        'role' => 'drink', 'addon_name' => $o['label'],
                        'quantity' => $o['quantity'], 'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                    ];
                    break;

                case UberTicketOptionClassifier::MENU:
                    $addons[] = [
                        'role' => 'menu_full', 'addon_name' => $this->menuAddonName($o['label']),
                        'quantity' => $o['quantity'], 'unit_price' => $o['price'],
                        'line_total' => $o['price'] * $o['quantity'],
                    ];
                    break;

                case UberTicketOptionClassifier::FRITES:
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
                    $saucesFrites[] = $o['label'];
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
}
