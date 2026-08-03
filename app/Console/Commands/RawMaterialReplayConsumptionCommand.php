<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\RawMaterialMovement;
use App\Models\Scopes\BranchScope;
use App\Services\RawMaterials\RawMaterialConsumptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P2b — B3] Rejeu de la consommation
 * matière sur l'HISTORIQUE des commandes réelles (backfill).
 *
 * Le listener {@see \App\Listeners\ConsumeRawMaterialsOnOrderCreated} ne consomme
 * QUE les commandes créées APRÈS son déploiement. Cette commande rejoue
 * {@see RawMaterialConsumptionService::consumeForOrder()} sur les commandes
 * passées pour reconstruire le stock théorique depuis les `composition_snapshot`
 * déjà scellés (immuables NF525).
 *
 * ─── Périmètre (statuts) ───────────────────────────────────────────────────
 *  On INCLUT toute commande NON terminale-négative, c.-à-d. tout SAUF
 *  CANCELED / REJECTED / RETURNED (miroir de {@see Order::scopeRealizedRevenue}
 *  et de l'ensemble terminal du modèle). Donc PENDING, ACCEPT, PREPARING,
 *  PREPARED, OUT_FOR_DELIVERY, DELIVERED sont rejoués — exactement comme le
 *  listener live consomme dès la création (OrderCreated), indépendamment du
 *  statut final. Les annulées/refusées/retournées (dont les miroirs de refund
 *  `status=RETURNED`) sont exclues : nourriture non vendue.
 *
 * ─── Idempotence ───────────────────────────────────────────────────────────
 *  Rejouer 2× = même stock : l'idempotence du StockService porte sur le triplet
 *  (source_type='order_item', source_id=order_item.id, raw_material_id). Un
 *  order_item déjà consommé (par le listener OU un run précédent) est un no-op.
 *  Sûr à relancer autant de fois que voulu.
 *
 * ─── DB PROD (amendement #5) ───────────────────────────────────────────────
 *  Conçu pour tourner sur la DB PROD VPS (la locale est polluée de tests e2e).
 *  `--dry-run` calcule tout SANS écrire : chaque commande est traitée dans une
 *  transaction annulée (rollback) — locks courts, une commande à la fois, aucune
 *  écriture ne persiste (garantie ACID). `--from` / `--to` bornent sur created_at.
 *
 * ─── NF525 / branch ────────────────────────────────────────────────────────
 *  Couche ADDITIVE : lit les ventes (snapshots), n'écrit RIEN dans la chaîne
 *  fiscale. Le service hard-scope les écritures stock sur branch_id=1 (V1). La
 *  requête retire le BranchScope global (contexte console/maintenance, hors auth)
 *  pour un périmètre déterministe — miroir du service sur `orderItems()`.
 */
class RawMaterialReplayConsumptionCommand extends Command
{
    protected $signature = 'raw-materials:replay-consumption
        {--from= : Date de début INCLUSE (Y-m-d) — filtre sur created_at}
        {--to= : Date de fin INCLUSE (Y-m-d) — filtre sur created_at}
        {--dry-run : Simuler sans écrire (chaque commande en transaction annulée)}';

    protected $description = 'Rejoue la consommation matière sur l\'historique des commandes réelles (hors annulées/refusées/retournées). Idempotent, dry-run sûr sur DB PROD.';

    /** Statuts exclus : ventes non réalisées (miroir Order::scopeRealizedRevenue). */
    private const EXCLUDED_STATUSES = [
        OrderStatus::CANCELED,
        OrderStatus::REJECTED,
        OrderStatus::RETURNED,
    ];

    /** Taille de lot pour le parcours mémoire-borné (DB PROD). */
    private const CHUNK = 200;

    public function handle(RawMaterialConsumptionService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        [$from, $to] = $this->window();

        if ($from === false || $to === false) {
            $this->error('Date invalide : --from/--to attendent le format Y-m-d.');

            return self::FAILURE;
        }

        $query = Order::query()
            ->withoutGlobalScope(BranchScope::class)
            ->whereNotIn('status', self::EXCLUDED_STATUSES)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->orderBy('id');

        $total = (clone $query)->count();

        $this->info(($dry ? '[dry-run] ' : '')."Rejeu conso matière — {$total} commande(s) éligible(s)"
            .$this->windowLabel($from, $to).'.');

        if ($total === 0) {
            $this->warn('Aucune commande à rejouer sur ce périmètre.');

            return self::SUCCESS;
        }

        $movementsBefore = $dry ? 0 : RawMaterialMovement::count();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $orders = 0;
        $consumedLines = 0;
        $skipped = 0;
        $materials = [];

        $query->chunkById(self::CHUNK, function ($chunk) use ($service, $dry, $bar, &$orders, &$consumedLines, &$skipped, &$materials): void {
            foreach ($chunk as $order) {
                $summary = $this->consume($service, $order, $dry);

                $orders++;
                $consumedLines += count($summary['consumed']);
                $skipped += count($summary['skipped']);
                foreach ($summary['consumed'] as $c) {
                    $materials[(int) $c['raw_material_id']] = true;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $movementsWritten = $dry ? 0 : (RawMaterialMovement::count() - $movementsBefore);

        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['Commandes traitées', (string) $orders],
                ['Lignes consommées (recettes résolues)', (string) $consumedLines],
                ['Matières touchées (distinctes)', (string) count($materials)],
                ['Ignorées (sans recette / supplément générique)', (string) $skipped],
                ['Mouvements de stock écrits', $dry ? '0 (dry-run — aucune écriture)' : (string) $movementsWritten],
            ]
        );

        if ($dry) {
            $this->comment('Dry-run : rien n\'a été écrit. Relancer sans --dry-run pour appliquer.');
        } else {
            $this->info('Rejeu appliqué. Relancer cette commande est idempotent (no-op).');
        }

        return self::SUCCESS;
    }

    /**
     * Traite une commande. En dry-run : transaction annulée → calcul réel via le
     * MÊME chemin de code, mais aucune écriture ne persiste (rollback garanti).
     *
     * @return array{consumed: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>}
     */
    private function consume(RawMaterialConsumptionService $service, Order $order, bool $dry): array
    {
        if (! $dry) {
            return $service->consumeForOrder($order);
        }

        DB::beginTransaction();

        try {
            return $service->consumeForOrder($order);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Résout la fenêtre de dates. Retourne [Carbon|null, Carbon|null] ; false sur
     * une valeur invalide (pour échouer proprement plutôt que rejouer tout).
     *
     * @return array{0: Carbon|null|false, 1: Carbon|null|false}
     */
    private function window(): array
    {
        return [
            $this->parseBound($this->option('from'), false),
            $this->parseBound($this->option('to'), true),
        ];
    }

    /** @return Carbon|null|false */
    private function parseBound(?string $value, bool $endOfDay)
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse(trim($value));
        } catch (\Throwable) {
            return false;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }

    private function windowLabel(?Carbon $from, ?Carbon $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }

        $a = $from?->toDateString() ?? '…';
        $b = $to?->toDateString() ?? '…';

        return " (fenêtre {$a} → {$b})";
    }
}
