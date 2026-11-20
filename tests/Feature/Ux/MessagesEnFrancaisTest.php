<?php

namespace Tests\Feature\Ux;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [ONB-11 T-2.1.2 2026-08-27] Les messages d'erreur nomment le champ en français.
 *
 * `lang/fr/validation.php` avait `'attributes' => []`. Laravel remplaçait donc
 * `:attribute` par le nom technique de la colonne : un commerçant lisait
 * « Le champ item_category_id est obligatoire » en essayant d'enregistrer un
 * produit. Un seul tableau vide, des dizaines d'écrans touchés.
 *
 * Ce test garde les champs les plus exposés — ceux qu'un commerçant rencontre
 * dès son premier formulaire.
 */
class MessagesEnFrancaisTest extends TestCase
{
    private function messagePour(string $champ): string
    {
        $v = Validator::make([], [$champ => ['required']]);
        $v->fails();

        return $v->errors()->first($champ);
    }

    /** @dataProvider champsExposes */
    public function test_le_message_nomme_le_champ_en_francais(string $champ, string $attendu): void
    {
        $message = $this->messagePour($champ);

        $this->assertStringContainsString(
            $attendu,
            $message,
            "Le message pour « {$champ} » doit nommer le champ en français, pas avec le nom "
            . "de la colonne. Obtenu : « {$message} »"
        );
        $this->assertStringNotContainsString(
            $champ,
            $message,
            "Le nom technique « {$champ} » ne doit jamais apparaître à l'écran."
        );
    }

    public static function champsExposes(): array
    {
        return [
            'catégorie d’un article' => ['item_category_id', 'catégorie'],
            'taxe d’un article'      => ['tax_id', 'taxe'],
            'établissement'          => ['branch_id', 'établissement'],
            'code postal'            => ['zip_code', 'code postal'],
            'moyen de paiement'      => ['payment_method', 'moyen de paiement'],
            'numéro de caisse'       => ['register_id', 'numéro de caisse'],
            'poste de cuisine'       => ['kds_station', 'poste de cuisine'],
        ];
    }

    public function test_le_tableau_des_attributs_n_est_pas_vide(): void
    {
        $attributs = trans('validation.attributes');

        $this->assertIsArray($attributs);
        $this->assertNotEmpty(
            $attributs,
            "Un tableau d'attributs vide fait tomber TOUS les formulaires de l'administration "
            . 'sur les noms de colonnes de la base.'
        );
    }
}
