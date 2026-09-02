<?php

namespace App\Services\Assistant\MissionLocale;

use App\Enums\Status;
use App\Http\Requests\ItemExtraRequest;
use App\Http\Requests\ItemRequest;
use App\Enums\Ask;
use App\Models\Item;
use App\Services\ItemExtraService;
use App\Services\ItemService;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * [ONB-04 2026-08-28] Appliquer une mission — en repassant par les règles.
 *
 * ═══ DEUX RÈGLES QUI NE SE NÉGOCIENT PAS ═══
 *
 * **1. Aucune écriture directe en base.** Tout passe par `ItemRequest` /
 * `ItemExtraRequest` et les services existants. Une nouvelle porte d'écriture qui
 * contourne les FormRequest rouvrirait exactement le trou qu'ONB-02 a fermé : un
 * article sans taxe est facturé à 0 % en silence par `PricingService`. Un assistant
 * qui écrit « plus simplement » est un assistant qui écrit plus mal.
 *
 * **2. Le plan est REFAIT ici, jamais reçu du client.** Le contrôleur ne fait pas
 * confiance à un diff envoyé par le navigateur : il ré-interprète la phrase et
 * re-planifie avant d'appliquer. Sinon un plan trafiqué en route ferait écrire
 * n'importe quoi sous couvert d'une confirmation humaine.
 *
 * Une transaction unique : cinquante produits changent ensemble, ou aucun. Un
 * catalogue à moitié modifié serait pire que pas modifié — le commerçant ne saurait
 * pas où il en est.
 */
class ExecuteurDeMission
{
    public function __construct(
        private readonly ItemService $itemService,
        private readonly ItemExtraService $itemExtraService,
        private readonly PlanificateurDeMission $planificateur,
    ) {
    }

    /**
     * @return array{applique: int, echecs: list<array{produit:string, raison:string}>, resume: string}
     */
    public function executer(Mission $mission): array
    {
        $plan = $this->planificateur->planifier($mission);

        if (! $plan['applicable']) {
            return [
                'applique' => 0,
                'echecs'   => [],
                'resume'   => (string) ($plan['avertissement'] ?? 'Rien à appliquer.'),
            ];
        }

        $applique = 0;
        $echecs = [];

        // [ONB-13 2026-08-28] TOUT OU RIEN — le docblock le promettait, le code ne le
        // tenait pas.
        //
        // Le `try/catch` etait A L'INTERIEUR de cette cloture : les echecs etaient
        // collectes sans jamais faire echouer la transaction, donc les succes
        // partiels etaient commites. Le commercant lisait « 4 modifies, 2 en echec »
        // et se retrouvait avec un catalogue a moitie change — precisement ce que le
        // docblock declare impossible.
        //
        // On leve maintenant apres la boucle si le moindre changement a echoue : la
        // transaction est annulee, et le rapport liste ce qu'il aurait fallu corriger.
        try {
            DB::transaction(function () use ($mission, $plan, &$applique, &$echecs): void {
                foreach ($plan['changements'] as $changement) {
                    $produit = Item::query()->find($changement['id']);

                    if ($produit === null) {
                        $echecs[] = ['produit' => $changement['produit'], 'raison' => 'produit introuvable'];
                        continue;
                    }

                    try {
                        $this->appliquerSur($mission, $produit);
                        $applique++;
                    } catch (ValidationException $e) {
                        $echecs[] = [
                            'produit' => $changement['produit'],
                            'raison'  => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                        ];
                    } catch (\Throwable $e) {
                        $echecs[] = ['produit' => $changement['produit'], 'raison' => $e->getMessage()];
                    }
                }

                if ($echecs !== []) {
                    throw new MissionPartielleException();
                }
            });
        } catch (MissionPartielleException) {
            // Rien n'a ete ecrit : on le DIT, plutot que d'annoncer des succes que
            // la base ne porte plus.
            return [
                'applique' => 0,
                'echecs'   => $echecs,
                'resume'   => 'Rien n\'a ete modifie : ' . count($echecs) . ' produit(s) ont ete refuses, '
                    . 'et une mission s\'applique entierement ou pas du tout. '
                    . 'Corrigez les produits listes, puis relancez.',
            ];
        }

        return [
            'applique' => $applique,
            'echecs'   => $echecs,
            'resume'   => $this->resume($applique, count($echecs), count($plan['ecartes'])),
        ];
    }

    // ─────────────────────────────────────────────────────────── par type

    private function appliquerSur(Mission $mission, Item $produit): void
    {
        match ($mission->type) {
            Mission::AJOUTER_UNE_OPTION => $this->itemExtraService->store(
                $this->preparer(ItemExtraRequest::class, [
                    'name'        => $mission->nomOption,
                    'price'       => $mission->prix,
                    'status'      => Status::ACTIVE,
                    'group_label' => $mission->groupe,
                ], ['item' => $produit]),
                $produit
            ),

            Mission::CHANGER_LE_PRIX => $this->itemService->update(
                $this->requeteArticle($produit, ['price' => $mission->prix]),
                $produit
            ),

            Mission::CHANGER_LA_DISPONIBILITE => $this->itemService->update(
                $this->requeteArticle($produit, [
                    'status' => $mission->actif ? Status::ACTIVE : Status::INACTIVE,
                ]),
                $produit
            ),
        };
    }

