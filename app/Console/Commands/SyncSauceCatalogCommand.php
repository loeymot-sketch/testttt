<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\ItemVariation;
use App\Support\Menu\SauceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL WIZARD-CAISSE 2026-08-28 · owner] Aligne les listes de sauces de TOUS
 * les articles sur le catalogue canonique de `config/pos_sauces.php`.
 *
 * Pourquoi : chaque article porte sa PROPRE copie des sauces dans
 * `item_variations`. Mesuré le 2026-08-28 sur les 59 articles vendables, cela
 * avait dérivé en cinq profils incompatibles — dont 13 articles sans « Sans
 * sauce » et deux bols avec DEUX sauces au lieu de treize.
 *
 * Ce que la commande fait, uniquement sur les articles qui ont DÉJÀ un
 * attribut sauce (5 ou 8) :
 *   1. renomme les libellés alias vers le libellé canonique
 *      (« Sauce fromagère maison » → « Fromagère maison ») ;
 *   2. réactive une sauce canonique désactivée (status 10 → 5) au lieu d'en
 *      créer un doublon ;
 *   3. crée les sauces canoniques manquantes (prix 0, status ACTIVE).
 *
 * Ce qu'elle NE fait PAS, volontairement :
 *   · elle n'ajoute d'attribut sauce à AUCUN article qui n'en a pas (un Coca
 *     ne doit pas se retrouver avec une liste de sauces) ;
 *   · elle ne supprime ni ne désactive aucune ligne existante — une sauce
 *     hors catalogue reste servie, elle est seulement signalée en sortie.
 *
 * Idempotente : un second passage ne modifie plus rien.
 */
class SyncSauceCatalogCommand extends Command
{
    protected $signature = 'foodking:sauces:sync
                            {--dry-run : Affiche le plan sans écrire en base}';

    protected $description = 'Aligne les sauces de chaque article sur le catalogue canonique (config/pos_sauces.php)';

    public function handle(): int
    {
        $attrIds = SauceCatalog::attributeIds();
        $catalog = SauceCatalog::all();
        $dry     = (bool) $this->option('dry-run');

        if (empty($attrIds) || empty($catalog)) {
            $this->error('config/pos_sauces.php est vide — rien à synchroniser.');

            return self::FAILURE;
        }

        // Articles concernés = ceux qui portent DÉJÀ au moins une sauce.
        // Les articles SUPPRIMÉS (soft-delete) sont exclus : leur recréer treize
        // sauces ne sert à rien et pollue l'inventaire de variations (le dépôt
        // contient des fixtures E2E supprimées qui portent un attribut sauce).
        // Un article seulement DÉSACTIVÉ (status 10) est en revanche traité :
        // il reste au menu et doit être correct le jour où on le rallume.
        $pairs = ItemVariation::query()
            ->whereIn('item_attribute_id', $attrIds)
            ->whereIn('item_id', function ($q) {
                $q->select('id')->from('items')->whereNull('deleted_at');
            })
            ->select('item_id', 'item_attribute_id')
            ->distinct()
            ->get();

        // Exceptions déclarées : articles qui doivent porter la carte des sauces
        // alors qu'ils n'ont encore aucune variation sauce (cf. `force_attach`).
        foreach ((array) config('pos_sauces.force_attach', []) as $forced) {
            $itemId = (int) ($forced['item_id'] ?? 0);
            $attrId = (int) ($forced['attribute_id'] ?? 0);
            if ($itemId <= 0 || $attrId <= 0) {
                continue;
            }
            $already = $pairs->contains(
                fn ($p) => (int) $p->item_id === $itemId && (int) $p->item_attribute_id === $attrId
            );
            if (!$already) {
                $pairs->push((object) ['item_id' => $itemId, 'item_attribute_id' => $attrId]);
                $this->line(sprintf('  ★ item %d/attr %d : rattachement forcé (config force_attach)', $itemId, $attrId));
            }
        }

        if ($pairs->isEmpty()) {
            $this->warn('Aucun article ne porte d\'attribut sauce — rien à faire.');

            return self::SUCCESS;
        }

        $renamed = $reactivated = $created = 0;
        $unknown = [];

        $apply = function () use ($pairs, $attrIds, $catalog, $dry, &$renamed, &$reactivated, &$created, &$unknown) {
            foreach ($pairs as $pair) {
                $itemId = (int) $pair->item_id;
                $attrId = (int) $pair->item_attribute_id;

                $existing = ItemVariation::query()
                    ->where('item_id', $itemId)
                    ->where('item_attribute_id', $attrId)
                    ->get();

                // Libellé canonique déjà présent (actif ou non) → clé de dédoublonnage.
                $seen = [];
                foreach ($existing as $row) {
                    $entry = SauceCatalog::match($row->name);
                    if ($entry === null) {
                        $unknown[$row->name] = ($unknown[$row->name] ?? 0) + 1;
                        continue;
                    }
                    $key = $entry['key'];

                    // 1. Normalisation du libellé.
                    if ($row->name !== $entry['name'] && !isset($seen[$key])) {
                        $this->line(sprintf(
                            '  ~ item %d/attr %d : « %s » → « %s »',
                            $itemId, $attrId, $row->name, $entry['name']
                        ));
                        if (!$dry) {
                            $row->name = $entry['name'];
                            $row->save();
                        }
                        $renamed++;
                    }

                    // 2. Réactivation plutôt que doublon.
                    if (!isset($seen[$key]) && (int) $row->status !== Status::ACTIVE) {
                        $this->line(sprintf(
                            '  ↑ item %d/attr %d : « %s » réactivée (status %d → %d)',
                            $itemId, $attrId, $entry['name'], (int) $row->status, Status::ACTIVE
                        ));
                        if (!$dry) {
                            $row->status = Status::ACTIVE;
                            $row->save();
                        }
                        $reactivated++;
                    }

                    $seen[$key] = true;
                }

                // 3. Création des sauces canoniques absentes.
                foreach ($catalog as $entry) {
                    if (isset($seen[$entry['key']])) {
                        continue;
                    }
                    $this->line(sprintf(
                        '  + item %d/attr %d : « %s » ajoutée',
                        $itemId, $attrId, $entry['name']
                    ));
                    if (!$dry) {
                        ItemVariation::create([
                            'item_id'           => $itemId,
                            'item_attribute_id' => $attrId,
                            'name'              => $entry['name'],
                            'price'             => 0,
                            'status'            => Status::ACTIVE,
                        ]);
                    }
                    $created++;
                }
            }
        };

        if ($dry) {
            $this->info('— MODE SIMULATION (--dry-run) : aucune écriture —');
            $apply();
        } else {
            DB::transaction($apply);
        }

        $this->newLine();
        $this->info(sprintf(
            '%s — %d article/attribut traités · %d renommées · %d réactivées · %d créées',
            $dry ? 'SIMULATION' : 'APPLIQUÉ',
            $pairs->count(), $renamed, $reactivated, $created
        ));

        if (!empty($unknown)) {
            $this->newLine();
            $this->warn('Sauces PRÉSENTES EN BASE mais ABSENTES du catalogue (laissées intactes, à arbitrer) :');
            foreach ($unknown as $name => $count) {
                $this->warn(sprintf('  · « %s » (%d ligne%s)', $name, $count, $count > 1 ? 's' : ''));
            }
        }

        return self::SUCCESS;
    }
}
