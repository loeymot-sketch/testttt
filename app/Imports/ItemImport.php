<?php

namespace App\Imports;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Rules\IniAmount;
use App\Libraries\EnumAppLibrary;
use DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;


class ItemImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsEmptyRows
{
    use Importable, SkipsFailures, \App\Imports\Concerns\AccepteLesEnTetesExportes;

    /**
     * [ONB 2026-08-28] Marqueur porte par le message des lignes DEJA PRESENTES,
     * pour que le controleur puisse les separer des vraies erreurs.
     *
     * Il n'apparait jamais a l'ecran : le controleur le retire avant d'afficher.
     */
    public const MARQUEUR_DEJA_PRESENT = '[[deja-present]]';

    /** Nombre de lignes reellement transformees en article. */
    public int $creees = 0;

    public function model(array $row)
    {
        $category_id = $this->getCategoryId($this->sanitizeInput($row['category']));
        if ($category_id) {
            $this->creees++;

            return new Item([
                'name' => $this->sanitizeInput($row['name'] ?? ''),
                'item_category_id' => $category_id,
                'slug' => Str::slug($this->sanitizeInput($row['name'])),
                'tax_id' => $this->getTaxId($row['tax']),
                'item_type' => EnumAppLibrary::itemType($this->sanitizeInput($row['item_type'] ?? '')),
                'price' => $row['price'],
                'is_featured' => EnumAppLibrary::itemFeature($row['featured']),
                'description' => $this->sanitizeInput($row['description'] ?? ''),
                'caution' => $this->sanitizeInput($row['caution'] ?? ''),
                'status' => EnumAppLibrary::itemStatus($row['status']),
            ]);
        }
    }

