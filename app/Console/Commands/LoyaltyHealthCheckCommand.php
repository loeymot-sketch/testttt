<?php

namespace App\Console\Commands;

use App\Services\Identity\PhoneIdentity;
use App\Services\Loyalty\LoyaltyRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LA SANTÉ DU PROGRAMME DE FIDÉLITÉ, EN UNE COMMANDE.
 *
 * ── POURQUOI CETTE COMMANDE EXISTE ───────────────────────────────────────────────────────────
 * La fidélité, c'est de l'argent qui ne se voit pas. Un solde qui dérive, un client coupé en deux
 * comptes, une vente qui aurait dû créditer et n'a rien crédité : rien de tout cela ne déclenche
 * d'erreur, rien ne s'affiche nulle part, et personne ne s'en aperçoit — jusqu'au jour où un
 * client réclame et où l'exploitant n'a rien à lui montrer.
 *
 * Le 2026-08-19, en cherchant à la main, on a trouvé sur la base réelle : 1817 ventes de caisse
 * dont 12 rattachées à un client, 6 numéros de téléphone portant plusieurs comptes (dont un avec
 * 500 points d'un côté et 0 de l'autre), et 10 comptes dont le solde ne correspond pas à leur
 * grand-livre. Aucun de ces chiffres n'était visible avant qu'on écrive la requête.
 *
 * Cette commande transforme cette fouille en geste répétable : `php artisan fidelite:verifier`.
 * Elle ne modifie RIEN — elle regarde et elle raconte. Les corrections ont leurs propres
 * commandes, parce qu'un outil qui diagnostique et répare dans le même geste finit toujours par
 * réparer ce qu'il a mal diagnostiqué.
 *
 * Code de sortie 1 s'il y a au moins une anomalie : utilisable dans un cron ou une CI.
 */
class LoyaltyHealthCheckCommand extends Command
{
    protected $signature = 'fidelite:verifier
                            {--details=8 : Nombre de lignes détaillées par anomalie}';

    protected $description = 'Vérifie la santé du programme de fidélité (soldes, doublons, ventes non créditées). Ne modifie rien.';

    public function handle(): int
    {
        $details = max(1, (int) $this->option('details'));
        $regles = app(LoyaltyRules::class);
        $anomalies = 0;

        $this->info('══ SANTÉ DU PROGRAMME DE FIDÉLITÉ ══');
        $this->line(sprintf(
            'Barème : %d point(s) par euro dépensé · %d points = 1 € de remise · utilisable à partir de %d points (%s €).',
            $regles->pointsPerEuro(),
            $regles->rate(),
            $regles->effectiveFloor(),
            number_format($regles->euroValue($regles->effectiveFloor()), 2, ',', ' ')
        ));
        $this->newLine();

        $anomalies += $this->soldesDivergents($details, $regles);
        $anomalies += $this->comptesEnDouble($details);
        $anomalies += $this->ventesNonCreditees($details);
        $anomalies += $this->pointsInatteignables($details);
        $anomalies += $this->adoptionDuProgramme();

        $this->newLine();
        if ($anomalies === 0) {
            $this->info('✔ Aucune anomalie détectée.');

            return self::SUCCESS;
        }

        $this->warn("⚠ {$anomalies} famille(s) d'anomalie détectée(s) — voir le détail ci-dessus.");

        return self::FAILURE;
    }

