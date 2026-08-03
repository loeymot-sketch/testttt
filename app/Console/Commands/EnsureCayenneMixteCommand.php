<?php

namespace App\Console\Commands;

use App\Enums\Status;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * [OWNER CAYENNE-MIXTE 2026-07-31 · RÉÉCRIT] Le Cayenne (pain #22 + galette #24) :
 *   1. « Mixte (hachée + poulet) » = un CHOIX DE VIANDE GRATUIT (price 0), pas un supplément.
 *      L'owner : « viande en plus de ce qui est inclus = payant, MAIS le Cayenne mixte gratuit
 *      comme un choix de viande ». → variation attr1 « Viande 1 » @0, visible_on=null (borne+caisse+web).
 *      Le Cayenne sandwich (#22) est mono-viande SIGNATURE (Poulet mariné, cf. web mkItem 101
 *      viandes:0) : on lui donne un choix LIMITÉ [Poulet mariné (défaut), Mixte] — PAS un
 *      build-your-own. La Galette Cayenne (#24) offre déjà son choix de 7 viandes → on ajoute
 *      seulement « Mixte ».
 *   2. « Sans sauce » = un CHOIX DE SAUCE GRATUIT (price 0) dans l'étape sauce (attr5, min1) : le
 *      client peut explicitement ne pas prendre de sauce.
 *
 * Money-path : tout @0 → rien à facturer (le surplus viande reste l'ItemExtra « Viande supplémentaire »
 * @2,50, INCHANGÉ). Le prix est scellé backend (PricingService lit le prix DB de la variation).
 * DATA only, idempotent (par nom+item+attr), 0 frozen. L'ancien extra pos-only @2,50 « Mixte » n'a
 * jamais été wiré (aucune migration) → rien à nettoyer.
 */
class EnsureCayenneMixteCommand extends Command
{
    protected $signature = 'menu:ensure-cayenne-mixte {--dry-run : Compter sans écrire}';

    protected $description = "Le Cayenne : « Mixte (hachée+poulet) » + « Sans sauce » = choix GRATUITS (variations @0, borne+caisse+web) + backfill viandes #22. Idempotent.";

    public const MIXTE_NAME = 'Mixte (hachée + poulet)';

    public const SANS_SAUCE_NAME = 'Sans sauce';

    /** Viande signature du Cayenne sandwich (#22) — reste le choix par défaut à côté de « Mixte ». */
    public const SIGNATURE_MEAT = 'Poulet mariné';

    /**
     * [OWNER 2026-08-01] 3ᵉ choix de viande du Cayenne sandwich (#22), CAISSE-ONLY comme les
     * deux autres. Le choix complet en caisse devient : Poulet mariné · Mixte · Viande Hachée.
     * Graphie EXACTE de la base — cf. migration 2026_07_27_090000_restore_viande_hachee_variations
     * (« Viande Hachée », V et H majuscules) : toute autre casse créerait un DOUBLON de variation.
     */
    public const HACHEE_NAME = 'Viande Hachée';

    /** Attributs résolus par NOM (robuste aux ids ; le step wizard cible ces refs). */
    public const ATTR_VIANDE_1_NAME = 'Viande 1';

    public const ATTR_SAUCE_NAME = 'Sauce (1ère Gratuite)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $n = self::ensure($dry);
        $this->info(($dry ? '[dry-run] ' : '')."Le Cayenne mixte/sans-sauce — {$n} variation(s) ajoutée(s).");

        return self::SUCCESS;
    }

    /**
     * @return int nombre de variations créées (ou qui seraient créées en dry-run)
     */
    public static function ensure(bool $dryRun = false): int
    {
        $created = 0;

        // Attributs « Viande 1 » / « Sauce (1ère Gratuite) » par NOM (ids stables en prod : 1 & 5,
        // mais on ne les code pas en dur → migration/test robustes). Absent → on saute proprement.
        $viandeAttrId = self::attrIdByName(self::ATTR_VIANDE_1_NAME);
        $sauceAttrId = self::attrIdByName(self::ATTR_SAUCE_NAME);

        // Les 2 Cayenne, par nom (robuste aux ids). #22 = Sandwichs, #24 = Galette.
        $cayennes = DB::table('items')
            ->whereIn('name', ['Cayenne', 'Galette Cayenne'])
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        foreach ($cayennes as $item) {
            if ($viandeAttrId !== null) {
                // Le Cayenne « sandwich » (#22) est mono-viande SIGNATURE (Poulet mariné) — cf. web
                // mkItem 101 viandes:0. L'owner veut y AJOUTER « Mixte » comme choix gratuit, PAS le
                // transformer en build-your-own. → choix LIMITÉ [Poulet mariné (défaut), Mixte].
                // La « Galette Cayenne » (#24) offre déjà son choix de 7 viandes (galette) → on ajoute
                // seulement « Mixte » sans toucher au reste.
                $isFixedMeatSandwich = ($item->name === 'Cayenne');

                if ($isFixedMeatSandwich) {
                    $created += self::ensureVariation($item->id, $viandeAttrId, self::SIGNATURE_MEAT, $dryRun);
                    // [OWNER 2026-08-01] 3ᵉ choix caisse : « Viande Hachée » @0. Le Cayenne sandwich
                    // propose donc en CAISSE : Poulet mariné · Mixte · Viande Hachée (3 choix).
                    // Gratuit comme les deux autres — c'est un CHOIX de viande, pas un supplément ;
                    // le surplus reste l'ItemExtra « Viande supplémentaire » @2,50, INCHANGÉ.
                    //
                    // On RESSUSCITE d'abord une éventuelle ligne soft-supprimée : le nettoyage
                    // `whereNotIn` ci-dessous a très probablement déjà soft-supprimé « Viande Hachée »
                    // sur cet item lors des passages précédents. Sans cette reprise, ensureVariation
                    // (qui n'inspecte que les lignes vivantes) insérerait un DOUBLON à côté de la
                    // ligne morte. Réutiliser la ligne existante préserve aussi son id.
                    if (! $dryRun) {
                        DB::table('item_variations')
                            ->where('item_id', $item->id)
                            ->where('item_attribute_id', $viandeAttrId)
                            ->where('name', self::HACHEE_NAME)
                            ->whereNotNull('deleted_at')
                            ->update(['deleted_at' => null, 'price' => 0, 'status' => Status::ACTIVE, 'updated_at' => now()]);
                    }
                    $created += self::ensureVariation($item->id, $viandeAttrId, self::HACHEE_NAME, $dryRun);
                }
                // « Mixte (hachée + poulet) » @0 dans Viande 1 (choix de viande gratuit, pas supplément).
                $created += self::ensureVariation($item->id, $viandeAttrId, self::MIXTE_NAME, $dryRun);

                if ($isFixedMeatSandwich && ! $dryRun) {
                    // Nettoie tout autre choix de viande (répare un backfill 7-viandes trop large) →
                    // ne garde que les 3 choix voulus. Soft-delete (réversible, jamais commandé).
                    // ⚠️ « Viande Hachée » DOIT figurer ici, sinon la variation qu'on vient de créer
                    // serait soft-supprimée dans la foulée par ce même nettoyage.
                    DB::table('item_variations')
                        ->where('item_id', $item->id)
                        ->where('item_attribute_id', $viandeAttrId)
                        ->whereNotIn('name', [self::SIGNATURE_MEAT, self::MIXTE_NAME, self::HACHEE_NAME])
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => now()]);
                }

                // ═══════════════════════════════════════════════════════════════════════════════
                // [INCIDENT BORNE 2026-08-01] Les choix SUPPLÉMENTAIRES de viande sont CAISSE-ONLY,
                // mais la viande SIGNATURE doit rester visible PARTOUT.
                //
                // Ce qui s'est passé : rendre TOUTES les viandes du #22 `visible_on=['pos']` laissait
                // la borne avec une étape OBLIGATOIRE à ZÉRO option — parce que `min_select` est porté
                // par l'ATTRIBUT « Viande 1 » (app/Models/ItemAttribute.php, colonne partagée par tous
                // les items), PAS par le nombre de variations visibles sur la surface. Résultat en
                // production : « Sélectionnez au moins 1 Viande 1 (actuel : 0) » et **panier
                // impossible à valider sur la borne**. On ne peut pas mettre min_select à 0 : l'attribut
                // est partagé (Galette & co. perdraient leur choix obligatoire de viande).
                //
                // Règle désormais : sur un sandwich mono-viande, « Poulet mariné » reste visible sur
                // TOUTES les surfaces (borne + web + caisse) → la borne a exactement 1 option, le client
                // a TOUJOURS du poulet, et la contrainte min_select=1 est satisfaite. Seuls les choix
                // EN PLUS (Mixte, Viande Hachée) sont réservés à la caisse.
                // #24 Galette : inchangé, SEUL « Mixte » est caisse-only, les 7 viandes restent visibles.
                // ═══════════════════════════════════════════════════════════════════════════════
                if (! $dryRun) {
                    $posOnlyMeats = $isFixedMeatSandwich
                        ? [self::MIXTE_NAME, self::HACHEE_NAME]
                        : [self::MIXTE_NAME];
                    DB::table('item_variations')
                        ->where('item_id', $item->id)
                        ->where('item_attribute_id', $viandeAttrId)
                        ->whereIn('name', $posOnlyMeats)
                        ->whereNull('deleted_at')
                        ->update(['visible_on' => json_encode(['pos'])]);

                    // La signature redevient visible partout (répare l'état posé par c53c7a820).
                    if ($isFixedMeatSandwich) {
                        DB::table('item_variations')
                            ->where('item_id', $item->id)
                            ->where('item_attribute_id', $viandeAttrId)
                            ->where('name', self::SIGNATURE_MEAT)
                            ->whereNull('deleted_at')
                            ->update(['visible_on' => null]);
                    }

                    // GARDE-FOU : ne JAMAIS laisser une étape obligatoire sans aucune option sur la
                    // borne. Si ça arrivait malgré tout, on échoue bruyamment ici plutôt que de laisser
                    // un client bloqué devant la borne, panier plein, sans pouvoir payer.
                    self::assertKioskHasAtLeastOneMeat($item->id, $viandeAttrId, (string) $item->name);
                }
            }

            // « Sans sauce » @0 dans l'étape sauce.
            if ($sauceAttrId !== null) {
                $created += self::ensureVariation($item->id, $sauceAttrId, self::SANS_SAUCE_NAME, $dryRun);
            }
        }

        return $created;
    }

    /**
     * [INCIDENT BORNE 2026-08-01] Garde-fou anti-blocage borne.
     *
     * `min_select` vit sur l'ATTRIBUT (partagé entre items), pas sur le nombre de variations
     * visibles par surface : une étape obligatoire dont TOUTES les options sont `['pos']` rend le
     * panier borne invalidable (« Sélectionnez au moins 1 Viande 1 (actuel : 0) »). Cette garde
     * transforme cette régression silencieuse en échec bruyant, au moment où on l'introduirait.
     *
     * @throws \RuntimeException si la borne n'a plus aucune viande sélectionnable
     */
    private static function assertKioskHasAtLeastOneMeat(int $itemId, int $attrId, string $itemName): void
    {
        $minSelect = (int) DB::table('item_attributes')->where('id', $attrId)->value('min_select');
        if ($minSelect < 1) {
            return; // étape facultative : 0 option visible n'a jamais bloqué personne
        }

        $visibleOnKiosk = DB::table('item_variations')
            ->where('item_id', $itemId)
            ->where('item_attribute_id', $attrId)
            ->whereNull('deleted_at')
            ->get(['visible_on'])
            ->filter(function ($row) {
                if ($row->visible_on === null || $row->visible_on === '') {
                    return true; // null = toutes surfaces
                }
                $surfaces = json_decode((string) $row->visible_on, true);

                return ! is_array($surfaces) || in_array('kiosk', $surfaces, true);
            })
            ->count();

        if ($visibleOnKiosk === 0) {
            throw new \RuntimeException(
                "Blocage borne évité : « {$itemName} » aurait une étape viande OBLIGATOIRE "
                ."(min_select={$minSelect}) sans aucune option visible sur la borne. "
                .'Garde au moins la viande signature visible partout.'
            );
        }
    }

    private static function attrIdByName(string $name): ?int
    {
        $id = DB::table('item_attributes')->where('name', $name)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Insère une variation @0 si absente (idempotent par item+attr+nom). visible_on=null =
     * toutes surfaces (borne+caisse ; le web tire le mapping par nom). status ACTIVE.
     */
    private static function ensureVariation(int $itemId, int $attrId, string $name, bool $dryRun, ?string $imagePath = null): int
    {
        $exists = DB::table('item_variations')
            ->where('item_id', $itemId)
            ->where('item_attribute_id', $attrId)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return 0;
        }

        if ($dryRun) {
            return 1;
        }

        $row = [
            'item_id'           => $itemId,
            'item_attribute_id' => $attrId,
            'name'              => $name,
            'price'             => 0,
            'status'            => Status::ACTIVE,
            'visible_on'        => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ];
        // image_path : présent en prod (MySQL), absent du schéma sqlite de test → conditionnel.
        if (Schema::hasColumn('item_variations', 'image_path')) {
            $row['image_path'] = $imagePath;
        }

        DB::table('item_variations')->insert($row);

        return 1;
    }
}
