<?php

namespace App\Console\Commands;

use App\Enums\Ask;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Services\Identity\PhoneIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RENDRE À UN CLIENT LES POINTS DE SON AUTRE COMPTE.
 *
 * ── LE PROBLÈME QU'ELLE RÉPARE ───────────────────────────────────────────────────────────────
 * « 06 12 34 56 78 », « +33612345678 » et « 612345678 » désignent le même humain. Tant que les
 * surfaces cherchaient l'écriture EXACTE tapée, un client inscrit sous une forme et retrouvé
 * sous une autre repartait avec un compte neuf — et ses points restaient sur le premier, sans
 * que personne ne s'en aperçoive. Les surfaces sont corrigées (`PhoneIdentity` partout), mais
 * les comptes DÉJÀ créés, eux, sont toujours là.
 *
 * ── CE QU'ELLE FAIT, ET CE QU'ELLE NE FAIT PAS ───────────────────────────────────────────────
 * Elle DÉPLACE les points des comptes secondaires vers le principal, PAR LE GRAND-LIVRE : une
 * ligne de retrait sur l'un, une ligne d'ajout sur l'autre. Jamais un `UPDATE` brut sur un
 * solde — la leçon du 2026-08-14, où un solde avait été réparé sans écriture et où plus personne
 * ne pouvait dire d'où venait le chiffre.
 *
 * Elle NE SUPPRIME AUCUN COMPTE. Un compte porte des commandes, des consentements, parfois un
 * historique fiscal ; le détruire pour ranger des points serait échanger un désordre visible
 * contre une perte invisible. Les comptes secondaires restent, à zéro point, et les surfaces
 * présenteront désormais le principal.
 *
 * ── COMMENT ELLE CHOISIT LE PRINCIPAL ────────────────────────────────────────────────────────
 * Dans cet ordre : un VRAI compte client (`is_guest = NO`, il a un mot de passe et se connecte)
 * l'emporte toujours sur un talon ; puis celui qui a le plus d'histoire au grand-livre ; puis le
 * plus de points ; puis le plus ancien. On ne fusionne jamais un vrai compte DANS un talon.
 *
 * Par défaut elle ne fait que MONTRER. `--apply` exécute.
 */
