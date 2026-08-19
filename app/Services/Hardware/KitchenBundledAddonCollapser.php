<?php

namespace App\Services\Hardware;

/**
 * [T-KDS-MENU-DOUBLON 2026-08-19 · GOAL owner] Repli des lignes de formule déjà
 * décrites sous leur produit parent, pour le TICKET CUISINE imprimé.
 *
 * JUMEAU STRICT de `resources/js/helpers/kdsBundledAddons.js` (écran cuisine).
 * Les deux surfaces sont documentées comme jumelles : toute évolution de l'une
 * doit être répercutée sur l'autre, sinon l'écran et le papier divergent.
 *
 * LE PROBLÈME
 * -----------
 * Une formule ajoutée à un produit est enregistrée DEUX FOIS :
 *   · dans l'`instruction` du produit parent (« + Menu (Frites + Boisson)
 *     (+2,50 €) / ↳ Sauce frites: Mayonnaise ») ;
 *   · comme `order_item` distinct portant le PRIX de la formule.
 *
 * Le ticket réellement imprimé pour la commande 6598 (avant correctif) :
 *
 *     S | CAY | P | ST | ALG
 *       FRITES : MAY               <- la sauce frites, sur le sandwich
 *       ** BOISSON: Coca-Cola 33cl
 *     MENU : MAY                   <- LA MÊME, une seconde fois
 *
 * `OrderReceiptEscPosRenderer` annonçait déjà en commentaire vouloir fusionner
 * la formule dans le bloc de son produit, mais y renonçait faute de lien fiable
 * (« Pas de fusion devinée : le menu n'est pas forcément adjacent à son
 * produit »). Ce lien existe pourtant : le parent revendique lui-même
 * « + <nom de la formule> », écrit par le wizard. On ne devine plus, on lit.
 *
 * PORTÉE
 * ------
 * Filtre d'AFFICHAGE uniquement. Aucune écriture, aucun effet sur le prix, la
 * TVA, `composition_snapshot` ou la chaîne fiscale : la ligne comptable de la
 * formule continue d'exister en base, elle n'est simplement plus réimprimée.
 *
 * SÛRETÉ
 * ------
 * On ne replie QUE ce qu'un parent revendique, et seulement à hauteur de la
 * quantité revendiquée. Une formule commandée SEULE reste toujours imprimée —
 * la masquer signifierait que la cuisine ne la prépare pas.
 */
class KitchenBundledAddonCollapser
{
    /**
     * @param  iterable<object>  $items  Lignes de commande (OrderItem ou équivalent)
     * @return array<int, object>        Nouvelle liste ; les lignes dont la quantité
     *                                   est réduite sont des CLONES, jamais la source.
     */
    public function collapse(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item;
        }

        if ($rows === []) {
            return [];
        }

        // 1. Recenser ce que chaque ligne revendique, en quantité.
        //    Une ligne ne se revendique JAMAIS elle-même (anti-auto-suppression).
        $quota = [];
        foreach ($rows as $item) {
            $ownName = $this->normalize($this->nameOf($item));
            $qty = $this->quantityOf($item);

            foreach ($this->claimedAddonNames($this->instructionOf($item)) as $claimed) {
                if ($claimed === $ownName) {
                    continue;
                }
                $quota[$claimed] = ($quota[$claimed] ?? 0) + $qty;
            }
        }

        if ($quota === []) {
            return $rows;
        }

        // 2. Consommer le quota sur les lignes correspondantes.
        $out = [];
        foreach ($rows as $item) {
            $name = $this->normalize($this->nameOf($item));
            $remaining = $quota[$name] ?? 0;

            if ($remaining <= 0) {
                $out[] = $item;
                continue;
            }

            $qty = $this->quantityOf($item);
            $consumed = min($remaining, $qty);
            $quota[$name] = $remaining - $consumed;

            $left = $qty - $consumed;
            if ($left <= 0) {
                continue; // entièrement décrite par son parent → repliée
            }

            $clone = clone $item;
            $clone->quantity = $left;
            $out[] = $clone;
        }

        return $out;
    }

    /**
     * Noms de formules revendiqués par une instruction.
     *
     * Le wizard écrit « + Menu (Frites + Boisson) (+2,50 €) ». On retire la
     * parenthèse de PRIX finale (celle qui commence par « + ») sans toucher au
     * nom, qui peut lui-même contenir des parenthèses.
     *
     * @return array<int, string> noms normalisés
     */
    private function claimedAddonNames(string $instruction): array
    {
        // [RED-TEAM 2026-08-19] N'examiner QUE la partie composée par le wizard.
        // La note libre du caissier est toujours écrite EN DERNIER, entre crochets
        // (`pos-wizard.js` : `extraLines.push('[' . instructionText . ']')`), et c'est
        // un `<textarea>` : elle peut donc contenir des retours à la ligne et des lignes
        // commençant par « + ». Sans cette coupe, une note telle que
        //     + Frites
        //     Merci
        // sur un sandwich faisait DISPARAÎTRE du ticket cuisine la vraie ligne « Frites »
        // commandée à côté — facturée, jamais préparée. On tronque au premier crochet.
        // Jumeau strict : resources/js/helpers/kdsBundledAddons.js.
        $bracket = mb_strpos($instruction, '[');
        if ($bracket !== false) {
            $instruction = mb_substr($instruction, 0, $bracket);
        }

        $names = [];

        foreach (preg_split('/\R/u', $instruction) ?: [] as $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '' || ! str_starts_with($line, '+')) {
                continue;
            }

            $withoutPlus = trim(mb_substr($line, 1));
            $withoutPrice = trim((string) preg_replace('/\s*\(\s*\+[^()]*\)\s*$/u', '', $withoutPlus));
            if ($withoutPrice !== '') {
                $names[] = $this->normalize($withoutPrice);
            }
        }

        return $names;
    }

    /** Jumeau de normalizeLabel() côté JS : sans accent, sans casse, espaces réduits. */
    private function normalize(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($ascii === false) {
            $ascii = $value;
        }
        // iconv//TRANSLIT peut produire « 'e » ou « ~n » selon la locale : on ne
        // garde que lettres, chiffres et séparateurs utiles.
        $ascii = preg_replace('/[^\p{L}\p{N}\s()+\-]/u', '', $ascii) ?? $ascii;

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $ascii)));
    }

    private function nameOf(object $item): string
    {
        $name = $item->name ?? null;
        if (! is_string($name) || $name === '') {
            $name = optional($item->orderItem ?? null)->name;
        }

        return is_string($name) ? $name : '';
    }

    private function quantityOf(object $item): int
    {
        return max(1, (int) ($item->quantity ?? 1));
    }

    private function instructionOf(object $item): string
    {
        $instruction = $item->instruction ?? '';

        return is_string($instruction) ? $instruction : '';
    }
}
