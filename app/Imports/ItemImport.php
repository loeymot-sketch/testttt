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
    use Importable, SkipsFailures;

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

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:190',
                Rule::unique("items", "name")->whereNull('deleted_at')
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
            'item_type' => ['required'],
            'price' => ['required', new IniAmount()],
            'featured' => ['required'],
            'description' => ['nullable', 'string', 'max:5000'],
            'caution' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'max:24'],
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