<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [GOAL CAISSE/CUISINE #1 2026-10-02] Catégorie « Sauces supplémentaires » — HORS MENU, vendable
 * SEULE ou EN PLUS d'un produit, à côté du « supplément libre » existant.
 *
 * DATA UNIQUEMENT, aucune ligne de PricingService / wizard (zones gelées) : une sauce est un VRAI
 * article du catalogue (`items`), donc facturée par le chemin ordinaire (prix DB × quantité). Vendue
 * seule = un clic en caisse ; « 5 sauces en plus » = quantité 5 de la même ligne, ou plusieurs lignes,
 * à côté du produit dans le même panier. Aucun produit existant n'est touché.
 *
 * « HORS MENU » : `channels = ["pos"]` sur la catégorie ET sur chaque article → la caisse la voit,
 * la borne et le site non (KioskMenuService exige isVisibleOn('kiosk')). La gestion se fait ensuite
 * depuis l'admin (Articles : prix, disponibilité, suppression) sans toucher aux autres produits.
 *
 * SSOT (CLAUDE.md §3bis — « n'inventer aucun produit ») : les noms viennent EXACTEMENT de
 * `config/pos_sauces.php` (catalogue canonique de la carte), « Sans sauce » exclue (ce n'est pas un
 * produit vendable). Prix 0,50 € = celui de l'extra « Sauce supplémentaire » déjà facturé ; le
 * propriétaire le change dans l'admin. TVA : celle des produits qui portent des sauces.
 *
 * Idempotente : catégorie et articles retrouvés par slug, y compris soft-supprimés (restaurés) ;
 * un prix modifié à la main dans l'admin n'est JAMAIS écrasé par un second passage.
 */
return new class extends Migration
{
    private const CATEGORY_SLUG = 'sauces-supplementaires';

    private const CATEGORY_NAME = 'Sauces supplémentaires';

    private const DEFAULT_PRICE = 0.50;

    public function up(): void
    {
        if (! Schema::hasTable('item_categories') || ! Schema::hasTable('items')) {
            return;
        }

        $taxId = $this->saucesTaxId();
        if ($taxId === null) {
            // Base vide (tests / premier démarrage) : rien à rattacher, on n'invente pas de TVA.
            return;
        }

        $now = now();
        $categoryId = $this->ensureCategory($now);

        $sort = 0;
        foreach ((array) config('pos_sauces.catalog', []) as $sauce) {
            $key = (string) ($sauce['key'] ?? '');
            $name = trim((string) ($sauce['name'] ?? ''));
            if ($name === '' || $key === 'sans_sauce') {
                continue;
            }
            $sort++;
            $slug = 'sauce-'.str_replace('_', '-', $key);
            $existing = DB::table('items')->where('slug', $slug)->first();

            if ($existing) {
                // Restaure sans toucher au prix/disponibilité choisis par le propriétaire.
                $update = ['updated_at' => $now];
                if ($existing->deleted_at !== null) {
                    $update['deleted_at'] = null;
                    $update['status'] = 5;
                }
                if ((int) $existing->item_category_id !== $categoryId) {
                    $update['item_category_id'] = $categoryId;
                }
                DB::table('items')->where('id', $existing->id)->update($update);

                continue;
            }

            DB::table('items')->insert([
                'item_category_id' => $categoryId,
                'tax_id' => $taxId,
                'name' => 'Sauce '.$name,
                'slug' => $slug,
                'description' => 'Sauce en supplément, seule ou en plus d\'un produit.',
                'price' => self::DEFAULT_PRICE,
                'status' => 5,
                'channels' => json_encode(['pos']),
                'kds_station' => 'cuisine_chaude',
                'item_type' => 5,
                'order' => $sort,
                'is_featured' => 0,
                'is_upsell' => 0,
                'is_available' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Retrait réversible et sûr : soft-delete des SEULS articles créés ici, jamais de DELETE dur.
        $category = DB::table('item_categories')->where('slug', self::CATEGORY_SLUG)->first();
        if (! $category) {
            return;
        }
        DB::table('items')->where('item_category_id', $category->id)->where('slug', 'like', 'sauce-%')
            ->whereNull('deleted_at')->update(['deleted_at' => now()]);
        DB::table('item_categories')->where('id', $category->id)->update(['deleted_at' => now()]);
    }

    private function ensureCategory($now): int
    {
        $category = DB::table('item_categories')->where('slug', self::CATEGORY_SLUG)->first();
        if ($category) {
            $update = ['updated_at' => $now];
            if ($category->deleted_at !== null) {
                $update['deleted_at'] = null;
                $update['status'] = 5;
            }
            DB::table('item_categories')->where('id', $category->id)->update($update);

            return (int) $category->id;
        }

        $sort = (int) DB::table('item_categories')->max('sort') + 1;

        return (int) DB::table('item_categories')->insertGetId([
            'name' => self::CATEGORY_NAME,
            'slug' => self::CATEGORY_SLUG,
            'description' => 'Sauces en supplément — hors menu, vendues seules ou en plus d\'un produit (caisse).',
            'status' => 5,
            'channels' => json_encode(['pos']),
            'sort' => $sort,
            'wizard_template' => 'simple',
            'has_menu' => 0,
            'default_menu_kiosk' => 0,
            'sauce_included_menu' => 0,
            'kiosk_upsell_include' => 0,
            'kiosk_upsell_skip_after_cart' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** TVA des produits qui portent des sauces (extra « Sauce supplémentaire »), sinon la plus courante. */
    private function saucesTaxId(): ?int
    {
        $fromSauceItems = DB::table('item_extras as e')
            ->join('items as i', 'i.id', '=', 'e.item_id')
            ->where('e.name', 'Sauce supplémentaire')
            ->whereNull('i.deleted_at')
            ->whereNotNull('i.tax_id')
            ->selectRaw('i.tax_id, count(*) as c')
            ->groupBy('i.tax_id')
            ->orderByDesc('c')
            ->value('tax_id');
        if ($fromSauceItems) {
            return (int) $fromSauceItems;
        }

        $common = DB::table('items')->whereNull('deleted_at')->whereNotNull('tax_id')
            ->selectRaw('tax_id, count(*) as c')->groupBy('tax_id')->orderByDesc('c')->value('tax_id');

        return $common ? (int) $common : null;
    }
};
