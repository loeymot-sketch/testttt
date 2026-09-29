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
    /** Puce des options de formule écrite par le wizard (« ↳ Sauce frites: … »). */
    private const PUCE_OPTION = "\u{21b3}";

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
        //    On mémorise AUSSI l'index du parent revendiquant — une entrée par unité —
        //    parce qu'un repli n'est pas une suppression : la ligne repliée doit LÉGUER
        //    ses consignes de cuisine à celui qui la revendique (§3).
        $quota = [];
        $claimers = [];
        foreach ($rows as $index => $item) {
            $ownName = self::normalize($this->nameOf($item));
            $qty = $this->quantityOf($item);

            foreach (self::claimedAddonNames($this->instructionOf($item)) as $claimed) {
                if ($claimed === $ownName) {
                    continue;
                }
                $quota[$claimed] = ($quota[$claimed] ?? 0) + $qty;
                for ($k = 0; $k < $qty; $k++) {
                    $claimers[$claimed][] = $index;
                }
            }
        }

        if ($quota === []) {
            return $rows;
        }

        // 2. Consommer le quota sur les lignes correspondantes.
        $out = [];   // index de ligne d'origine => objet à rendre (l'ordre est conservé)
        $legs = [];  // index du parent         => consignes héritées de la ligne repliée
        foreach ($rows as $index => $item) {
            $name = self::normalize($this->nameOf($item));
            $remaining = $quota[$name] ?? 0;

            if ($remaining <= 0) {
                $out[$index] = $item;
                continue;
            }

            $qty = $this->quantityOf($item);
            $consumed = min($remaining, $qty);
            $quota[$name] = $remaining - $consumed;

            // 3. LEGS — [OWNER 2026-08-19, 2ᵉ passe] Le repli initial DÉTRUISAIT ce que la
            //    ligne de formule était SEULE à porter. Mesuré en base avant correctif :
            //    5 formules dont la « Sauce frites : … » n'existe QUE là (commande 5544 :
            //    Andalouse), et 17 parents qui revendiquent un menu sans porter eux-mêmes
            //    la moindre consigne — leur badge tombait donc à VIDE : plus de « MENU »,
            //    plus de boisson, la cuisine ne préparait plus la formule. On ne jette
            //    plus : on transmet au parent, qui les affiche dans SON bloc.
            // [AUDIT-SUPERVISEUR 2026-08-25 · E-009] Les EXTRAS de la ligne repliée étaient
            // perdus eux aussi — sur le TICKET IMPRIMÉ comme sur l'écran. Un supplément
            // facturé ne peut pas être effacé par un repli d'AFFICHAGE : le repli existe
            // pour alléger la lecture, pas pour retirer de l'information payée.
            // Jumeau strict du correctif JS `kdsBundledAddons.js`.
            $consignes = $this->kitchenDirectives($this->instructionOf($item));
            $extrasLegues = $this->extrasDeLaLigne($item);
            for ($k = 0; $k < $consumed; $k++) {
                $parent = array_shift($claimers[$name]);
                if ($parent === null) {
                    continue;
                }
                if ($consignes === [] && $extrasLegues === []) {
                    continue;
                }
                foreach ($consignes as $ligne) {
                    $legs[$parent]['consignes'][] = $ligne;
                }
                foreach ($extrasLegues as $extra) {
                    $legs[$parent]['extras'][] = $extra;
                }
            }

            $left = $qty - $consumed;
            if ($left <= 0) {
                continue; // entièrement décrite par son parent → repliée
            }

            $clone = clone $item;
            $clone->quantity = $left;
            $out[$index] = $clone;
        }

        // 4. Appliquer les legs sur des CLONES — jamais sur le modèle source, qui reste la
        //    ligne comptable intacte (aucune écriture, aucun effet prix/TVA/fiscal).
        foreach ($legs as $parentIndex => $legue) {
            if (! isset($out[$parentIndex])) {
                continue;
            }
            $parent = $out[$parentIndex];
            $clone = null;

            // 4a. Consignes d'instruction (comportement d'origine, inchangé).
            $instruction = $this->instructionOf($parent);
            $deja = [];
            foreach ($this->kitchenDirectives($instruction) as $existante) {
                $deja[$this->directiveKey($existante)] = true;
            }

            $ajouts = [];
            foreach (($legue['consignes'] ?? []) as $ligne) {
                $cle = $this->directiveKey($ligne);
                if (isset($deja[$cle])) {
                    continue;
                }
                $deja[$cle] = true;
                $ajouts[] = $ligne;
            }
            if ($ajouts !== []) {
                $clone = clone $parent;
                $clone->instruction = $this->appendDirectives($instruction, $ajouts);
            }

            // 4b. [E-009] Extras hérités, écrits dans la source que le rendu LIRA :
            // l'instantané NF525 quand il porte déjà des extras, l'ancienne colonne sinon.
            $extras = $legue['extras'] ?? [];
            if ($extras !== []) {
                $clone = $this->avecExtrasHerites($clone ?? clone $parent, $extras);
            }

            if ($clone !== null) {
                $out[$parentIndex] = $clone;
            }
        }

        return array_values($out);
    }

    /**
     * [AUDIT-SUPERVISEUR 2026-08-25 · E-009] Extras d'une ligne, lus avec la MÊME
     * priorité que le rendu : l'instantané NF525 d'abord, l'ancienne colonne ensuite.
     *
     * @return array<int, mixed>
     */
    private function extrasDeLaLigne(mixed $item): array
    {
        $snap = $item->composition_snapshot ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        if (is_array($snap) && isset($snap['extras']) && is_array($snap['extras']) && $snap['extras'] !== []) {
            return array_values($snap['extras']);
        }

        $legacy = $item->item_extras ?? null;
        if (is_string($legacy)) {
            $legacy = json_decode($legacy, true);
        }

        return is_array($legacy) ? array_values($legacy) : [];
    }

    /** Clé de dédoublonnage d'un extra : son nom, quelle que soit la forme qui le porte. */
    private function extraKey(mixed $extra): string
    {
        $nom = '';
        foreach (['extra_name', 'name', 'item_name'] as $cle) {
            $candidat = trim((string) (is_array($extra) ? ($extra[$cle] ?? '') : ($extra->$cle ?? '')));
            if ($candidat !== '') {
                $nom = mb_strtolower($candidat);
                break;
            }
        }

        return $nom !== '' ? $nom : json_encode($extra);
    }

    /**
     * Clone portant ses extras + ceux hérités d'une ligne repliée, écrits dans la source
     * que le rendu lira réellement. Jamais de mutation du modèle source.
     *
     * @param  array<int, mixed>  $herites
     */
    private function avecExtrasHerites(mixed $parent, array $herites): mixed
    {
        $snap = $parent->composition_snapshot ?? null;
        if (is_string($snap)) {
            $snap = json_decode($snap, true);
        }
        $snapPorte = is_array($snap) && isset($snap['extras']) && is_array($snap['extras']) && $snap['extras'] !== [];

        $actuels = $snapPorte ? array_values($snap['extras']) : $this->extrasDeLaLigne($parent);
        if (! $snapPorte) {
            $legacy = $parent->item_extras ?? null;
            if (is_string($legacy)) {
                $legacy = json_decode($legacy, true);
            }
            $actuels = is_array($legacy) ? array_values($legacy) : [];
        }

        $vus = [];
        foreach ($actuels as $e) {
            $vus[$this->extraKey($e)] = true;
        }

        $ajouts = [];
        foreach ($herites as $e) {
            $cle = $this->extraKey($e);
            if (isset($vus[$cle])) {
                continue;
            }
            $vus[$cle] = true;
            $ajouts[] = $e;
        }
        if ($ajouts === []) {
            return $parent;
        }

        $fusionnes = array_merge($actuels, $ajouts);

        if ($snapPorte) {
            $snap['extras'] = $fusionnes;
            // Mutation EN MÉMOIRE, sur un clone, jamais persistée. `$parent` arrive toujours
            // par `$clone ?? clone $parent` (voir collapse()), et ce service n'écrit rien en
            // base : aucun ->save(), ->update(), DB:: ni ::query(). L'instantané fiscal de la
            // commande n'est donc pas touché — on ne fabrique ici que la vue du ticket cuisine.
            // NF525 : `composition_snapshot` reste figé à la création (CLAUDE.md §8).
            $parent->composition_snapshot = $snap;
        } else {
            $parent->item_extras = json_encode($fusionnes);
        }

        return $parent;
    }

    /**
     * Consignes de CUISINE portées par une instruction — celles qu'un repli ne doit
     * jamais faire disparaître : les options de formule (lignes « ↳ »), la sauce des
     * frites et la boisson incluse. Tout le reste (nom du produit en tête, note libre
     * entre crochets) appartient à la ligne repliée et n'a pas à migrer.
     *
     * Jumeau strict : resources/js/helpers/kdsBundledAddons.js kitchenDirectives().
     *
     * @return array<int, string>
     */
    private function kitchenDirectives(string $instruction): array
    {
        $bracket = mb_strpos($instruction, '[');
        if ($bracket !== false) {
            $instruction = mb_substr($instruction, 0, $bracket);
        }

        $out = [];
        foreach (preg_split('/\R/u', $instruction) ?: [] as $rawLine) {
            $line = trim((string) $rawLine);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, self::PUCE_OPTION)
                || preg_match('/^boisson\s*:/iu', $line)
                || preg_match('/sauce\s*frites\s*:/iu', $line)
            ) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Clé d'unicité d'une consigne : deux « Sauce frites : … » ne coexistent jamais sur un
     * même bloc (seule la première est lue à l'affichage — la seconde ne ferait que du bruit).
     */
    private function directiveKey(string $ligne): string
    {
        if (preg_match('/sauce\s*frites\s*:/iu', $ligne)) {
            return 'sauce-frites';
        }

        return self::normalize($ligne);
    }

    /**
     * Insère les consignes héritées AVANT la note libre du caissier, qui doit rester la
     * DERNIÈRE ligne : les deux surfaces tronquent au premier crochet pour l'ignorer.
     *
     * @param  array<int, string>  $ajouts
     */
    private function appendDirectives(string $instruction, array $ajouts): string
    {
        $bracket = mb_strpos($instruction, '[');
        $tete = $bracket === false ? $instruction : mb_substr($instruction, 0, $bracket);
        $note = $bracket === false ? '' : mb_substr($instruction, $bracket);

        $tete = rtrim($tete, "\r\n");
        $bloc = implode("\n", $ajouts);
        $fusion = $tete === '' ? $bloc : $tete."\n".$bloc;

        return $note === '' ? $fusion : $fusion."\n".$note;
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
    public static function claimedAddonNames(string $instruction): array
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
                $names[] = self::normalize($withoutPrice);
            }
        }

        return $names;
    }

    /** Jumeau de normalizeLabel() côté JS : sans accent, sans casse, espaces réduits. */
    private static function normalize(string $value): string
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
