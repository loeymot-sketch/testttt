<?php

namespace App\Services\Pos;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Validation\ValidationException;

/**
 * [GOAL CAISSE/CUISINE #5 2026-10-02] Bouton « Offert » sur un supplément / une sauce.
 *
 * LE PRIX N'EST JAMAIS ENVOYÉ PAR LE CLIENT. Une ligne porte, à côté de `item_extras` (la part
 * PAYANTE, facturée par PricingService), un sidecar `item_extras_offered: [{id, quantity}]` : des
 * identifiants d'extras à offrir, rien d'autre. PricingService ne lit que `item_extras` → la part
 * offerte vaut 0 par construction, SANS que sa logique (zone gelée) change : il reste la source de
 * vérité du prix. Ce service ne fait que :
 *   1. VALIDER le sidecar (forme, appartenance de l'extra au produit, extra payant, surface POS) ;
 *   2. TRACER l'offert dans l'audit chaîné (qui / quoi / quand / valeur offerte).
 * Le snapshot immuable (CompositionSnapshotBuilder) scelle la ligne « OFFERT » à 0 €.
 */
class OfferedExtras
{
    public const AUDIT_ACTION = 'order.line_offered';

    private const MAX_QTY_PER_EXTRA = 99;

    /** @return list<array{id:int,quantity:int}> sidecar normalisé d'une ligne de requête. */
    public static function entries(object|array $line): array
    {
        $raw = is_array($line) ? ($line['item_extras_offered'] ?? null) : ($line->item_extras_offered ?? null);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            $entry = (array) $entry;
            $out[] = ['id' => (int) ($entry['id'] ?? 0), 'quantity' => (int) ($entry['quantity'] ?? 0)];
        }

        return $out;
    }

    /**
     * @param  array<int,mixed>  $items  lignes décodées de la requête
     *
     * @throws ValidationException
     */
    public function assertValid(array $items, string $surface): void
    {
        foreach ($items as $index => $line) {
            $rawSidecar = is_array($line) ? ($line['item_extras_offered'] ?? null) : ($line->item_extras_offered ?? null);
            if ($rawSidecar === null || $rawSidecar === []) {
                continue;
            }

            $fail = fn (string $message) => throw ValidationException::withMessages([
                'items' => ["Ligne {$index} : {$message}"],
            ]);

            if ($surface !== 'pos' || ! (bool) config('pos.offer_extras_enabled', true)) {
                $fail("l'offert n'est disponible qu'en caisse.");
            }
            if (! is_array($rawSidecar)) {
                $fail("liste d'options offertes invalide.");
            }

            $itemId = (int) (is_array($line) ? ($line['item_id'] ?? 0) : ($line->item_id ?? 0));
            if ($itemId <= 0) {
                $fail("l'offert ne s'applique qu'à une ligne catalogue.");
            }

            $seen = [];
            foreach (self::entries($line) as $entry) {
                if ($entry['id'] <= 0 || $entry['quantity'] < 1 || $entry['quantity'] > self::MAX_QTY_PER_EXTRA) {
                    $fail('option offerte invalide (id ou quantité).');
                }
                if (isset($seen[$entry['id']])) {
                    $fail('option offerte en double.');
                }
                $seen[$entry['id']] = true;

                $extra = ItemExtra::query()->whereKey($entry['id'])->first();
                // Appartenance : l'extra doit appartenir AU produit de la ligne, actif, et PAYANT
                // (offrir un extra déjà gratuit n'a aucun sens et masquerait un signal d'abus).
                if (! $extra || (int) $extra->item_id !== $itemId || (int) $extra->status !== Status::ACTIVE) {
                    $fail("l'option offerte n'appartient pas à ce produit.");
                }
                if ((float) $extra->price <= 0) {
                    $fail("« {$extra->name} » est déjà gratuit : rien à offrir.");
                }
            }
        }
    }

    /**
     * Trace chaque option offerte d'une commande dans l'audit chaîné (HMAC, append-only).
     * Appelée DANS la transaction de création : trace atomique avec la commande.
     *
     * @param  array<int,mixed>  $items
     */
    public function audit(array $items, int $orderId, int $branchId, ?int $actorId): void
    {
        foreach ($items as $line) {
            $sidecar = self::entries($line);
            if ($sidecar === []) {
                continue;
            }
            $itemId = (int) (is_array($line) ? ($line['item_id'] ?? 0) : ($line->item_id ?? 0));
            $itemName = (string) (Item::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->whereKey($itemId)->value('name') ?? '');

            foreach ($sidecar as $entry) {
                $extra = ItemExtra::query()->whereKey($entry['id'])->first();
                if (! $extra) {
                    continue;
                }
                app(AuditLogService::class)->write([
                    'branch_id' => $branchId,
                    'user_id' => $actorId,
                    'action' => self::AUDIT_ACTION,
                    'resource' => 'order',
                    'resource_id' => $orderId,
                    'payload' => [
                        'item_id' => $itemId,
                        'item_name' => $itemName,
                        'extra_id' => (int) $extra->id,
                        'extra_name' => (string) $extra->name,
                        'quantity' => $entry['quantity'],
                        'catalog_unit_price' => round((float) $extra->price, 2),
                        'offered_value' => round((float) $extra->price * $entry['quantity'], 2),
                        'offered_at' => now()->toIso8601String(),
                    ],
                ]);
            }
        }
    }
}