    /**
     * Reconstruit la requête d'article COMPLÈTE à partir du produit existant, en
     * ne remplaçant que ce que la mission change.
     *
     * `ItemService::update()` fait `$item->update($request->validated())` : n'envoyer
     * que le champ modifié suffirait techniquement. Mais `ItemRequest` porte des
     * règles `required` (taxe, statut, ordre) qui refuseraient une requête partielle
     * — et surtout, repartir de l'état réel garantit qu'on ne peut pas écraser par
     * omission, le défaut le plus répandu de ce dépôt.
     *
     * @param array<string, mixed> $remplacements
     */
    /**
     * [ONB-13 2026-08-28] Ramene `is_featured` a une valeur que la regle accepte.
     *
     * ═══ POURQUOI ═══
     *
     * `requeteArticle()` rejoue l'etat REEL du produit — c'est voulu, et c'est ce
     * qui empeche d'ecraser par omission. Mais l'etat reel n'est pas toujours
     * valide : **47 articles sur 104** portent `is_featured = 0`, une valeur qui
     * n'est ni `Ask::YES` (5) ni `Ask::NO` (10), pendant que `ItemRequest:93` porte
     * `not_in:0`.
     *
     * Consequence mesuree : l'assistant refusait 4 categories ENTIERES — Boissons
     * 15/15, Supplements 10/10, Bols 8/8, Desserts 3/3 — et partiellement 4 autres.
     * Le commercant tapait « desactivez toutes les Boissons » et lisait « 0 modifie,
     * 15 en echec », sans jamais savoir que la faute venait d'un champ qu'il
     * n'avait pas demande de toucher.
     *
     * `0` et `Ask::NO` produisent le meme comportement partout (le filtre de mise en
     * avant cherche `Ask::YES`) : la normalisation ne change donc rien pour le
     * client, elle rend seulement la requete acceptable.
     *
     * ⚠️ On ne CORRIGE pas la donnee : ecrire dans 47 lignes du catalogue en
     * service est une decision proprietaire. On rend juste l'assistant capable de
     * travailler avec le catalogue tel qu'il est.
     */
    private function miseEnAvantValide(mixed $valeur): int
    {
        return (int) $valeur === Ask::YES ? Ask::YES : Ask::NO;
    }

    private function requeteArticle(Item $produit, array $remplacements): ItemRequest
    {
        return $this->preparer(ItemRequest::class, array_merge([
            'name'             => $produit->name,
            'item_category_id' => $produit->item_category_id,
            'tax_id'           => $produit->tax_id,
            'item_type'        => $produit->item_type,
            'price'            => $produit->price,
            'is_featured'      => $this->miseEnAvantValide($produit->is_featured),
            'status'           => $produit->status,
            'order'            => $produit->order ?? 1,
            'description'      => $produit->description,
            'caution'          => $produit->caution,
        ], $remplacements), ['item' => $produit]);
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>  $parametresDeRoute
     */
    private function preparer(string $classe, array $donnees, array $parametresDeRoute = [])
    {
        /** @var \Illuminate\Foundation\Http\FormRequest $requete */
        $requete = $classe::create('/', 'POST', array_filter(
            $donnees,
            static fn ($v) => $v !== null
        ));

        $requete->setContainer(app())->setRedirector(app(Redirector::class));
        $requete->setUserResolver(fn () => auth()->user());

        /*
         * `ItemExtraRequest` lit `$this->route('item')` pour borner son unicité au
         * produit courant. Sans ce faux routage, elle porterait sur `item_id = null`.
         *
         * ⚠️ HONNÊTETÉ SUR CE QUE CETTE LIGNE PROTÈGE. Ce n'est PAS elle qui empêche
         * les doublons sur ce chemin : `PlanificateurDeMission::projeterUneOption()`
         * écarte déjà tout produit qui porte l'option, et le retirer laisse le banc
         * `test_reappliquer_la_meme_mission_ne_double_rien` vert — je l'ai vérifié en
         * le cassant. C'est une SECONDE ligne de défense : elle sert si le plan et
         * l'écriture divergent un jour, ou si quelqu'un appelle `appliquerSur()`
         * directement.
         *
         * Écrire « sans ça, les doublons passent » aurait été un commentaire qui
         * affirme ce que le code ne fait pas — le motif exact que cette session passe
         * son temps à corriger ailleurs.
         */
        if ($parametresDeRoute !== []) {
            $route = new \Illuminate\Routing\Route(['POST'], '/', []);

            // `setParameter()` lève « Route is not bound. » tant que `bind()` n'a pas
            // initialisé le sac de paramètres.
            $route->bind($requete);

            foreach ($parametresDeRoute as $nom => $valeur) {
                $route->setParameter($nom, $valeur);
            }

            $requete->setRouteResolver(fn () => $route);
        }

        $requete->validateResolved();

        return $requete;
    }

    private function resume(int $applique, int $echecs, int $ecartes): string
    {
        $phrase = $applique > 0
            ? $applique . ' produit' . ($applique > 1 ? 's modifiés.' : ' modifié.')
            : 'Aucun produit modifié.';

        if ($ecartes > 0) {
            $phrase .= ' ' . $ecartes . ($ecartes > 1 ? ' écartés' : ' écarté')
                . ' (déjà dans cet état).';
        }

        if ($echecs > 0) {
            $phrase .= ' ' . $echecs . ' en échec : voir le détail.';
        }

        return $phrase;
    }
}
