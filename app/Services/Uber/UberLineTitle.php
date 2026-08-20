<?php

namespace App\Services\Uber;

/**
 * [UBER TITRE ENTIER 2026-08-20 · owner] Ce que le ticket Uber disait, quand notre carte n'a rien
 * reconnu.
 *
 * POURQUOI CETTE CLASSE EXISTE
 * ----------------------------
 * `order_items` n'a AUCUNE colonne de nom : toutes les surfaces (ticket imprimé, écran de cuisine,
 * liste « commandes en cours » de la caisse) résolvent le libellé par la relation `item`. Une
 * ligne Uber que la carte n'a pas reconnue est ancrée sur l'article TECHNIQUE « Article Uber (non
 * mappé) » — elles affichaient donc toutes ce nom-là, dont le moteur symbolique tire « ART », un
 * code qui ne désigne rien. L'owner l'a mesuré sur chaque ticket scanné : « chaque fois ça donne
 * ART ».
 *
 * Le titre réel ne vit qu'à UN endroit : le `composition_snapshot`, scellé à la création. Trois
 * appelants doivent le lire ; sans point unique, la règle aurait divergé au premier changement —
 * et c'est exactement l'écart aperçu↔cuisine qui avait déjà coûté une passe le 2026-08-12.
 *
 * Jumeau JS : resources/js/helpers/kdsSymbolic.js uberUnmappedTitle().
 */
final class UberLineTitle
{
    /**
     * Le titre RECOPIÉ du ticket, uniquement si la ligne n'a pas trouvé la carte. Null sinon —
     * une ligne reconnue porte le nom de NOTRE carte, et c'est lui que la cuisine doit lire.
     *
     * Accepte la forme brute (colonne JSON) comme la forme castée (array) : les ressources reçoivent
     * l'une ou l'autre selon le chemin de chargement, et un `??` sur la mauvaise forme rendrait
     * simplement null — un retour à « ART » parfaitement silencieux.
     */
    public static function unmapped(mixed $snapshot): ?string
    {
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        if (! is_array($snapshot) || ($snapshot['uber_unmapped'] ?? false) !== true) {
            return null;
        }

        $titre = trim((string) ($snapshot['uber_title'] ?? ''));

        return $titre !== '' ? $titre : null;
    }
}
