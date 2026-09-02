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
     *     doublons_dans_la_lecture: list<array{ligne:string, raison:string}>,
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
            // [ONB 2026-08-28] Deux lignes de MEME nom dans une seule lecture.
            // Voir la boucle plus bas : elles etaient rangees avec les « deja la »,
            // et la seconde disparaissait sans un mot.
            'doublons_dans_la_lecture' => [],
            'refus'              => [],
        ];

        /*
         * Les noms deja presents AVANT cette application. C'est ce qui permet de
         * distinguer une vraie idempotence (le commercant reclique) d'un doublon
         * interne au fichier (deux lignes de meme nom dans la meme lecture).
         * On le fige AVANT la boucle : une fois la premiere ligne creee, la base
         * ne sait plus faire la difference.
         */
        $presentsAvant = [];
        foreach ($articles as $article) {
            $nom = trim((string) ($article['nom'] ?? ''));

            if ($nom !== '' && $this->articleExiste($nom)) {
                $presentsAvant[$this->cle($nom)] = true;
            }
        }

        /*
         * [ONB 2026-08-28] Les catégories sont résolues PARESSEUSEMENT.
         *
         * Elles étaient toutes créées d'abord, avant la moindre tentative d'écriture
         * d'article. Une catégorie dont TOUS les articles échouaient — prix illisible,
         * nom en double, taxe absente — restait donc en base, VIDE.
         *
         * Sur la fixture du bouchon, « Menus midi » n'a qu'un seul article, et c'est
         * exactement celui que le doublon de nom fait perdre : la catégorie survivait
         * seule. Elle s'affiche alors dans le bandeau de la borne, un client la
         * touche, et ne voit rien.
         *
         * Créer au dernier moment vaut mieux que faire le ménage après : il n'y a
         * plus rien à supprimer, donc pas de code de suppression à écrire — ce qui
         * aurait été la partie risquée.
         */
        $identifiantsParNom = [];

        foreach ($articles as $article) {
            $nom = trim((string) ($article['nom'] ?? ''));

            if ($nom === '') {
                continue;
            }

            // Idempotence : une seconde application ne recrée rien. On le dit comme
            // « déjà là », pas comme un refus — sinon un commerçant qui reclique
            // croirait que tout a échoué.
            if ($this->articleExiste($nom)) {
                /*
                 * [ONB 2026-08-28] Mais « déjà là » ne doit se dire que d'un article
                 * qui était là AVANT. Si le nom n'a été créé qu'à l'instant, par une
                 * ligne précédente de CETTE lecture, alors la ligne courante est un
                 * DOUBLON — un autre produit, un autre prix, une autre catégorie — et
                 * elle est en train d'être perdue.
                 *
                 * Le catalogue impose l'unicité du nom : on ne peut pas créer les
                 * deux. La seule issue honnête est de nommer la collision pour que le
                 * commerçant renomme l'une des deux lignes, au lieu de lui affirmer
                 * qu'il possédait déjà un produit qu'il vient de perdre.
                 */
                if (! isset($presentsAvant[$this->cle($nom)])) {
                    $rapport['doublons_dans_la_lecture'][] = [
                        'ligne'  => $nom,
                        'raison' => "« {$nom} » apparaît plusieurs fois dans cette lecture, "
                            . "sous des catégories ou des prix différents. Le catalogue "
                            . "impose un nom unique : seule la première ligne a été créée. "
                            . "Renommez la ou les suivantes, puis appliquez à nouveau.",
                    ];
                    continue;
                }

                $rapport['articles_deja_la'][] = $nom;
                continue;
            }

            $categorie = $this->categoriePour(
                (string) ($article['categorie'] ?? ''),
                $identifiantsParNom,
                $rapport
            );

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

    /**
     * [ONB 2026-08-28] Résout — et crée si besoin — la catégorie d'un article.
     *
     * Appelée seulement quand un article est sur le point d'y être rattaché, donc
     * une catégorie dont aucun article n'aboutit n'est jamais créée.
     *
     * Le résultat est mémorisé, y compris l'échec (`false`) : sans cela, dix
     * articles d'une même catégorie invalide produiraient dix tentatives d'écriture
     * et dix lignes de refus identiques.
     *
     * @param  array<string, int|false>  $cache
     * @param  array<string, mixed>      $rapport
     */
    private function categoriePour(string $nomBrut, array &$cache, array &$rapport): ?int
    {
        $nom = trim($nomBrut);

        if ($nom === '') {
            return null;
        }

        $cle = $this->cle($nom);

        if (array_key_exists($cle, $cache)) {
            return $cache[$cle] === false ? null : $cache[$cle];
        }

        $existante = $this->categorieParNom($nom);

        if ($existante !== null) {
            $rapport['categories_deja_la'][] = $nom;

            return $cache[$cle] = (int) $existante->id;
        }

        try {
            $creee = $this->categorieService->store($this->requeteCategorie($nom));
            $rapport['categories_creees'][] = $nom;

            return $cache[$cle] = (int) $creee->id;
        } catch (ValidationException $e) {
            $rapport['refus'][] = ['ligne' => $nom, 'raison' => $this->premierMessage($e)];
        } catch (\Throwable $e) {
            Log::info('[ONB-04] catégorie refusée : ' . $e->getMessage());
            $rapport['refus'][] = ['ligne' => $nom, 'raison' => $e->getMessage()];
        }

        $cache[$cle] = false;

        return null;
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