    /**
     * [ONB-02 2026-08-28] Accepter le fichier que l'application vient d'exporter.
     *
     * `ItemExport::headings()` ecrit des en-tetes TRADUITS (« Nom », « Categorie »,
     * « Prix »...), que `WithHeadingRow` slugge. L'import cherchait `name`,
     * `category`, `price` : AUCUNE colonne ne correspondait.
     *
     * Le commercant exportait sa carte, corrigeait deux prix, redeposait le meme
     * fichier : toutes les lignes echouaient sur `name required`, `SkipsOnFailure`
     * les avalait, et l'ecran annoncait un succes. Le SEUL moyen d'editer sa carte
     * en masse etait un aller-retour qui ne revenait jamais.
     *
     * (Le defaut ne frappait pas que le francais : `all.label.item_category_id`
     * donne « Item Category Id » en anglais, sluggue `item_category_id`, quand
     * l'import attend `category`.)
     *
     * La resolution vit desormais dans `AccepteLesEnTetesExportes`, partagee avec
     * l'import des categories — qui avait ete oublie lors de la premiere correction.
     *
     * @return array<string, string>
     */
    protected static function correspondanceDesEnTetes(): array
    {
        return [
            'all.label.name'             => 'name',
            'all.label.item_category_id' => 'category',
            'all.label.price'            => 'price',
            'all.label.item_type'        => 'item_type',
            'all.label.tax_id'           => 'tax',
            'all.label.status'           => 'status',
            'all.label.featured'         => 'featured',
            'all.label.caution'          => 'caution',
            'all.label.description'      => 'description',
        ];
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:190',
                /*
                 * [ONB 2026-08-28] Remplace `Rule::unique("items","name")
                 * ->whereNull('deleted_at')` — MEME REQUETE, MEME VERDICT — pour
                 * pouvoir en changer le MESSAGE.
                 *
                 * Le message par defaut de Laravel, « The name has already been
                 * taken. », est exact et inutilisable : il presente comme une faute
                 * ce qui est, dans le parcours normal (corriger quelques lignes puis
                 * redeposer LE MEME fichier), la preuve que les lignes precedentes
                 * sont bien passees. Un commercant lisait 40 erreurs la ou il en
                 * attendait zero.
                 *
                 * `DB::table()` et non `Item::query()` : `Rule::unique` interroge le
                 * constructeur NU, sans les scopes globaux. Passer par le modele
                 * appliquerait `BranchScope` et changerait le verdict.
                 */
                function ($attribut, $valeur, $echec) {
                    $existe = DB::table('items')
                        ->where('name', $valeur)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($existe) {
                        $echec(
                            self::MARQUEUR_DEJA_PRESENT
                            . " Ce produit est deja dans votre carte : la ligne a ete "
                            . "ignoree, rien n'a ete modifie. L'import AJOUTE des produits, "
                            . "il ne les met pas a jour — pour changer un prix ou une "
                            . 'description, ouvrez la fiche du produit dans Produits.'
                        );
                    }
                },
            ],
            // [ONB-02 2026-08-28] La regle ne verifiait que la PRESENCE du nom.
            // Quand la categorie n'existait pas, `getCategoryId()` renvoyait null,
            // `model()` ne retournait rien, et Maatwebsite sautait la ligne EN
            // SILENCE (`ModelManager::toModels()` fait `Collection::wrap(null)`).
            // Le commercant deposait 45 lignes, lisait « succes », et pouvait avoir
            // 0 produit cree sans un seul message.
            //
            // En passant par la validation, la ligne tombe dans `failures()` — que le
            // controleur lit desormais — avec un message qui NOMME les categories
            // existantes. Plus de saut muet.
            'category' => [
                'required',
                'string',
                function ($attribut, $valeur, $echec) {
                    if ($this->categorieExiste($valeur)) {
                        return;
                    }

                    $connues = \App\Models\ItemCategory::query()
                        ->pluck('name')->sort()->take(12)->implode(' · ');

                    $echec(
                        "La categorie « {$valeur} » n'existe pas. Creez-la d'abord, ou "
                        . "utilisez une categorie existante : {$connues}"
                    );
                },
            ],
            // [ONB-02 / agent ROUGE 2026-08-27] L'import Excel n'appelle JAMAIS
            // ItemRequest : rendre `tax_id` obligatoire là-bas ne fermait donc rien
            // ici. Une colonne « tax » vide produisait un article à tax_id NULL, que
            // PricingService facture ensuite à 0 % sans rien signaler. Le trou ne
            // s'était pas bouché, il s'était déplacé — c'est un agent adverse qui l'a
            // trouvé, pas moi.
            'tax' => ['required', 'numeric'],
            // [ONB-02 2026-08-28] `EnumAppLibrary` retombait SILENCIEUSEMENT sur une
            // valeur par defaut quand elle ne reconnaissait pas la saisie : « Actif »
            // devenait INACTIF, « Vegetarien » devenait VEG quoi qu'il arrive. Les 45
            // produits etaient crees invisibles, et l'ecran disait « reussi ».
            // On refuse desormais, en NOMMANT les valeurs acceptees — sinon
            // « valeur invalide » ne dit pas au commercant quoi ecrire a la place.
            'item_type' => ['required', function ($attribut, $valeur, $echec) {
                if (EnumAppLibrary::itemType($valeur) === null) {
                    $echec("« {$valeur} » n'est pas un type de produit reconnu. Valeurs "
                        . 'acceptees : ' . implode(' · ', EnumAppLibrary::valeursAcceptees('itemType')));
                }
            }],
            'price' => ['required', new IniAmount()],
            'featured' => ['required', function ($attribut, $valeur, $echec) {
                if (EnumAppLibrary::itemFeature($valeur) === null) {
                    $echec("« {$valeur} » n'est pas une reponse reconnue. Valeurs "
                        . 'acceptees : ' . implode(' · ', EnumAppLibrary::valeursAcceptees('ask')));
                }
            }],
            'description' => ['nullable', 'string', 'max:5000'],
            'caution' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'max:24', function ($attribut, $valeur, $echec) {
                if (EnumAppLibrary::itemStatus($valeur) === null) {
                    $echec("« {$valeur} » n'est pas un statut reconnu. Sans cette "
                        . 'correction le produit serait cree INACTIF, donc invisible a la '
                        . 'borne comme a la caisse. Valeurs acceptees : '
                        . implode(' · ', EnumAppLibrary::valeursAcceptees('statuse')));
                }
            }],
        ];
    }

    private function sanitizeInput($value): array|bool|string
    {
        return mb_convert_encoding(trim($value), 'UTF-8', 'UTF-8');
    }

    /**
     * [ONB-02 / agent ROUGE 2026-08-27] Renvoyer null revenait à créer un article
     * hors taxe en silence. On refuse désormais, en nommant le taux introuvable :
     * le contrôleur d'import attrape l'exception et renvoie un 422 avec ce message,
     * donc le commerçant apprend enfin CE QUI a échoué au lieu de lire « accepté ».
     */
    private function getTaxId($tax_rate): int
    {
        $tax = Tax::where('tax_rate', $tax_rate)->first();
        if ($tax) {
            return $tax->id;
        }

        $connus = Tax::query()->pluck('tax_rate')->unique()->sort()->values()
            ->map(fn ($t) => rtrim(rtrim(number_format((float) $t, 2, ',', ''), '0'), ',') . ' %')
            ->implode(' · ');

        throw new \InvalidArgumentException(
            "Le taux de TVA « {$tax_rate} » de votre fichier ne correspond à aucune taxe "
            . "enregistrée. Créez-la d'abord dans Réglages → Taxes, ou utilisez un des "
            . "taux existants : {$connus}."
        );
    }

    /** La categorie est-elle resoluble ? Meme recherche que `getCategoryId()`. */
    private function categorieExiste($categoryName): bool
    {
        return $this->getCategoryId($this->sanitizeInput((string) $categoryName)) !== null;
    }

    private function getCategoryId($categoryName): int|null
    {
        $category = ItemCategory::whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($categoryName) . '%'])->first();
        if ($category) {
            return $category->id;
        }

        return null;
    }

}