<?php

namespace App\Services\Menu;

use App\Http\Requests\ItemCategoryRequest;
use App\Http\Requests\ItemRequest;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\ItemCategoryService;
use App\Services\ItemService;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * [ONB-04 2026-08-28] Applique une proposition de carte VALIDÉE par le commerçant.
 *
 * La règle du propriétaire gouverne toute cette classe :
 *
 *     l'IA PROPOSE, l'humain VALIDE, le système APPLIQUE.
 *
 * D'où trois refus de conception, qui sont le sujet de la classe et non des détails :
 *
 * 1. **On n'écrit jamais en base directement.** Chaque ligne passe par la FormRequest
 *    de l'écran correspondant ({@see ItemCategoryRequest}, {@see ItemRequest}) puis par
 *    le service que le contrôleur appelle. Une ligne proposée par une lecture d'image
 *    subit donc EXACTEMENT les mêmes règles qu'une ligne saisie à la main — dont la
 *    taxe obligatoire posée par ONB-02 (`ItemRequest.php:67`). Sans ce détour, un
 *    article arrivé par photo aurait été facturé hors taxe en silence, ce qui est
 *    précisément le défaut qu'ONB-02 a fermé.
 *
 * 2. **On ne calcule aucun prix.** Le prix proposé est une DONNÉE SAISIE, au même titre
 *    qu'un import de tableur : le commerçant la relit et la valide. `PricingService`
 *    (zone gelée §7) reste seul maître du calcul.
 *
 * 3. **Appliquer deux fois ne duplique rien.** C'est le critère C3 du cahier des
 *    charges, et c'est la propriété qui rend l'écran utilisable : un commerçant qui
 *    doute et reclique ne doit pas se retrouver avec sa carte en double. Une ligne
 *    déjà présente est COMPTÉE COMME DÉJÀ LÀ, pas comme une erreur — la nuance
 *    compte, parce qu'un rapport plein de rouge sur une seconde application ferait
 *    croire à un échec.
 *
 * Domaine NEUF, ADDITIF, HORS NF525 : aucune écriture fiscale, aucune commande.
 */
class MenuDraftApplier
{
    public function __construct(
        private ItemCategoryService $categorieService,
        private ItemService $articleService,
    ) {
    }

    /**
     * @param  array<int, array{nom:string, categorie:string, prix:float|null, description:string|null}>  $articles
     * @param  array{tax_id:int, item_type:int}  $defauts  Choisis par le COMMERÇANT à l'écran,
     *                                                     jamais par la lecture d'image.
     * @return array{
     *     categories_creees: list<string>,
     *     categories_deja_la: list<string>,
     *     articles_crees: list<string>,
     *     articles_deja_la: list<string>,
     *     refus: list<array{ligne:string, raison:string}>
     * }
     */
    public function appliquer(array $articles, array $defauts): array
    {
        $rapport = [
            'categories_creees'  => [],
            'categories_deja_la' => [],
            'articles_crees'     => [],
            'articles_deja_la'   => [],
            'refus'              => [],
        ];

        // Les catégories d'abord : un article ne peut pas être rattaché à une
        // catégorie qui n'existe pas encore.
        $identifiantsParNom = [];

        foreach ($this->nomsDeCategories($articles) as $nom) {
            $existante = $this->categorieParNom($nom);

            if ($existante !== null) {
                $identifiantsParNom[$this->cle($nom)] = (int) $existante->id;
                $rapport['categories_deja_la'][] = $nom;
                continue;
            }

            try {
                $creee = $this->categorieService->store($this->requeteCategorie($nom));
                $identifiantsParNom[$this->cle($nom)] = (int) $creee->id;
                $rapport['categories_creees'][] = $nom;
            } catch (ValidationException $e) {
                $rapport['refus'][] = ['ligne' => $nom, 'raison' => $this->premierMessage($e)];
            } catch (\Throwable $e) {
                Log::info('[ONB-04] catégorie refusée : ' . $e->getMessage());
                $rapport['refus'][] = ['ligne' => $nom, 'raison' => $e->getMessage()];
            }
        }

        foreach ($articles as $article) {
            $nom = trim((string) ($article['nom'] ?? ''));

            if ($nom === '') {
                continue;
            }

            // Idempotence : une seconde application ne recrée rien. On le dit comme
            // « déjà là », pas comme un refus — sinon un commerçant qui reclique
            // croirait que tout a échoué.
            if ($this->articleExiste($nom)) {
                $rapport['articles_deja_la'][] = $nom;
                continue;
            }

            $categorie = $identifiantsParNom[$this->cle((string) ($article['categorie'] ?? ''))] ?? null;

            if ($categorie === null) {
                $rapport['refus'][] = [
                    'ligne'  => $nom,
                    'raison' => "la catégorie « " . ($article['categorie'] ?? '') . " » n'a pas pu être créée",
                ];
                continue;
            }

            try {
                $this->articleService->store($this->requeteArticle($article, $categorie, $defauts));
                $rapport['articles_crees'][] = $nom;
            } catch (ValidationException $e) {
                $rapport['refus'][] = ['ligne' => $nom, 'raison' => $this->premierMessage($e)];
            } catch (\Throwable $e) {
                Log::info('[ONB-04] article refusé : ' . $e->getMessage());
                $rapport['refus'][] = ['ligne' => $nom, 'raison' => $e->getMessage()];
            }
        }

        return $rapport;
    }

