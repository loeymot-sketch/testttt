<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — contrepartie du correctif T-5.3.3]
 *
 * POURQUOI CETTE COMMANDE EXISTE
 * -------------------------------
 * `DashboardService::slaAlerts()` remontait TOUTE commande restée en préparation depuis plus de
 * 15 minutes, sans borne basse. Mesuré le 2026-08-25 : 344 lignes, dont les 344 avaient plus de
 * 24 h et 313 plus de 30 jours. Le panneau d'alertes SLA alertait donc en permanence, sur rien —
 * et une vraie commande en retard y était invisible.
 *
 * La fenêtre a été bornée (`config/dashboard.php`). Mais borner, c'est aussi **cacher** : ces 344
 * commandes ont disparu de la seule surface qui les montrait, et aucune autre ne les compte.
 * Supprimer du bruit ne doit jamais supprimer de la visibilité — sinon on a troqué une fausse
 * alerte contre un angle mort, ce qui est pire.
 *
 * Cette commande est cette contrepartie : elle regarde exactement ce que le tableau de bord ne
 * regarde plus. Elle est **strictement en lecture** — elle ne corrige, ne clôture et ne supprime
 * rien. Une commande figée peut porter une trace fiscale (NF525, rétention 6 ans) : ce qu'on en
 * fait est une décision d'exploitation, jamais un automatisme.
 */
class ReportStuckOrdersCommand extends Command
{
    protected $signature = 'foodking:commandes-figees
                            {--jours=1 : Ancienneté minimale, en jours}
                            {--limite=20 : Nombre de lignes détaillées à afficher}
                            {--json : Sortie machine}';

    protected $description = 'Liste les commandes restées en préparation au-delà de la fenêtre des alertes SLA (lecture seule)';

    public function handle(): int
    {
        $jours = max(0, (int) $this->option('jours'));
        $limite = max(1, (int) $this->option('limite'));
        $seuil = Carbon::now()->subDays($jours);

        $base = DB::table('orders')
            ->where('status', OrderStatus::PREPARING)
            ->where('updated_at', '<', $seuil);

        $total = (clone $base)->count();

        $lignes = (clone $base)
            ->orderBy('updated_at')
            ->limit($limite)
            ->get(['id', 'order_serial_no', 'branch_id', 'updated_at']);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'seuil_jours' => $jours,
                'total'       => $total,
                'commandes'   => $lignes,
            ], JSON_UNESCAPED_UNICODE));

            return 0;
        }

        if ($total === 0) {
            $this->info("Aucune commande figée en préparation au-delà de {$jours} jour(s).");

            return 0;
        }

        $this->warn("{$total} commande(s) restée(s) en préparation depuis plus de {$jours} jour(s).");
        $this->line('');
        $this->table(
            ['#', 'N° commande', 'Succursale', 'Dernière mise à jour', 'Âge'],
            $lignes->map(fn ($o) => [
                $o->id,
                $o->order_serial_no,
                $o->branch_id,
                $o->updated_at,
                Carbon::parse($o->updated_at)->diffForHumans(),
            ])->all(),
        );

        if ($total > $limite) {
            $this->line('');
            $this->comment('… '.($total - $limite).' de plus. Utilisez --limite pour en voir davantage.');
        }

        $this->line('');
        $this->comment(
            'Lecture seule : rien n\'a été modifié. Ces commandes n\'apparaissent PLUS dans les '
            .'alertes SLA du tableau de bord (fenêtre bornée à '
            .config('dashboard.sla_alerts_window_hours', 24).' h) — c\'est voulu, elles noyaient '
            .'les vraies alertes. Ce qu\'il convient d\'en faire est une décision d\'exploitation : '
            .'une commande figée peut porter une trace fiscale à conserver 6 ans.'
        );

        return 0;
    }
}
