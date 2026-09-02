<?php

namespace App\Console\Commands;

use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerProfileService;
use App\Services\Composer\WizardPageMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matérialise les pages de wizard d'une ou de toutes les catégories sur leurs produits, puis
 * (sauf `--no-clone`) republie le wizard pour recopier le profil sur chaque produit — ce que la
 * caisse, la borne et PricingService lisent.
 *
 *   php artisan composer:materialize --all --dry-run     # plan complet, zéro écriture
 *   php artisan composer:materialize --category=5        # Tacos, écrit + clones
 *   php artisan composer:materialize --all --no-clone    # choix seulement, pas de republication
 *
 * Idempotente : un second passage annonce « 0 écart ».
 */
class ComposerMaterializeCommand extends Command
{
    protected $signature = 'composer:materialize
                            {--category=* : Identifiant(s) de catégorie}
                            {--all : Toutes les catégories qui ont un wizard}
                            {--dry-run : Affiche le plan sans écrire en base}
                            {--no-clone : Ne pas republier (pas de clone produit)}';

    protected $description = 'Écrit les pages de wizard (choix + prix) sur les produits de chaque catégorie et recopie le profil publié sur chaque produit';

    public function handle(WizardPageMaterializer $materializer, ComposerProfileService $profiles): int
    {
        $dry = (bool) $this->option('dry-run');
        $noClone = (bool) $this->option('no-clone');

        $categoryIds = array_values(array_filter(array_map('intval', (array) $this->option('category'))));
        if ($categoryIds === [] && ! $this->option('all')) {
            $this->error('Précisez --category=<id> (répétable) ou --all.');

            return self::FAILURE;
        }

        $query = ItemCategory::query()->orderBy('id');
        if ($categoryIds !== []) {
            $query->whereIn('id', $categoryIds);
        } else {
            $query->whereIn('id', ItemWizardProfile::query()->whereNotNull('item_category_id')->select('item_category_id'));
        }

        $categories = $query->get();
        if ($categories->isEmpty()) {
            $this->warn('Aucune catégorie avec wizard.');

            return self::SUCCESS;
        }

        $totalChanges = 0;
        foreach ($categories as $category) {
            $published = $profiles->publishedForCategory($category);
            $current = ItemWizardProfile::query()->where('item_category_id', $category->id)->orderByDesc('id')->first();
            $target = $published ?? $current;
            if (! $target) {
                $this->line(sprintf('· %s (#%d) : aucun wizard', $category->name, $category->id));
                continue;
            }

            $this->info(sprintf(
                '· %s (#%d) — profil P%d v%d %s',
                $category->name, $category->id, $target->id, $target->version, $target->is_published ? 'publié' : 'brouillon'
            ));

            // [2026-09-02 · audit adverse P1-1] Hors simulation, écrire les choix puis republier doit
            // être tout-ou-rien : sinon une republication refusée (422) laisse en base des créations,
            // des désactivations et des prix réécrits, pour une opération annoncée comme échouée.
            $refus = null;
            $republication = null;
            $work = function () use ($materializer, $profiles, $category, $target, $published, $dry, $noClone, &$refus, &$republication) {
                $report = $materializer->materializeCategory($category, $target, $dry);
                if ($dry || ! $published || $noClone) {
                    return $report;
                }
                $cov = $profiles->runtimeSnapshot($category)['coverage'];
                if ($cov['covered'] >= $cov['total'] && ! $report->hasChanges()) {
                    return $report;
                }
                try {
                    $fresh = $profiles->publish($published->fresh('steps'));
                    $republication = [$fresh->version, $profiles->runtimeSnapshot($category)['coverage']];
                } catch (ValidationException $e) {
                    $refus = implode(' ', array_map(fn ($m) => implode(' ', (array) $m), $e->errors()));
                    throw $e;
                }

                return $report;
            };

            try {
                $report = $dry ? $work() : DB::transaction($work);
            } catch (ValidationException $e) {
                $this->error(sprintf('· %s (#%d) ⇒ republication refusée, RIEN n\'a été écrit : %s',
                    $category->name, $category->id, $refus ?? ''));
                continue;
            }
            foreach ($report->lines as $line) {
                $this->line($line);
            }
            foreach ($report->warnings as $warning) {
                $this->warn('  ! '.$warning);
            }
            $this->line(sprintf(
                '  = %d produit(s) · %d étape(s) reliée(s) · variations +%d ~%d −%d · extras +%d ~%d −%d · addons +%d −%d · attributs +%d',
                $report->itemsTouched, $report->stepsBound,
                $report->counts['variations_created'], $report->counts['variations_updated'], $report->counts['variations_deactivated'],
                $report->counts['extras_created'], $report->counts['extras_updated'], $report->counts['extras_deactivated'],
                $report->counts['addons_created'], $report->counts['addons_removed'],
                $report->counts['attributes_created']
            ));
            $totalChanges += $report->changes();

            if ($published && ! $noClone) {
                $snapshot = $profiles->runtimeSnapshot($category);
                $cov = $snapshot['coverage'];
                $needsClone = $cov['covered'] < $cov['total'] || $report->hasChanges();
                if ($dry) {
                    $this->line(sprintf('  ⇒ clones : %d/%d à jour, %d périmé(s), %d manquant(s)%s',
                        $cov['covered'], $cov['total'], $cov['stale'], $cov['missing'],
                        $needsClone ? ' — republication nécessaire (simulation)' : ''));
                } elseif ($republication !== null) {
                    [$version, $after] = $republication;
                    $this->line(sprintf('  ⇒ republié v%d — clones %d/%d à jour', $version, $after['covered'], $after['total']));
                    $totalChanges++;
                } else {
                    $this->line(sprintf('  ⇒ clones %d/%d à jour, rien à republier', $cov['covered'], $cov['total']));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('%s — %d changement(s) au total', $dry ? 'SIMULATION' : 'APPLIQUÉ', $totalChanges));

        return self::SUCCESS;
    }
}