    /** @param array<int, array<string, mixed>> $articles @return list<string> */
    private function nomsDeCategories(array $articles): array
    {
        $noms = [];

        foreach ($articles as $article) {
            $nom = trim((string) ($article['categorie'] ?? ''));

            if ($nom !== '' && !isset($noms[$this->cle($nom)])) {
                $noms[$this->cle($nom)] = $nom;
            }
        }

        return array_values($noms);
    }

    /**
     * Comparaison insensible à la casse et aux espaces de bord : « Tacos », « tacos »
     * et « Tacos » avec une espace finale sont la MÊME catégorie pour un restaurateur.
     * Sans cette normalisation, deux lectures de la même carte en créeraient deux.
     */
    private function cle(string $nom): string
    {
        return mb_strtolower(trim($nom));
    }

    private function categorieParNom(string $nom): ?ItemCategory
    {
        return ItemCategory::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->cle($nom)])
            ->first();
    }

    private function articleExiste(string $nom): bool
    {
        return Item::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$this->cle($nom)])
            ->exists();
    }

    private function requeteCategorie(string $nom): ItemCategoryRequest
    {
        return $this->preparer(ItemCategoryRequest::class, [
            'name'   => $nom,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
    }

    /** @param array<string, mixed> $article @param array{tax_id:int, item_type:int} $defauts */
    private function requeteArticle(array $article, int $categorie, array $defauts): ItemRequest
    {
        return $this->preparer(ItemRequest::class, [
            'name'             => trim((string) $article['nom']),
            'item_category_id' => $categorie,
            // La taxe vient du COMMERÇANT, jamais de la lecture d'image. Si elle
            // manque, `ItemRequest` refuse — et c'est le comportement voulu : mieux
            // vaut un refus lisible qu'un article facturé à 0 %.
            'tax_id'           => $defauts['tax_id'] ?? null,
            'item_type'        => $defauts['item_type'] ?? null,
            'price'            => $article['prix'] ?? null,
            'is_featured'      => \App\Enums\Activity::DISABLE,
            'status'           => \App\Enums\Status::ACTIVE,
            // L'article importé s'ajoute APRÈS ce que le commerçant a déjà, dans
            // l'ordre où il apparaissait sur sa carte. S'intercaler en tête (ordre 0)
            // réordonnerait silencieusement une carte déjà en place — le genre de
            // surprise qui fait perdre confiance dans l'import.
            'order'            => $this->prochainOrdre($categorie),
            'description'      => $article['description'] ?? null,
        ]);
    }

    /** Position suivante dans la catégorie : on se range derrière l'existant. */
    private function prochainOrdre(int $categorie): int
    {
        return ((int) Item::query()
            ->where('item_category_id', $categorie)
            ->max('order')) + 1;
    }

    /**
     * Construit une FormRequest et la fait passer par SA PROPRE validation.
     *
     * `validateResolved()` est la méthode que Laravel appelle lui-même quand la
     * requête arrive par HTTP : elle exécute `authorize()` puis `rules()`. On
     * emprunte donc le chemin exact de l'écran, sans le dupliquer — c'est ce qui
     * garantit qu'une règle ajoutée demain à l'écran s'applique aussi ici, sans
     * que personne n'ait à y penser.
     *
     * @param  class-string<\Illuminate\Foundation\Http\FormRequest>  $classe
     * @param  array<string, mixed>  $donnees
     * @return mixed
     */
    private function preparer(string $classe, array $donnees)
    {
        /** @var \Illuminate\Foundation\Http\FormRequest $requete */
        $requete = $classe::create('/', 'POST', array_filter(
            $donnees,
            static fn ($v) => $v !== null
        ));

        $requete->setContainer(app())->setRedirector(app(Redirector::class));
        $requete->setUserResolver(fn () => auth()->user());
        $requete->validateResolved();

        return $requete;
    }

    private function premierMessage(ValidationException $e): string
    {
        $premier = collect($e->errors())->flatten()->first();

        return is_string($premier) ? $premier : $e->getMessage();
    }
}
