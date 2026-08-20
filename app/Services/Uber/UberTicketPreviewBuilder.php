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
     * @return array{cuisson:string, lignes:array<int,array{quantity:int,titre:string,symbolique:string,menu:string,supplements:array<int,string>,boissons:array<int,string>,note:string,non_mappe:bool}>}
     */
    public function build(array $mapped): array
    {
        $lignes = [];

        foreach ((array) ($mapped['items'] ?? []) as $line) {
            $nom = (string) ($line['name'] ?? '');
            $snapshot = (array) ($line['composition_snapshot'] ?? []);
            $instruction = (string) ($line['instruction'] ?? '');

            $boissons = $this->formatter->drinkLines($snapshot);

            $lignes[] = [
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'titre' => $nom,
                'symbolique' => $this->formatter->mainLine($nom, $snapshot, $instruction),
                // Le BADGE, pas la simple ligne de menu : c'est lui qui sera imprimé, sauce des
                // frites comprise. Un aperçu qui montrerait autre chose donnerait une fausse
                // confiance à la personne qui valide.
                'menu' => $this->formatter->menuBadge($snapshot, $nom, $instruction),
                'supplements' => $this->formatter->supplementLines($snapshot, $instruction),
                'boissons' => $boissons,
                // La note passe par le MÊME nettoyeur que le ticket : ce qui n'y survivrait pas
                // ne doit pas apparaître dans l'aperçu, sinon l'humain croit avoir transmis une
                // consigne qui n'arrivera jamais en cuisine.
                'note' => $this->formatter->cleanInstruction($instruction, $nom, $boissons),
                // [UBER TITRE ENTIER 2026-08-20 · owner] Le drapeau se lit dans le snapshot, plus
                // dans le texte de la note : la note ne porte plus la mention (elle distrayait la
                // cuisine), et une détection par sous-chaîne aurait de toute façon fini par mentir
                // le jour où un client écrit ces mots-là dans SA note.
                'non_mappe' => ($snapshot['uber_unmapped'] ?? false) === true,
            ];
        }

        $cuisson = $this->portions->forOrder(array_map(static fn (array $l): array => [
            'name' => (string) ($l['name'] ?? ''),
            'snapshot' => (array) ($l['composition_snapshot'] ?? []),
            'quantity' => max(1, (int) ($l['quantity'] ?? 1)),
            'instruction' => (string) ($l['instruction'] ?? ''),
        ], (array) ($mapped['items'] ?? [])));

        return ['cuisson' => $cuisson['texte'], 'lignes' => $lignes];
    }
}
