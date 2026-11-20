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
     * @return array{cuisson:string, en_bas:list<string>, lignes:array<int,array{quantity:int,titre:string,symbolique:string,menu:string,supplements:array<int,string>,boissons:array<int,string>,note:string,non_mappe:bool,en_bas:bool}>}
     */
    public function build(array $mapped): array
    {
        $lignes = [];
        $enBas = [];

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
            ];
        }

        $cuisson = $this->portions->forOrder(array_map(static fn (array $l): array => [
            'name' => (string) ($l['name'] ?? ''),
            'snapshot' => (array) ($l['composition_snapshot'] ?? []),
            'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
            'instruction' => (string) ($l['instruction'] ?? ''),
        ], (array) ($mapped['items'] ?? [])));

        return ['cuisson' => $cuisson['texte'], 'en_bas' => $enBas, 'lignes' => $lignes];
    }
}