class LoyaltyMergeDuplicatePhonesCommand extends Command
{
    protected $signature = 'fidelite:fusionner-doublons
                            {--apply : Exécute réellement la fusion (sinon simple aperçu)}
                            {--phone= : Ne traiter qu\'un seul numéro}';

    protected $description = 'Regroupe les comptes fidélité d\'un même téléphone et rend les points au compte principal (aperçu par défaut).';

    public function handle(): int
    {
        $tel = app(PhoneIdentity::class);
        $appliquer = (bool) $this->option('apply');
        $filtre = $this->option('phone');

        $groupes = [];
        User::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('id', 'name', 'phone', 'email', 'is_guest', 'loyalty_code', 'loyalty_points', 'created_at')
            ->orderBy('id')
            ->chunk(500, function ($lot) use (&$groupes, $tel) {
                foreach ($lot as $u) {
                    if (! $tel->looksComplete((string) $u->phone)) {
                        continue;
                    }
                    if (! $this->estUnCompteClient($u)) {
                        continue;
                    }
                    $groupes[$tel->normalize((string) $u->phone)][] = $u;
                }
            });

        if ($filtre) {
            $canonique = $tel->normalize((string) $filtre);
            $groupes = array_filter($groupes, static fn ($k) => $k === $canonique, ARRAY_FILTER_USE_KEY);
        }

        $doublons = array_filter($groupes, static fn ($g) => count($g) > 1);

        if (empty($doublons)) {
            $this->info('✔ Aucun client présent en plusieurs exemplaires.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d numéro(s) portent plusieurs comptes.', count($doublons)));
        if (! $appliquer) {
            $this->warn('APERÇU — rien ne sera modifié. Ajoutez --apply pour exécuter.');
        }
        $this->newLine();

        $pointsDeplaces = 0;
        $fusions = 0;

        foreach ($doublons as $canonique => $comptes) {
            $principal = $this->choisirPrincipal($comptes);
            $secondaires = array_values(array_filter($comptes, static fn ($u) => $u->id !== $principal->id));

            $aDeplacer = array_sum(array_map(static fn ($u) => max(0, (int) $u->loyalty_points), $secondaires));

            $this->line(sprintf(
                '%s → principal #%d (%s, %d pts%s)',
                $tel->masked((string) $canonique),
                $principal->id,
                $principal->name ?: '—',
                (int) $principal->loyalty_points,
                (int) $principal->is_guest === Ask::NO ? ', compte plein' : ''
            ));

            foreach ($secondaires as $s) {
                $this->line(sprintf(
                    '    ↳ #%d %s : %d pts%s',
                    $s->id,
                    $s->name ?: '—',
                    (int) $s->loyalty_points,
                    (int) $s->loyalty_points > 0 ? ' → transférés' : ' (rien à transférer)'
                ));
            }

            if ($aDeplacer <= 0) {
                // Rien à déplacer : signaler le doublon suffit. Toucher des comptes vides
                // n'apporte rien et ajoute du bruit dans un grand-livre à vocation comptable.
                continue;
            }

            $fusions++;
            $pointsDeplaces += $aDeplacer;

            if ($appliquer) {
                $this->fusionner($principal, $secondaires, (string) $canonique);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '%s : %d fusion(s), %d point(s) rendus à leur propriétaire.',
            $appliquer ? 'FAIT' : 'À FAIRE',
            $fusions,
            $pointsDeplaces
        ));

        if (! $appliquer && $fusions > 0) {
            $this->line('   Exécuter réellement : php artisan fidelite:fusionner-doublons --apply');
        }

        return self::SUCCESS;
    }

    /**
     * ON NE TOUCHE QU'AUX COMPTES DE CLIENTS. JAMAIS AU PERSONNEL.
     *
     * Constaté en lançant l'aperçu sur la vraie base : les numéros en double y sont surtout des
     * comptes de STAFF (caissier, livreur, écran cuisine) qui partagent un numéro de test. Sans
     * cette garde, le jour où l'exploitant ou un caissier partage son numéro avec un client — ce
     * qui arrive dans un restaurant de quartier — la commande transférerait les points DU CLIENT
     * vers le compte DU PERSONNEL, en écrivant proprement au grand-livre que c'était voulu.
     *
     * Un compte de fidélité, c'est un compte sans rôle d'exploitation. Le doute profite au
     * client : au moindre rôle, on s'abstient.
     */
    private function estUnCompteClient(User $u): bool
    {
        // La règle vit sur le modèle (`User::isLoyaltyCustomer`) : écrite une seconde fois ici,
        // elle avait déjà divergé du vérificateur de santé le jour même de sa naissance.
        return $u->isLoyaltyCustomer();
    }

    /**
     * @param  array<int, User>  $comptes
     */
    private function choisirPrincipal(array $comptes): User
    {
        $historique = [];
        foreach ($comptes as $u) {
            $historique[$u->id] = LoyaltyTransaction::where('user_id', $u->id)->count();
        }

        usort($comptes, static function (User $a, User $b) use ($historique) {
            // Un VRAI compte client l'emporte toujours : il a un mot de passe, il se connecte,
            // c'est lui que le client considère comme « son compte ».
            $plein = ((int) $b->is_guest === Ask::NO) <=> ((int) $a->is_guest === Ask::NO);
            if ($plein !== 0) {
                return $plein;
            }
            $hist = $historique[$b->id] <=> $historique[$a->id];
            if ($hist !== 0) {
                return $hist;
            }
            $pts = (int) $b->loyalty_points <=> (int) $a->loyalty_points;
            if ($pts !== 0) {
                return $pts;
            }

            return $a->id <=> $b->id; // à égalité, le plus ancien
        });

        return $comptes[0];
    }

    /**
     * LE TRANSFERT, ÉCRIT DES DEUX CÔTÉS.
     *
     * Une seule transaction : un incident au milieu laisserait des points retirés à quelqu'un et
     * jamais rendus à personne. Et deux écritures, pas une — le grand-livre doit pouvoir répondre
     * « d'où viennent ces points ? » aussi bien que « où sont passés les miens ? ».
     *
     * @param  array<int, User>  $secondaires
     */
    private function fusionner(User $principal, array $secondaires, string $canonique): void
    {
        /*
         * [2026-08-19] AUCUN `withoutGlobalScopes()` ICI, ET C'EST VOLONTAIRE.
         *
         * La première version en posait cinq, par réflexe « commande d'administration ». La
         * sentinelle `WithoutGlobalScopesAuditSentinelTest` les a refusés — à raison : chaque
         * contournement d'isolation doit être justifié un par un, et l'inscrire sur une liste
         * d'exceptions aurait été traiter l'alarme au lieu de la cause.
         *
         * Ils n'étaient pas nécessaires : un client porte `branch_id = 0`, et `BranchScope` est
         * un no-op explicite sur le modèle `User` (CLAUDE.md §9). Ne pas contourner est donc à la
         * fois plus sûr et plus simple.
         */

        DB::transaction(function () use ($principal, $secondaires, $canonique) {
            $principalFrais = User::whereKey($principal->id)->lockForUpdate()->first();
            $solde = (int) $principalFrais->loyalty_points;

            foreach ($secondaires as $s) {
                $source = User::whereKey($s->id)->lockForUpdate()->first();
                $points = (int) $source->loyalty_points;
                if ($points <= 0) {
                    continue;
                }

                // 1) Retrait chez le doublon — solde à zéro, et la ligne dit pourquoi.
                User::whereKey($source->id)->update([
                    'loyalty_points' => 0,
                    'updated_at' => now(),
                ]);
                LoyaltyTransaction::create([
                    'user_id' => $source->id,
                    'loyalty_code' => $source->loyalty_code,
                    'order_id' => null,
                    'type' => 'manual_deduct',
                    'points' => -$points,
                    'balance_after' => 0,
                    'source_surface' => 'admin',
                    'description' => 'Regroupement de comptes : points transferes vers #'.$principal->id,
                ]);

                // 2) Ajout chez le principal.
                $solde += $points;
                User::whereKey($principal->id)->update([
                    'loyalty_points' => $solde,
                    'updated_at' => now(),
                ]);
                LoyaltyTransaction::create([
                    'user_id' => $principal->id,
                    'loyalty_code' => $principalFrais->loyalty_code,
                    'order_id' => null,
                    'type' => 'manual_add',
                    'points' => $points,
                    'balance_after' => $solde,
                    'source_surface' => 'admin',
                    'description' => 'Regroupement de comptes : points recuperes du compte #'.$source->id,
                ]);

                Log::info('[Loyalty] Fusion de comptes en double', [
                    'canonique' => substr($canonique, -4),
                    'source' => $source->id,
                    'principal' => $principal->id,
                    'points' => $points,
                ]);
            }

            // Le principal porte désormais la forme canonique : le prochain client qui donne son
            // numéro tombe sur LUI, quelle que soit l'écriture qu'il présente.
            User::whereKey($principal->id)->update([
                'phone' => $canonique,
                'updated_at' => now(),
            ]);
        });
    }
}
