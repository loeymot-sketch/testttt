<?php

namespace App\Services\Uber;

use App\Services\Hardware\KitchenTicketSymbolicFormatter;
use App\Services\Kitchen\MeatPortionCalculator;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Rend, AVANT envoi, ce que la cuisine verra RÉELLEMENT.
 *
 * POURQUOI CET APERÇU EXISTE
 * --------------------------
 * Une photo lue par un modèle peut se tromper. L'humain qui valide doit donc pouvoir comparer,
 * en une seconde, sa photo et le résultat — mais le résultat qui compte n'est pas le texte brut
 * lu : c'est la LIGNE SYMBOLIQUE que le cuisinier lira (« G | TAC | P | STO | ALG ») et le
 * bandeau de cuisson (« 2K 1F »). Montrer autre chose donnerait une fausse confiance.
 *
 * L'aperçu est donc calculé par les MÊMES services que le ticket imprimé et l'écran de cuisine —
 * jamais par une reconstitution approchée. Si l'aperçu est juste, le ticket l'est aussi ; s'il
 * est faux, l'humain le voit avant que la cuisine ne travaille.
 */
final class UberTicketPreviewBuilder
{
    public function __construct(
        private readonly KitchenTicketSymbolicFormatter $formatter = new KitchenTicketSymbolicFormatter,
        private readonly MeatPortionCalculator $portions = new MeatPortionCalculator,
    ) {
    }

    /**
     * @param  array{items?:array<int,array>}  $mapped  sortie de {@see UberPhotoOrderMapper::map()}
     * [SAUCES FRITES UBER 2026-11-20 · owner] Comme le ticket imprimé : les boissons et les desserts ne
     * sont plus sous chaque produit mais dans `en_bas` (« pour qu'on n'oublie pas »), et une boisson
     * ou un dessert commandé seul porte `en_bas: true`. L'ordre de `lignes` ne bouge pas : l'écran
     * retire une ligne par son INDEX, qui doit rester celui de la commande lue.
     *
     * @return array{cuisson:string, en_bas:list<string>, lignes:array<int,array{quantity:int,titre:string,symbolique:string,menu:string,supplements:array<int,string>,boissons:array<int,string>,note:string,non_mappe:bool,en_bas:bool,doublon_de:?string}>}
     */
    public function build(array $mapped): array
    {
        $lignes = [];
        $enBas = [];
        $cles = [];

        foreach ((array) ($mapped['items'] ?? []) as $line) {
            $nom = (string) ($line['name'] ?? '');
            $snapshot = (array) ($line['composition_snapshot'] ?? []);
            $instruction = (string) ($line['instruction'] ?? '');

            $boissons = $this->formatter->drinkLines($snapshot);
            $supplements = $this->formatter->supplementLines($snapshot, $instruction);
            $quantite = max(1, (int) ($line['quantity'] ?? 1));
            $basDeLaLigne = $this->formatter->boissonsEtDessertsUber($snapshot, $quantite);
            array_push($enBas, ...$basDeLaLigne);
            $seule = $this->formatter->isDrinkItem($nom) || $this->formatter->isDessertItem($nom);

            $lignes[] = [
                'quantity' => $quantite,
                'titre' => $nom,
                // En bas du ticket, une boisson ou un dessert seul s'écrit en entier (« Tiramisu »).
                'symbolique' => $seule ? $nom : $this->formatter->mainLine($nom, $snapshot, $instruction),
                // Le BADGE, pas la simple ligne de menu : c'est lui qui sera imprimé, sauce des
                // frites comprise. Un aperçu qui montrerait autre chose donnerait une fausse
                // confiance à la personne qui valide.
                'menu' => $this->formatter->menuBadge($snapshot, $nom, $instruction),
                'supplements' => $supplements,
                // Imprimées en bas (voir `en_bas`) : plus jamais sous le produit.
                'boissons' => [],
                // Ce que CETTE ligne envoie en bas : l'écran recalcule la rubrique quand une ligne
                // est retirée (sinon le Coca d'un menu retiré resterait annoncé).
                'boissons_en_bas' => $basDeLaLigne,
                // La note passe par le MÊME nettoyeur que le ticket : ce qui n'y survivrait pas
                // ne doit pas apparaître dans l'aperçu, sinon l'humain croit avoir transmis une
                // consigne qui n'arrivera jamais en cuisine.
                // [GOAL REMARQUES 2026-10-03 · revue F6] … y compris les deux passes ajoutées au ticket :
                // option déjà affichée en supplément, marqueur Uber (le titre EST la ligne produit).
                'note' => $this->formatter->sansMarqueurUber($this->formatter->sansOptionsDejaAffichees(
                    $this->formatter->cleanInstruction($instruction, $nom, $boissons),
                    $supplements
                )),
                'non_mappe' => str_contains($instruction, '[UBER NON MAPPÉ'),
                'en_bas' => $seule,
                'doublon_de' => null,
            ];
            $cles[] = [
                'seule' => $this->formatter->isDrinkItem($nom) ? $this->cleBoisson($nom) : null,
                'menu' => array_values(array_filter(array_map(
                    fn (string $b): ?array => $this->cleBoisson($b),
                    $this->boissonsDuProduit($snapshot)
                ))),
            ];
        }

        // [DOUBLON BOISSON 2026-11-20 · owner] La lecture a déjà rendu la boisson d'un menu une 2ᵉ fois,
        // comme article à part (ticket Méga réel). Sans le papier, impossible de savoir si le client en
        // a payé une seconde : on SIGNALE, on ne retire jamais. L'humain tranche en un appui.
        foreach ($cles as $i => $c) {
            if ($c['seule'] === null) {
                continue;
            }
            foreach ($cles as $j => $autre) {
                if ($j !== $i && $this->memeBoisson($c['seule'], $autre['menu'])) {
                    $lignes[$i]['doublon_de'] = $lignes[$j]['titre'];
                    break;
                }
            }
        }

        $cuisson = $this->portions->forOrder(array_map(static fn (array $l): array => [
            'name' => (string) ($l['name'] ?? ''),
            'snapshot' => (array) ($l['composition_snapshot'] ?? []),
            'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
            'instruction' => (string) ($l['instruction'] ?? ''),
        ], (array) ($mapped['items'] ?? [])));

        return ['cuisson' => $cuisson['texte'], 'en_bas' => $enBas, 'lignes' => $lignes];
    }

