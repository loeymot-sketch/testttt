<?php

namespace App\Services\Pos;

use App\Models\AuditLog;
use App\Models\Order;

/**
 * [GOAL REMARQUES 2026-10-03 · R-059] « Commandes ratées » : les commandes TÉLÉPHONE annulées depuis
 * moins de 24 h parce que le client n'est pas venu — par la croix (`order.counter_payment_canceled`) ou
 * par « Supprimer les commandes téléphone » (StaleCounterOrderPurger::AUDIT_ACTION).
 *
 * LECTURE SEULE. La date de l'annulation vient de la trace d'audit chaînée (la table `orders` n'a pas de
 * colonne d'annulation). Après 24 h la commande sort de la liste ; elle n'est jamais effacée (NF525).
 * « Pas enregistré fiscalement » : une commande jamais encaissée n'a ni numéro fiscal ni place dans le Z ;
 * la ligne d'audit de l'annulation, elle, est obligatoire et reste.
 */
class CommandesRatees
{
    public const FENETRE_HEURES = 24;

    public const ACTIONS = ['order.counter_payment_canceled', StaleCounterOrderPurger::AUDIT_ACTION];

    /**
     * @return list<array{id:int, numero:string, client:?string, telephone:?string, total:float, annulee_a:string, motif:?string, produits:list<string>}>
     */
    public function liste(int $branchId): array
    {
        $audits = AuditLog::query()
            ->whereIn('action', self::ACTIONS)
            ->where('resource', 'order')
            ->where('created_at', '>=', now()->subHours(self::FENETRE_HEURES))
            // Revue vague 2 · P3 : audit_logs n'est indexé que par (branch_id, created_at) ; pour
            // l'administrateur (branche 0) on passe la liste des branches pour profiter de l'index
            // au lieu de balayer six ans de journal à chaque rafraîchissement.
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->when($branchId <= 0, fn ($q) => $q->whereIn('branch_id', \App\Models\Branch::query()->pluck('id')))
            ->orderByDesc('id')
            ->get(['resource_id', 'created_at', 'payload']);

        // La plus récente annulation de chaque commande (une commande n'est annulée qu'une fois ; garde quand même).
        $parCommande = [];
        foreach ($audits as $a) {
            $parCommande[(int) $a->resource_id] ??= $a;
        }
        if ($parCommande === []) {
            return [];
        }

        $commandes = Order::withTrashed()
            ->whereIn('id', array_keys($parCommande))
            ->where('source_surface', 'phone')
            ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
            ->with('orderItems.orderItem')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($parCommande as $id => $audit) {
            $o = $commandes[$id] ?? null;
            if (! $o) {
                continue;
            }
            $out[] = [
                'id' => (int) $o->id,
                'numero' => (string) ($o->queue_number ?: $o->order_serial_no ?: $o->id),
                'client' => $o->pos_customer_name ?: null,
                'telephone' => $o->pos_customer_phone ?: null,
                'total' => (float) $o->total,
                'annulee_a' => $audit->created_at->toIso8601String(),
                'motif' => is_array($audit->payload) ? ($audit->payload['reason'] ?? null) : null,
                'produits' => $o->orderItems->map(fn ($oi) => trim(((int) $oi->quantity > 1 ? $oi->quantity.' × ' : '')
                    .($oi->manual_label ?: ($oi->orderItem?->name ?? 'Article'))))->values()->all(),
            ];
        }

        return $out;
    }
}
