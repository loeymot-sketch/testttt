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
 * Les matières déjà en grammes (viande hachée 75 g/steak, poulet mariné 100 g/pièce) sont
 * converties via `piece_weight_g` — le poids d'UNE UNITÉ COMPTÉE, jamais celui d'une portion
 * servie (voir l'avertissement sur MATIERES_A_CREER). Une matière en grammes SANS poids déclaré n'est PAS consommée à 0 en
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
     * Matières à créer si absentes : nom => [unité, poids d'UNE unité comptée].
     *
     * La plupart sont comptées en PIÈCES — une pièce se compte sans connaître son poids, et la
     * question de l'owner (« combien on a vraiment sorti ») trouve alors une réponse exacte
     * tout de suite. Le poids ne sert qu'à valoriser en grammes.
     *
     * Le POULET fait exception : il est vendu au poids, donc l'unité est le GRAMME et la
     * conversion doit être connue.
     *
     * ⚠️ [OWNER 2026-08-19] CE POIDS SUIT L'UNITÉ COMPTÉE, IL NE DÉCRIT PAS LA PORTION SERVIE.
     * Le propriétaire a demandé que le bandeau de cuisson DOUBLE le poulet — il s'affichait en
     * portions de 200 g, seule viande de la table à valoir 1 quand toutes les autres valent 2,
     * ce qui produisait les « 0,5P » qu'il a signalés. `MeatPortionCalculator` compte donc
     * désormais le poulet en DEMI-PORTIONS, et le poids d'UNE unité comptée passe mécaniquement
     * de 200 g à 100 g.
     *
     * Le résultat physique est INCHANGÉ, et c'est tout l'intérêt de bouger les deux ensemble :
     *     Cayenne poulet   1P × 200 g    ->   2P × 100 g   = 200 g   (identique)
     *     Méga mixte       0,5P × 200 g  ->   1P × 100 g   = 100 g   (identique)
     *     supplément       1P × 200 g    ->   2P × 100 g   = 200 g   (identique)
     * Le SEUL déplacement voulu est celui que l'owner a demandé le même jour : un BOL passe
     * d'une portion pleine à une demi (200 g -> 100 g de poulet, 2 -> 1 cordon bleu).
     *
     * Toucher ce poids SANS toucher la table des portions — ou l'inverse — diviserait ou
     * doublerait la consommation réelle de poulet, en silence. Les deux vont ensemble.
     * `php artisan stock:ensure-meat-materials` réaligne les matières déjà en base, et la
     * migration `2026_08_19_190000_align_poulet_marine_piece_weight` le fait au déploiement.
     *
     * @var array<string, array{0:string, 1:float|null}>
     */
    public const MATIERES_A_CREER = [
        'Poulet mariné' => ['g', 100.0],
        'Mexicanos' => ['piece', null],
        'Tenders' => ['piece', null],
        'Nuggets' => ['piece', null],
        'Fricadelle' => ['piece', null],
        'Chicken burger' => ['piece', null],
        'Poisson pané' => ['piece', null],
    ];

    /** @var array<string, RawMaterial|null> cache par nom, pour ne pas requêter par ligne */
    private array $cache = [];

    /**
     * Convertit les pièces du bandeau de cuisson en quantités de matière consommables.
     *
     * @param  array<string, float>  $pieces  sortie de MeatPortionCalculator (symbole => unités
     *                                          comptées, éventuellement fractionnaires)
     * @return array{totals: array<int, float>, skipped: array<int, array{symbol: string, reason: string, pieces: float}>}
     */
    public function toMaterialQuantities(array $pieces, int $branchId = 1): array
    {
        $totals = [];
        $skipped = [];

        foreach ($pieces as $symbole => $n) {
            // (float) et NON (int) : depuis que le poulet se compte en portions, une DEMI-portion
            // vaut 0,5 — un cast entier l'écrasait à 0 et faisait disparaître du stock, en
            // silence, tout le poulet des sandwichs mixtes.
            $n = (float) $n;
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
                // [CUISSON fail-loud 2026-08-07 · audit R1 P2] Une viande NON MAPPÉE consomme
                // ZÉRO stock EN SILENCE : l'owner a ajouté une viande sans la câbler dans les 3
                // tables (MEAT_TABLE, SYMBOLE_VERS_MATIERE, VIANDES_PILOTEES). Sans ce warning le
                // trou de stock restait invisible (juste dans skipped[]) → food-cost faussé.
                Log::warning('[MeatMaterialResolver] symbole viande NON MAPPÉ — stock NON consommé', [
                    'symbol' => $symbole,
                    'pieces' => $n,
                    'branch_id' => $branchId,
                    'hint' => 'Câbler le symbole dans SYMBOLE_VERS_MATIERE + créer la matière + son poids unitaire.',
                ]);

                continue;
            }

            $matiere = $this->matiere(self::SYMBOLE_VERS_MATIERE[$symbole], $branchId);
            if ($matiere === null) {
                $skipped[] = ['symbol' => $symbole, 'reason' => 'matiere_absente', 'pieces' => $n];
                // [CUISSON fail-loud 2026-08-07] Symbole mappé mais matière première absente en base
                // pour cette branche → même trou de stock silencieux. On le rend visible.
                Log::warning('[MeatMaterialResolver] matière première ABSENTE — stock NON consommé', [
                    'symbol' => $symbole,
                    'materiel' => self::SYMBOLE_VERS_MATIERE[$symbole],
                    'pieces' => $n,
                    'branch_id' => $branchId,
                ]);

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
     * Quantité à consommer pour N unités comptées, dans l'unité de la matière. N peut être
     * FRACTIONNAIRE (une demi-portion de poulet = 0,5 → 100 g).
     * Retourne null si la conversion est impossible (grammes sans poids unitaire).
     */
    private function quantitePour(RawMaterial $matiere, float $pieces): ?float
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
