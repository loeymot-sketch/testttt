<?php

namespace App\Console\Commands;

use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Account\AnonymisationCompte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [test-e2e stores S17-003 · 2026-11-13] Rattrapage des comptes CLIENTS supprimés AVANT les correctifs d'effacement
 * (identité réellement effacée le 2026-08-19, complément d'adresse et courses Uber le 2026-11-12). La politique de
 * confidentialité annonce ces données effacées : pour les anciens comptes, elles ne l'étaient pas.
 *
 * Par défaut : SIMULATION, rien n'est écrit — la commande dit ce qu'elle ferait. `--appliquer` écrit, compte par compte,
 * chacun dans sa transaction. Seuls les comptes dont le rôle PRINCIPAL est « Customer » sont touchés (jamais un compte
 * d'exploitation). Rejouable sans effet : un compte déjà propre est compté et passé.
 *
 * À lancer en production UNE fois, juste après le déploiement du backend : `php artisan comptes:rattraper-suppressions`
 * (lecture), puis `php artisan comptes:rattraper-suppressions --appliquer`.
 */
class RattraperSuppressionsComptesCommand extends Command
{
    protected $signature = 'comptes:rattraper-suppressions {--appliquer : écrire (sans cette option : simulation)}';

    protected $description = 'Efface les données personnelles restées sur les comptes clients supprimés avant les correctifs (simulation par défaut).';

    public function handle(AnonymisationCompte $anonymisation): int
    {
        $appliquer = (bool) $this->option('appliquer');
        $bilan = ['comptes' => 0, 'identites' => 0, 'complements' => 0, 'courses' => 0, 'roue' => 0, 'ignores_non_clients' => 0];

        User::withoutGlobalScope(BranchScope::class)->onlyTrashed()->with('roles')->orderBy('id')
            ->chunkById(200, function ($comptes) use ($anonymisation, $appliquer, &$bilan) {
                foreach ($comptes as $compte) {
                    $premier = $compte->roles->first();
                    if (! $premier || $premier->name !== 'Customer') {
                        $bilan['ignores_non_clients']++;
                        continue;
                    }
                    $bilan['comptes']++;
                    $identite = ! $anonymisation->identiteDejaEffacee($compte);
                    $complements = DB::table('order_addresses')->where('user_id', $compte->id)
                        ->where(function ($q) { $q->whereNotNull('apartment')->orWhere('label', '!=', 'Livraison'); })->count();
                    // [test-e2e stores S18-002] Aussi la réponse BRUTE d'Uber (delivery_payload) : un webhook reçu après la suppression
                    //   y remettait nom et téléphone du destinataire, invisibles pour un contrôle limité aux colonnes.
                    $courses = DB::table('uber_direct_deliveries')
                        ->whereIn('order_id', DB::table('orders')->where('user_id', $compte->id)->select('id'))
                        ->get(['dropoff_phone', 'dropoff_instructions', 'delivery_payload'])
                        ->filter(function ($c) {
                            if ($c->dropoff_phone !== null || $c->dropoff_instructions !== null) {
                                return true;
                            }
                            $brut = json_decode((string) $c->delivery_payload, true);
                            if (! is_array($brut)) {
                                return false;
                            }
                            $d = is_array($brut['dropoff'] ?? null) ? $brut['dropoff'] : [];

                            return isset($d['name']) || isset($d['phone_number']) || isset($d['notes'])
                                || isset($brut['dropoff_name']) || isset($brut['dropoff_phone_number']) || isset($brut['dropoff_notes']);
                        })->count();
                    // [P18-002] Tours de roue crédités au compte qui gardent encore nom / e-mail / téléphone.
                    $roue = \Illuminate\Support\Facades\Schema::hasColumn('wheel_spins', 'points_credited_user_id')
                        ? DB::table('wheel_spins')->where('points_credited_user_id', $compte->id)
                            ->where(function ($q) { $q->whereNotNull('customer_name')->orWhere('phone', 'not like', 'SUPPRIME-%'); })->count()
                        : 0;
                    $bilan['roue'] += $roue;
                    $bilan['identites'] += $identite ? 1 : 0;
                    $bilan['complements'] += $complements;
                    $bilan['courses'] += $courses;

                    if ($appliquer && ($identite || $complements || $courses || $roue)) {
                        DB::transaction(function () use ($anonymisation, $compte, $identite) {
                            if ($identite) {
                                $anonymisation->anonymiserIdentite($compte);
                                $compte->tokens()->delete();
                            }
                            $anonymisation->effacerResidus((int) $compte->id);
                        });
                    }
                }
            });

        $this->info(($appliquer ? 'EFFACÉ' : 'SIMULATION (rien écrit)') . ' — comptes clients supprimés : ' . $bilan['comptes']
            . ' ; identités encore lisibles : ' . $bilan['identites']
            . ' ; compléments/libellés d\'adresse : ' . $bilan['complements']
            . ' ; courses Uber avec téléphone/consignes : ' . $bilan['courses']
            . ' ; tours de roue identifiants : ' . $bilan['roue']
            . ' ; comptes non clients ignorés : ' . $bilan['ignores_non_clients'] . '.');
        if (! $appliquer && ($bilan['identites'] || $bilan['complements'] || $bilan['courses'] || $bilan['roue'])) {
            $this->line('Relancer avec --appliquer pour effacer.');
        }

        return self::SUCCESS;
    }
}
