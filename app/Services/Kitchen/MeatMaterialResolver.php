<?php

namespace App\Services\Kitchen;

use App\Models\RawMaterial;
use Illuminate\Support\Facades\Log;

/**
 * [STOCK-VIANDE 2026-08-06 owner] PONT entre le bandeau de cuisson et le stock matière.
 *
 * POURQUOI
 * --------
 * Le moteur de consommation existant résout les matières depuis la FICHE PRODUIT. Or neuf
 * produits laissent le client choisir sa viande, et aucune ligne de recette n'existe par
 * variation : la viande décrémentée était donc toujours la même, quel que soit le choix.
 * Mesuré sur 30 jours : un Cayenne « Mixte (hachée + poulet) » retirait 200 g de poulet et
 * 0 g de hachée, et un Méga « Tenders + Cordon Bleu » ne retirait aucune viande.
 *
 * Ce résolveur fait de {@see MeatPortionCalculator} la SEULE voix sur la viande et les frites —
 * la même qui parle au cuisinier sur le ticket et l'écran. La cuisine et le stock ne peuvent
 * plus se contredire, puisqu'ils lisent le même calcul.
 *
 * COMPTER EN PIÈCES
 * -----------------
 * Une pièce se compte sans connaître son poids ; le poids ne sert qu'à convertir en grammes
 * pour le coût. Les viandes créées ici sont donc en `piece` : « aujourd'hui on a sorti 168
 * pièces de poulet mariné » est une réponse exacte, disponible immédiatement.
 * Les matières déjà en grammes (viande hachée, 75 g/pièce) sont converties via
 * `piece_weight_g`. Une matière en grammes SANS poids déclaré n'est PAS consommée à 0 en
 * silence : elle est ignorée et LOGGÉE, pour que le trou se voie.
 */
final class MeatMaterialResolver
{
    /**
     * Symbole cuisine → nom canonique de la matière première.
     * Les symboles viennent de MEAT_TABLE (KitchenTicketSymbolicFormatter), déjà en parité
     * verrouillée avec la jumelle JS — d'où l'absence de toute table de motifs ici.
     *
     * « Poulet mariné » est DISTINCT de la matière historique « Poulet » (vrac, en grammes,
     * sans poids unitaire) : ce sont deux objets physiques différents, et fusionner les deux
     * rendrait l'historique illisible.
     *
     * @var array<string, string>
     */
    public const SYMBOLE_VERS_MATIERE = [
        'K' => 'Viande hachée',
        'P' => 'Poulet mariné',
        'Mex' => 'Mexicanos',
        'Tender' => 'Tenders',
        'Nug' => 'Nuggets',
        'Frec' => 'Fricadelle',
        'Cordon' => 'Cordon bleu',
        'Chick' => 'Chicken burger',
        'Poi' => 'Poisson pané',
        'F' => 'Portion frites',
    ];

    /**
     * Matières à créer si absentes, comptées en PIÈCES (aucun poids requis pour compter).
     * Le poids unitaire reste à saisir par l'owner pour la valorisation en grammes/coût.
     *
     * @var array<int, string>
     */
    public const MATIERES_EN_PIECES = [
        'Poulet mariné', 'Mexicanos', 'Tenders', 'Nuggets', 'Fricadelle',
        'Chicken burger', 'Poisson pané',
    ];

    /** @var array<string, RawMaterial|null> cache par nom, pour ne pas requêter par ligne */
    private array $cache = [];

    /**
     * Convertit les pièces du bandeau de cuisson en quantités de matière consommables.
     *
     * @param  array<string, int>  $pieces  sortie de MeatPortionCalculator (symbole => pièces)
     * @return array{totals: array<int, float>, skipped: array<int, array{symbol: string, reason: string, pieces: int}>}
     */
    public function toMaterialQuantities(array $pieces, int $branchId = 1): array
    {
        $totals = [];
        $skipped = [];

        foreach ($pieces as $symbole => $n) {
            $n = (int) $n;
            if ($n <= 0) {
                continue;
            }

            // « ? » = supplément viande dont le nom n'a pas pu être récupéré. On ne devine pas
            // laquelle : la compter en hachée par défaut fabriquerait une consommation fausse.
            // Ce cas est déjà pris en charge par la ligne de recette de GROUPE côté extras (la
            // moyenne historique) et y est déjà signalé — le rapporter ici aussi ferait remonter
            // deux fois le même événement, ce qui rend une télémétrie inutilisable.
            if ($symbole === '?') {
                continue;
            }

            if (! isset(self::SYMBOLE_VERS_MATIERE[$symbole])) {
                $skipped[] = ['symbol' => $symbole, 'reason' => 'symbole_non_mappe', 'pieces' => $n];
                continue;
            }

            $matiere = $this->matiere(self::SYMBOLE_VERS_MATIERE[$symbole], $branchId);
            if ($matiere === null) {
                $skipped[] = ['symbol' => $symbole, 'reason' => 'matiere_absente', 'pieces' => $n];
                continue;
            }

            $qty = $this->quantitePour($matiere, $n);
            if ($qty === null) {
                // Matière en grammes sans poids unitaire : consommer 0 en silence serait pire
                // que ne rien faire — le trou doit rester visible.
                $skipped[] = ['symbol' => $symbole, 'reason' => 'poids_unitaire_absent', 'pieces' => $n];
                Log::info('[MeatMaterialResolver] poids unitaire manquant — matière non consommée', [
                    'raw_material_id' => (int) $matiere->id,
                    'name' => $matiere->name,
                    'pieces' => $n,
                ]);
                continue;
            }

            $totals[(int) $matiere->id] = ($totals[(int) $matiere->id] ?? 0.0) + $qty;
        }

        return ['totals' => $totals, 'skipped' => $skipped];
    }

    /**
     * Quantité à consommer pour N pièces, dans l'unité de la matière.
     * Retourne null si la conversion est impossible (grammes sans poids unitaire).
     */
    private function quantitePour(RawMaterial $matiere, int $pieces): ?float
    {
        $unit = mb_strtolower(trim((string) $matiere->unit));

        if ($unit === 'g' || $unit === 'kg' || $unit === 'gramme' || $unit === 'grammes') {
            $poids = (float) ($matiere->piece_weight_g ?? 0);
            if ($poids <= 0) {
                return null;
            }
            $grammes = $pieces * $poids;

            return $unit === 'kg' ? $grammes / 1000 : $grammes;
        }

        // piece / tranche / portion… : une pièce vaut une unité.
        return (float) $pieces;
    }

    /** Matière par nom, insensible à la casse et aux accents, mise en cache. */
    private function matiere(string $nom, int $branchId): ?RawMaterial
    {
        $cle = mb_strtolower($nom);
        if (array_key_exists($cle, $this->cache)) {
            return $this->cache[$cle];
        }

        return $this->cache[$cle] = RawMaterial::query()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(name) = ?', [$cle])
            ->first();
    }
}
