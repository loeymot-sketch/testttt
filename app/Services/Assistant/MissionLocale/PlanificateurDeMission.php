<?php

namespace App\Services\Assistant\MissionLocale;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;

/**
 * [ONB-04 2026-08-28] Projeter une mission en DIFF, sans rien écrire.
 *
 * C'est la moitié qui compte. Une mission locale touche cinquante produits d'un
 * coup : si le commerçant ne voit pas AVANT ce qui va changer, l'assistant est un
 * accélérateur d'erreurs plutôt qu'un gain de temps. « J'ai ajouté la sauce à vos
 * 47 tacos » n'est pas rattrapable en un clic.
 *
 * Ce planificateur ne fait donc que LIRE. Il rend, pour chaque produit concerné,
 * l'avant et l'après — et, séparément, les produits qu'il ÉCARTE et pourquoi. Un
 * plan qui cache ses exclusions ment par omission : le commerçant croirait avoir
 * couvert toute sa catégorie.
 */
class PlanificateurDeMission
{
    /**
     * @return array{
     *   categorie: string|null,
     *   resume: string,
     *   changements: list<array{id:int, produit:string, avant:string, apres:string}>,
     *   ecartes: list<array{produit:string, raison:string}>,
     *   applicable: bool,
     *   avertissement: string|null
     * }
     */
    public function planifier(Mission $mission): array
    {
        $categorie = $this->categorieParNom($mission->categorie);

        if ($categorie === null) {
            return $this->planVide(
                $mission,
                sprintf(
                    "Je ne trouve aucune catégorie « %s ». Vos catégories : %s.",
                    $mission->categorie,
                    ItemCategory::query()->orderBy('name')->pluck('name')->take(15)->implode(' · ')
                )
            );
        }

        $produits = Item::query()
            ->where('item_category_id', $categorie->id)
            ->orderBy('name')
            ->get();

        if ($produits->isEmpty()) {
            return $this->planVide(
                $mission,
                sprintf('La catégorie « %s » ne contient aucun produit.', $categorie->name)
            );
        }

        $changements = [];
        $ecartes = [];

        foreach ($produits as $produit) {
            [$avant, $apres, $raisonEcart] = $this->projeter($mission, $produit);

            if ($raisonEcart !== null) {
                $ecartes[] = ['produit' => (string) $produit->name, 'raison' => $raisonEcart];
                continue;
            }

            if ($avant === $apres) {
                $ecartes[] = [
                    'produit' => (string) $produit->name,
                    'raison'  => 'déjà dans cet état — rien à changer',
                ];
                continue;
            }

            $changements[] = [
                'id'      => (int) $produit->id,
                'produit' => (string) $produit->name,
                'avant'   => $avant,
                'apres'   => $apres,
            ];
        }

        return [
            'categorie'     => (string) $categorie->name,
            'resume'        => $mission->resume(),
            'changements'   => $changements,
            'ecartes'       => $ecartes,
            'applicable'    => $changements !== [],
            'avertissement' => $changements === []
                ? "Rien à faire : aucun produit de cette catégorie n'est concerné."
                : null,
        ];
    }

    // ────────────────────────────────────────────────────────────── projection

    /**
     * @return array{0:string, 1:string, 2:string|null} avant, après, raison d'écart
     */
    private function projeter(Mission $mission, Item $produit): array
    {
        return match ($mission->type) {
            Mission::AJOUTER_UNE_OPTION => $this->projeterUneOption($mission, $produit),

            Mission::CHANGER_LE_PRIX => [
                $this->euros((float) $produit->price),
                $this->euros((float) $mission->prix),
                null,
            ],

            Mission::CHANGER_LA_DISPONIBILITE => [
                (int) $produit->status === Status::ACTIVE ? 'actif' : 'inactif',
                $mission->actif ? 'actif' : 'inactif',
                null,
            ],
        };
    }

    /** @return array{0:string, 1:string, 2:string|null} */
    private function projeterUneOption(Mission $mission, Item $produit): array
    {
        // `ItemExtraRequest` impose l'unicité du nom PAR PRODUIT. Un produit qui a
        // déjà cette option n'est pas une erreur : c'est un produit à écarter, et à
        // dire. Le laisser dans les changements ferait échouer l'application sur une
        // règle que le plan aurait dû anticiper.
        $existe = ItemExtra::query()
            ->where('item_id', $produit->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim((string) $mission->nomOption))])
            ->whereNull('deleted_at')
            ->exists();

        if ($existe) {
            return ['', '', sprintf('« %s » y est déjà', $mission->nomOption)];
        }

        $libelle = sprintf(
            '%s (%s)',
            $mission->nomOption,
            $mission->prix > 0 ? '+' . $this->euros((float) $mission->prix) : 'gratuit'
        );

        return ['sans cette option', $libelle, null];
    }

    // ───────────────────────────────────────────────────────────────── outillage

    private function categorieParNom(string $nom): ?ItemCategory
    {
        $cle = mb_strtolower(trim($nom));

        // Exact d'abord — « Tacos » ne doit pas tomber sur « Tacos du chef » quand
        // les deux existent.
        $exacte = ItemCategory::query()->whereRaw('LOWER(TRIM(name)) = ?', [$cle])->first();

        if ($exacte !== null) {
            return $exacte;
        }

        // Puis approchant, mais SEULEMENT s'il n'y a pas d'ambiguïté : deux
        // candidates, on refuse plutôt que d'en choisir une au hasard.
        $candidates = ItemCategory::query()
            ->whereRaw('LOWER(name) LIKE ?', ['%' . $cle . '%'])
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function euros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ') . ' €';
    }

    /**
     * @return array{categorie: null, resume: string, changements: list<never>,
     *               ecartes: list<never>, applicable: false, avertissement: string}
     */
    private function planVide(Mission $mission, string $pourquoi): array
    {
        return [
            'categorie'     => null,
            'resume'        => $mission->resume(),
            'changements'   => [],
            'ecartes'       => [],
            'applicable'    => false,
            'avertissement' => $pourquoi,
        ];
    }
}