    /**
     * LE SOLDE D'UN CLIENT DOIT ÊTRE LA SOMME DE SON HISTOIRE.
     *
     * S'ils divergent, l'un des deux ment. Le grand-livre est la pièce qu'on présente au client
     * qui conteste : un solde qu'il n'explique pas est un solde indéfendable.
     */
    private function soldesDivergents(int $details, LoyaltyRules $regles): int
    {
        $lignes = DB::select('
            SELECT u.id, u.name, u.phone, u.loyalty_code,
                   u.loyalty_points AS solde,
                   COALESCE(SUM(t.points), 0) AS livre,
                   COUNT(t.id) AS n
            FROM users u
            LEFT JOIN loyalty_transactions t ON t.user_id = u.id
            WHERE u.loyalty_code IS NOT NULL
            GROUP BY u.id, u.name, u.phone, u.loyalty_code, u.loyalty_points
            HAVING u.loyalty_points <> COALESCE(SUM(t.points), 0)
        ');

        if (empty($lignes)) {
            $this->info('✔ Soldes : tous cohérents avec le grand-livre.');

            return 0;
        }

        $ecart = array_sum(array_map(static fn ($l) => abs($l->solde - $l->livre), $lignes));

        $this->warn(sprintf(
            '✖ Soldes incohérents : %d compte(s), %d point(s) d\'écart cumulé (≈ %s €).',
            count($lignes),
            $ecart,
            number_format($regles->euroValue((int) $ecart), 2, ',', ' ')
        ));
        $this->line('   Un solde antérieur à la mise en place du grand-livre explique une partie de ces écarts ;');
        $this->line('   un écart NOUVEAU, lui, signale un chemin qui déplace des points sans les écrire.');

        usort($lignes, static fn ($a, $b) => abs($b->solde - $b->livre) <=> abs($a->solde - $a->livre));
        $this->table(
            ['id', 'client', 'code', 'solde', 'grand-livre', 'écart', 'lignes'],
            array_map(static fn ($l) => [
                $l->id,
                mb_substr((string) $l->name, 0, 22),
                $l->loyalty_code,
                $l->solde,
                $l->livre,
                $l->solde - $l->livre,
                $l->n,
            ], array_slice($lignes, 0, $details))
        );

        return 1;
    }

    /**
     * UN CLIENT, DEUX COMPTES : SES POINTS SONT SUR CELUI QU'IL NE PRÉSENTE PAS.
     *
     * « 06 … », « +33 6 … » et « 6 … » désignent la même personne. Les surfaces cherchent
     * désormais toutes les écritures (`PhoneIdentity`), mais les doublons DÉJÀ créés restent :
     * il faut les fusionner, et `fidelite:fusionner-doublons` est là pour ça.
     */
    private function comptesEnDouble(int $details): int
    {
        $tel = app(PhoneIdentity::class);
        $groupes = [];

        DB::table('users')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('id', 'name', 'phone', 'loyalty_code', 'loyalty_points')
            ->orderBy('id')
            ->chunk(500, function ($lot) use (&$groupes, $tel) {
                foreach ($lot as $u) {
                    $canonique = $tel->normalize((string) $u->phone);
                    if (! $tel->looksComplete((string) $u->phone)) {
                        continue;
                    }
                    $groupes[$canonique][] = $u;
                }
            });

        $doublons = array_filter($groupes, static fn ($g) => count($g) > 1);

        if (empty($doublons)) {
            $this->info('✔ Téléphones : aucun client présent en plusieurs exemplaires.');

            return 0;
        }

        // Ce qui est VRAIMENT en jeu : les points portés par les comptes qui ne sont pas le
        // principal. Un doublon à 0 point partout est un désordre ; un doublon avec des points
        // d'un seul côté est une perte pour le client.
        $pointsEnJeu = 0;
        foreach ($doublons as $g) {
            $soldes = array_map(static fn ($u) => (int) $u->loyalty_points, $g);
            rsort($soldes);
            $pointsEnJeu += array_sum(array_slice($soldes, 1));
        }

        $this->warn(sprintf(
            '✖ Téléphones en double : %d numéro(s) portent plusieurs comptes, %d point(s) sur les comptes secondaires.',
            count($doublons),
            $pointsEnJeu
        ));
        $this->line('   → réparable sans perte : php artisan fidelite:fusionner-doublons');

        $rendu = [];
        foreach (array_slice($doublons, 0, $details, true) as $canonique => $g) {
            $rendu[] = [
                $tel->masked($canonique),
                count($g),
                implode(' | ', array_map(static fn ($u) => "#{$u->id}:{$u->loyalty_points}pts", $g)),
            ];
        }
        $this->table(['numéro', 'comptes', 'détail'], $rendu);

        return 1;
    }

    /**
     * UNE VENTE RATTACHÉE À UN CLIENT QUI N'A JAMAIS CRÉDITÉ.
     *
     * C'est la perte la plus injuste : le caissier a fait le geste, le client a vu son nom sur le
     * ticket, et rien n'est arrivé sur son compte. La sentinelle du crédit
     * (`loyalty_points_awarded`) reste NULLE alors que la commande est servie.
     */
    private function ventesNonCreditees(int $details): int
    {
        $servies = [8, 13]; // PREPARED, DELIVERED — les deux états qui déclenchent le crédit

        $lignes = DB::table('orders')
            ->whereNotNull('loyalty_customer_code')
            ->whereIn('status', $servies)
            ->whereNull('loyalty_points_awarded')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'order_serial_no', 'loyalty_customer_code', 'total', 'status', 'created_at']);

