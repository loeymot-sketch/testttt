<?php

namespace Database\Seeders;

use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Enums\TaxType;
use App\Enums\Status;
use App\Models\Tax;

class TaxTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $envService = new EnvEditor();

        // [ONB-02 T-2.1.2 2026-08-27] Des taux FRANÇAIS, et des noms qui ne mentent pas.
        //
        // Le socle livrait « No-VAT », deux entrées nommées « VAT » (5 % et 10 %,
        // indiscernables dans une liste déroulante) et deux « GST » — une taxe
        // indienne qui n'existe pas en France. Aucun taux à 20 %, alors que
        // l'alcool y est soumis : un bar ne pouvait pas déclarer correctement.
        //
        // Le nom porte désormais le taux ET son usage : un restaurateur choisit
        // sans avoir à connaître le code interne. `updateOrCreate` est indexé sur
        // `code`, donc renommer ne crée pas de doublon, ne change aucun `id`, et
        // ne réécrit aucune commande passée (order_items.tax_rate/tax_amount sont
        // figés à la création, et le nom de taxe est capturé dans la commande).
        //
        // Les codes historiques sont conservés tels quels : ce sont eux qui font
        // la clé de rapprochement, les toucher casserait les installations en place.
        $taxes = [
            [
                'name'       => 'TVA 10 % — sur place et à emporter',
                'code'       => 'VAT-10%',
                'tax_rate'   => 10,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'TVA 20 % — alcools et boissons alcoolisées',
                'code'       => 'VAT-20%',
                'tax_rate'   => 20,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'TVA 5,5 % — alimentaire conditionné',
                'code'       => 'VAT-5.5%',
                'tax_rate'   => 5.5,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            [
                'name'       => 'TVA 0 % — exonéré',
                'code'       => 'VAT-0',
                'tax_rate'   => 0,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::ACTIVE,
            ],
            // Conservés et renommés pour ne pas laisser d'entrées trompeuses dans
            // les installations existantes : mêmes codes, donc mêmes lignes.
            [
                'name'       => 'TVA 5 % (ancien taux, à ne plus utiliser)',
                'code'       => 'VAT-5%',
                'tax_rate'   => 5,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::INACTIVE,
            ],
            [
                'name'       => 'GST 5 % (hors France, à ne plus utiliser)',
                'code'       => 'GST-5%',
                'tax_rate'   => 5,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::INACTIVE,
            ],
            [
                'name'       => 'GST 10 % (hors France, à ne plus utiliser)',
                'code'       => 'GST-10%',
                'tax_rate'   => 10,
                'type'       => TaxType::PERCENTAGE,
                'status'     => Status::INACTIVE,
            ],
        ];

        // Local / production-like setups still need at least one tax row because
        // MenuSeeder references config('menu.settings.default_tax_id') = 1.
        // Previously, taxes were only seeded in DEMO mode, which made a fresh
        // local install crash during MenuSeeder with tax_id foreign-key errors.
        foreach ($taxes as $tax) {
            Tax::updateOrCreate(
                ['code' => $tax['code']],
                $tax + ['updated_at' => now(), 'created_at' => now()]
            );
        }
    }
}