    /** Noms des boissons portées par un produit (menu), desserts exclus. */
    private function boissonsDuProduit(array $snapshot): array
    {
        $noms = [];
        foreach ((array) ($snapshot['addons'] ?? []) as $a) {
            $role = strtolower((string) ($a['role'] ?? ''));
            if ($role === 'drink' || $role === 'menu_boisson' || str_contains($role, 'boisson')) {
                $noms[] = (string) ($a['addon_name'] ?? $a['name'] ?? '');
            }
        }

        return $noms;
    }

    /** Mots qui distinguent deux boissons de la même marque : « Coca » n'est pas « Coca zéro ». */
    private const VARIANTES = ['zero', 'light', 'max', 'cherry', 'vanille', 'peche', 'citron', 'mangue',
        'framboise', 'pomme', 'orange', 'tropical', 'hawai', 'hawaii', 'fraise', 'menthe', 'gazeuse', 'plate'];

    /** @return list<string>|null mots significatifs du nom (volume, « canette », ponctuation retirés) */
    private function cleBoisson(string $nom): ?array
    {
        $n = strtolower(\Illuminate\Support\Str::ascii($nom));
        $n = preg_replace('/\d+([.,]\d+)?\s*(cl|ml|l)\b/u', ' ', $n) ?? $n;
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;
        $mots = array_values(array_diff(
            array_filter(explode(' ', $n), static fn (string $m): bool => $m !== ''),
            ['la', 'le', 'de', 'du', 'en', 'canette', 'bouteille', 'boisson', 'x']
        ));

        return $mots === [] ? null : array_values(array_unique($mots));
    }

    /** Même boisson : les mots de l'une sont tous dans l'autre, sans mot de variante en surplus. */
    private function memeBoisson(array $seule, array $duMenu): bool
    {
        foreach ($duMenu as $m) {
            [$court, $long] = count($seule) <= count($m) ? [$seule, $m] : [$m, $seule];
            if (array_diff($court, $long) === [] && array_intersect(array_diff($long, $court), self::VARIANTES) === []) {
                return true;
            }
        }

        return false;
    }
}
