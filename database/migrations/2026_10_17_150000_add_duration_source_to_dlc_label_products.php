<?php

use App\Models\DlcLabelProduct;
use App\Support\Labels\RecommendedDlcCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier P0.6] D'où vient chaque durée de conservation ?
 *
 *   · dlc_label_products.duration_source — texte libre court : « Valeur de départ Cayenne » (catalogue installé, À VALIDER),
 *                                          « GBPH restauration », « Plan de maîtrise sanitaire », « Fabricant »… Chaque ligne
 *                                          du catalogue dit d'où sort sa durée ; la validation par le responsable
 *                                          (`durations_checked_at / _by`, déjà présentes) dit qui l'a confirmée et quand.
 *
 * Additif : une colonne nullable (NULL = « saisie par le restaurant »). UN SEUL rattrapage de données : les produits du
 * catalogue recommandé DÉJÀ installés avant cette colonne (jamais validés, durées encore celles du catalogue) sont marqués
 * « Valeur de départ Cayenne » — sans cela ils passeraient pour des durées du restaurant et plus rien ne dirait qu'elles
 * attendent d'être validées. Un produit fait à la main, ou dont une durée a été changée, ou déjà validé, n'est pas touché.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dlc_label_products')) {
            return;
        }

        Schema::table('dlc_label_products', function (Blueprint $table) {
            if (! Schema::hasColumn('dlc_label_products', 'duration_source')) $table->string('duration_source', 80)->nullable()->after('durations_checked_by');
        });

        $this->marquerLesValeursDeDepart();
    }

    private function marquerLesValeursDeDepart(): void
    {
        $catalogue = [];
        foreach (RecommendedDlcCatalog::all() as $row) {
            $catalogue[DlcLabelProduct::nameKey($row['name'])] = array_map(
                fn (array $a): array => [$a['key'], (int) $a['value'], $a['unit']],
                $row['actions']
            );
        }

        DlcLabelProduct::withoutGlobalScopes()
            ->whereNull('duration_source')
            ->whereNull('durations_checked_at')
            ->get()
            ->each(function (DlcLabelProduct $p) use ($catalogue): void {
                $depart = $catalogue[DlcLabelProduct::nameKey($p->name)] ?? null;
                if ($depart === null) return;
                $siennes = array_map(fn (array $a): array => [$a['key'], (int) $a['value'], $a['unit']], $p->actionList());
                if ($siennes !== $depart) return;
                // DB::table : ne touche pas updated_at (la fiche n'a pas été modifiée par quelqu'un)
                DB::table('dlc_label_products')->where('id', $p->id)->update(['duration_source' => DlcLabelProduct::SOURCE_STARTING]);
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('dlc_label_products') && Schema::hasColumn('dlc_label_products', 'duration_source')) {
            Schema::table('dlc_label_products', fn (Blueprint $table) => $table->dropColumn('duration_source'));
        }
    }
};
