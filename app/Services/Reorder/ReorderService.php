<?php

namespace App\Services\Reorder;

use App\Enums\Status;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderItem;
use App\Services\Kiosk\PricingPreviewService;
use App\Services\Menu\AvailabilityService;

/**
 * [PROFIL APP 2026-11-12] « Recommander en un toucher » depuis Mes commandes (application et site).
 *
 * Rend, pour chaque ligne d'une ANCIENNE commande du client, la composition EXACTE qu'il avait envoyée (mêmes
 * identifiants de variations, suppléments et formule — relus dans la ligne scellée et son `composition_snapshot`),
 * avec le prix d'AUJOURD'HUI calculé par le serveur (PricingService via PricingPreviewService, lecture seule, aucune
 * écriture) et, si la ligne n'est plus commandable, la raison en français. Le client n'envoie AUCUN prix : à la
 * commande, le serveur recalcule tout comme pour n'importe quel panier (et refuse un total qui ne correspond pas).
 *
 * Aucune zone gelée n'est modifiée : PricingService est seulement APPELÉ (même chemin que l'aperçu de la borne).
 */
final class ReorderService
{
    public function __construct(
        private readonly PricingPreviewService $preview = new PricingPreviewService(),
    ) {
    }

    /**
     * @return array{order_id:int, lines: array<int, array<string, mixed>>, available_count:int, unavailable_count:int}
     */
    public function linesFor(FrontendOrder $order, int $customerId): array
    {
        $order->loadMissing(['orderItems']);
        $branchId = (int) ($order->branch_id ?: 1);
        $availability = app(AvailabilityService::class);

        $lines = [];
        foreach ($order->orderItems as $orderItem) {
            $lines[] = $this->line($orderItem, $branchId, $customerId, $availability);
        }

        $ok = count(array_filter($lines, fn ($l) => $l['available']));

        return [
            'order_id'          => (int) $order->id,
            'lines'             => $lines,
            'available_count'   => $ok,
            'unavailable_count' => count($lines) - $ok,
        ];
    }

    /** @return array<string, mixed> */
    private function line(OrderItem $orderItem, int $branchId, int $customerId, AvailabilityService $availability): array
    {
        $item = Item::withTrashed()->find((int) $orderItem->item_id);
        $nom = (string) ($item?->name ?? 'Article');
        $quantite = max(1, (int) $orderItem->quantity);
        $snapshot = $this->decode($orderItem->composition_snapshot);

        $payload = [
            'item_id'         => (int) $orderItem->item_id,
            'quantity'        => 1,
            'item_variations' => $this->idsQuantites($this->decode($orderItem->item_variations)),
            'item_extras'     => $this->extras($this->decode($orderItem->item_extras)),
            'item_addons'     => $this->addons($snapshot['addons'] ?? []),
        ];
        $instruction = $this->instructionSansNoteDeCommande((string) ($orderItem->instruction ?? ''));
        if ($instruction !== '') {
            $payload['instruction'] = $instruction;
        }
        $payload = array_filter($payload, fn ($v) => $v !== []);

        $base = [
            'order_item_id' => (int) $orderItem->id,
            'item_id'       => (int) $orderItem->item_id,
            'name'          => $nom,
            'quantity'      => $quantite,
            'summary'       => $this->resume($snapshot, $instruction),
        ];

        $refus = $this->refus($orderItem, $item, $branchId, $availability);
        if ($refus !== null) {
            return $base + ['available' => false, 'reason' => $refus[0], 'message' => $refus[1], 'unit_price' => null, 'payload' => null];
        }

        try {
            $prix = $this->preview->preview($branchId, [$payload], $customerId, null, null);
            $unitaire = round((float) ($prix['lines'][0]['line_total'] ?? $prix['total'] ?? 0), 2);
        } catch (\InvalidArgumentException $e) {
            $message = str_contains($e->getMessage(), 'plus disponible')
                ? 'Une partie de « ' . $nom . ' » n\'est plus disponible pour le moment.'
                : 'La composition de « ' . $nom . ' » a changé depuis cette commande : recomposez-le depuis la carte.';

            return $base + ['available' => false, 'reason' => 'composition', 'message' => $message, 'unit_price' => null, 'payload' => null];
        }

        return $base + ['available' => true, 'reason' => null, 'message' => null, 'unit_price' => $unitaire, 'payload' => $payload];
    }

