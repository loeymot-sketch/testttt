<?php

namespace App\Imports;

use App\Libraries\EnumAppLibrary;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;


class ItemCategoryImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure, SkipsEmptyRows
{
    use Importable, SkipsFailures, \App\Imports\Concerns\AccepteLesEnTetesExportes;

    /** Voir `ItemImport::MARQUEUR_DEJA_PRESENT` : meme separation, meme raison. */
    public const MARQUEUR_DEJA_PRESENT = '[[deja-present]]';

    /** Nombre de lignes reellement transformees en categorie. */
    public int $creees = 0;

    /**
     * [ONB 2026-08-28] Les en-tetes traduits de `ItemCategoryExport::headings()`.
     *
     * L'export ecrit « Nom », « Statut », « Description ». L'import attendait
     * `name`, `status`, `description`. L'aller-retour ne bouclait pas — le meme
     * defaut que cote produits, jamais corrige de ce cote-ci.
     *
     * @return array<string, string>
     */
    protected static function correspondanceDesEnTetes(): array
    {
        return [
            'all.label.name'        => 'name',
            'all.label.status'      => 'status',
            'all.label.description' => 'description',
        ];
    }

    public function model(array $row)
    {
        $this->creees++;

            return new ItemCategory([
                'name' => $this->sanitizeInput($row['name'] ?? ''),
                'slug' => Str::slug($this->sanitizeInput($row['name'])),
                // [ONB-02 2026-08-28] ABSENT et NON RECONNU ne veulent pas dire la
                // meme chose, et les confondre coutait cher : un fichier de
                // categories SANS colonne « statut » donnait `itemStatus(null)` ->
                // INACTIF pour toutes. Le commercant importait ses 11 categories et
                // aucune n'apparaissait.
                //
                // Absent -> ACTIF, qui est ce qu'un commercant veut en important.
                // Present mais illisible -> refuse par la regle ci-dessous, jamais
                // rabattu en silence sur un defaut.
                'status' => EnumAppLibrary::itemStatus($row['status'] ?? '')
                    ?? \App\Enums\Status::ACTIVE,
                'description' => $this->sanitizeInput($row['description'] ?? ''),
            ]);
    }

    public function rules(): array
    {
        return [
            'name'        => [
                'required',
                'string',
                'max:190',
                // [ONB 2026-08-28] Equivalent exact de
                // `Rule::unique("item_categories","name")` — meme requete, meme
                // verdict — pour en maitriser le message. Voir `ItemImport`.
                function ($attribut, $valeur, $echec) {
                    if (DB::table('item_categories')->where('name', $valeur)->exists()) {
                        $echec(
                            self::MARQUEUR_DEJA_PRESENT
                            . " Cette categorie est deja dans votre carte : la ligne a "
                            . "ete ignoree, rien n'a ete modifie. L'import AJOUTE des "
                            . 'categories, il ne les met pas a jour.'
                        );
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:900'],
            'status'      => ['nullable', 'string', function ($attribut, $valeur, $echec) {
                // Une colonne vide est acceptee (elle vaudra ACTIF). Une valeur
                // ECRITE mais non reconnue est refusee : sans ce garde, « Actif »
                // — le mot que l'application elle-meme exporte — creait des
                // categories INACTIVES en silence.
                if (trim((string) $valeur) === '') {
                    return;
                }

                if (EnumAppLibrary::itemStatus($valeur) === null) {
                    $echec("« {$valeur} » n'est pas un statut reconnu. Valeurs "
                        . 'acceptees : ' . implode(' · ', EnumAppLibrary::valeursAcceptees('statuse')));
                }
            }],
        ];
    }

    private function sanitizeInput($value): array|bool|string
    {
        return mb_convert_encoding(trim($value), 'UTF-8', 'UTF-8');
    }

}