        if ($lignes->isEmpty()) {
            $this->info('✔ Ventes rattachées : toutes ont crédité leur client.');

            return 0;
        }

        $this->warn(sprintf(
            '✖ Ventes rattachées NON créditées : %d (le client a donné son numéro et n\'a rien reçu).',
            $lignes->count()
        ));

        $this->table(
            ['commande', 'code client', 'total', 'statut', 'date'],
            $lignes->take($details)->map(static fn ($o) => [
                $o->order_serial_no ?: $o->id,
                $o->loyalty_customer_code,
                number_format((float) $o->total, 2, ',', ' ') . ' €',
                $o->status,
                (string) $o->created_at,
            ])->all()
        );

        return 1;
    }

    /**
     * DES POINTS QUE PERSONNE NE PEUT DÉPENSER.
     *
     * Un compte porteur de points mais sans code fidélité, ou dans un statut que les surfaces
     * refusent, c'est de l'argent bloqué : le client y a droit, aucune caisse ne le lui rendra.
     */
    private function pointsInatteignables(int $details): int
    {
        $sansCode = DB::table('users')
            ->where('loyalty_points', '>', 0)
            ->where(function ($q) {
                $q->whereNull('loyalty_code')->orWhere('loyalty_code', '');
            })
            ->get(['id', 'name', 'phone', 'loyalty_points']);

        $statutRefuse = DB::table('users')
            ->where('loyalty_points', '>', 0)
            ->whereNotNull('loyalty_code')
            ->whereNotIn('status', [1, 5])
            ->get(['id', 'name', 'loyalty_code', 'loyalty_points', 'status']);

        if ($sansCode->isEmpty() && $statutRefuse->isEmpty()) {
            $this->info('✔ Points bloqués : aucun.');

            return 0;
        }

        $this->warn(sprintf(
            '✖ Points inatteignables : %d compte(s) sans code (%d pts), %d compte(s) au statut refusé (%d pts).',
            $sansCode->count(),
            (int) $sansCode->sum('loyalty_points'),
            $statutRefuse->count(),
            (int) $statutRefuse->sum('loyalty_points')
        ));

        foreach ($sansCode->take($details) as $u) {
            $this->line("   sans code   → #{$u->id} {$u->name} : {$u->loyalty_points} pts");
        }
        foreach ($statutRefuse->take($details) as $u) {
            $this->line("   statut {$u->status} → #{$u->id} {$u->name} ({$u->loyalty_code}) : {$u->loyalty_points} pts");
        }

        return 1;
    }

    /**
     * LE PROGRAMME TOURNE-T-IL ? Le chiffre qui a tout déclenché le 2026-08-19.
     *
     * Ce n'est pas une anomalie technique : c'est la mesure qui dit si le dispositif sert à
     * quelque chose. Un moteur parfait que personne n'utilise ne vaut rien, et c'est le genre de
     * constat qu'aucun test ne remonte jamais.
     */
    private function adoptionDuProgramme(): int
    {
        $ventes = DB::table('orders')->where('source_surface', 'pos')->count();
        $rattachees = DB::table('orders')->where('source_surface', 'pos')->whereNotNull('loyalty_customer_code')->count();
        $comptes = DB::table('users')->whereNotNull('loyalty_code')->count();
        $regles = app(LoyaltyRules::class);
        $auSeuil = DB::table('users')
            ->whereNotNull('loyalty_code')
            ->where('loyalty_points', '>=', $regles->effectiveFloor())
            ->count();

        $this->newLine();
        $this->info('── Adoption ──');
        $this->line(sprintf(
            '   Ventes de caisse : %d, dont %d rattachées à un client (%s%%).',
            $ventes,
            $rattachees,
            $ventes > 0 ? number_format($rattachees * 100 / $ventes, 1, ',', ' ') : '0'
        ));
        $this->line(sprintf(
            '   Comptes fidélité : %d, dont %d ont assez de points pour en dépenser (%s%%).',
            $comptes,
            $auSeuil,
            $comptes > 0 ? number_format($auSeuil * 100 / $comptes, 1, ',', ' ') : '0'
        ));

        if ($comptes > 0 && $auSeuil * 100 / max(1, $comptes) < 5) {
            $this->line('   ⓘ Moins de 5 % des clients atteignent le seuil : le programme récompense, mais');
            $this->line('     presque personne ne peut s\'en servir. C\'est un réglage de barème, pas un défaut.');
        }

        return 0;
    }
}