    /** @return array{0:string,1:string}|null */
    private function refus(OrderItem $orderItem, ?Item $item, int $branchId, AvailabilityService $availability): ?array
    {
        $nom = (string) ($item?->name ?? 'Cet article');
        $retire = ['retire', '« ' . $nom . ' » n\'est plus à la carte.'];

        if ((string) ($orderItem->line_type ?? 'catalog') !== 'catalog') {
            return ['caisse', 'Cette ligne avait été ajoutée en caisse : elle ne se recommande pas en ligne.'];
        }
        if (! $item || $item->trashed() || (int) $item->status !== Status::ACTIVE) {
            return $retire;
        }
        // Même règle que la commande en ligne : un produit réservé à la caisse ne se commande ni par le site ni par l'app.
        if (! $item->isVisibleOn('web') && ! $item->isVisibleOn('kiosk')) {
            return $retire;
        }
        $categorie = $item->item_category_id ? ItemCategory::query()->whereKey($item->item_category_id)->value('status') : null;
        if ($categorie !== null && (int) $categorie !== Status::ACTIVE) {
            return $retire;
        }
        if (($item->is_available !== null && ! (bool) $item->is_available) || ! $availability->isAvailable((int) $item->id, $branchId)) {
            return ['epuise', '« ' . $nom . ' » est épuisé pour le moment.'];
        }

        return null;
    }

    /** @return array<int, mixed> */
    private function decode(mixed $brut): array
    {
        if (is_array($brut)) {
            return $brut;
        }
        if (! is_string($brut) || $brut === '') {
            return [];
        }
        $d = json_decode($brut, true);

        return is_array($d) ? $d : [];
    }

    /** @return array<int, array{id:int, quantity:int}> */
    private function idsQuantites(array $lignes): array
    {
        $out = [];
        foreach ($lignes as $l) {
            $id = (int) ($l['id'] ?? $l['variation_id'] ?? 0);
            if ($id > 0) {
                $out[] = ['id' => $id, 'quantity' => max(1, (int) ($l['quantity'] ?? 1))];
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function extras(array $lignes): array
    {
        $out = [];
        foreach ($lignes as $l) {
            $id = (int) ($l['id'] ?? $l['extra_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $e = ['id' => $id, 'quantity' => max(1, (int) ($l['quantity'] ?? 1))];
            // Le libellé (« Sauce supplémentaire (Mayonnaise) ») est lu par la cuisine : il est rendu tel quel.
            if (isset($l['name']) && is_string($l['name']) && $l['name'] !== '') {
                $e['name'] = mb_substr($l['name'], 0, 190);
            }
            $out[] = $e;
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function addons(mixed $lignes): array
    {
        $out = [];
        foreach (is_array($lignes) ? $lignes : [] as $l) {
            $id = (int) ($l['addon_id'] ?? $l['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $a = ['id' => $id, 'quantity' => max(1, (int) ($l['quantity'] ?? 1))];
            if (! empty($l['role']) && is_string($l['role'])) {
                $a['role'] = $l['role'];
            }
            $out[] = $a;
        }

        return $out;
    }

    /** La note de COMMANDE de l'ancienne commande (« [Note commande : …] ») ne suit pas : elle concernait ce jour-là. */
    private function instructionSansNoteDeCommande(string $instruction): string
    {
        $garde = array_filter(
            preg_split('/\R/u', $instruction) ?: [],
            fn ($l) => trim($l) !== '' && ! preg_match('/^\s*\[Note commande\s*:/u', $l)
        );

        return trim(implode("\n", $garde));
    }

    private function resume(array $snapshot, string $instruction): string
    {
        $parts = [];
        foreach ($snapshot['lines'] ?? [] as $l) {
            if (! empty($l['variation_name'])) {
                $parts[] = (string) $l['variation_name'];
            }
        }
        foreach ($snapshot['addons'] ?? [] as $a) {
            if (! empty($a['addon_name'])) {
                $parts[] = (string) $a['addon_name'];
            }
        }
        foreach ($snapshot['extras'] ?? [] as $e) {
            if (! empty($e['extra_name'])) {
                $q = max(1, (int) ($e['quantity'] ?? 1));
                $parts[] = ($q > 1 ? $q . '× ' : '') . $e['extra_name'];
            }
        }
        foreach (preg_split('/\R/u', $instruction) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '' && preg_match('/^(Boisson menu|Sauce frites|Frites)\s*:/iu', $l)) {
                $parts[] = $l;
            }
        }

        return mb_substr(implode(' · ', array_values(array_unique($parts))), 0, 300);
    }
}
