<?php

namespace App\Services\Uber;

use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * [UBER-EATS 2026-07-01] Mappe une commande Uber Eats → structure interne réutilisable
 * (item_id catalogue + composition_snapshot lines/extras/addons). Réutilise TOUT le reste
 * (ticket ESC/POS, KDS symbolique, caisse) car ils lisent le composition_snapshot.
 *
 * NB : les PRIX Uber sont scellés (déjà encaissés côté Uber) — on N'appelle PAS PricingService
 * pour recalculer ; on garde les montants Uber tels quels (canal séparé).
 */
class UberOrderMapper
{
    /**
     * @param  array  $uberOrder  Payload complet de GET /v1/eats/orders/{id}
     * @return array{queue_number:?string, display_id:?string, items:array<int,array>, total:float, raw_customer:?string}
     */
    public function map(array $uberOrder): array
    {
        $cart = $uberOrder['cart'] ?? $uberOrder['eater_cart'] ?? [];
        $lines = $cart['items'] ?? $uberOrder['items'] ?? [];

        $items = [];
        foreach ($lines as $line) {
            $items[] = $this->mapLine($line);
        }

        return [
            'display_id'   => (string) ($uberOrder['display_id'] ?? $uberOrder['id'] ?? ''),
            'queue_number' => $this->shortDisplay($uberOrder),
            'items'        => $items,
            'total'        => (float) (($uberOrder['payment']['charges']['total']['amount'] ?? 0) / 100) ?: (float) ($uberOrder['total'] ?? 0),
            'raw_customer' => (string) ($uberOrder['eater']['first_name'] ?? $uberOrder['customer']['name'] ?? ''),
        ];
    }

    private function mapLine(array $line): array
    {
        $title = (string) ($line['title'] ?? $line['name'] ?? '');
        $uberId = (string) ($line['id'] ?? $line['external_id'] ?? '');
        $qty = (int) ($line['quantity'] ?? 1);

        $itemId = $this->resolveItemId($title, $uberId);

        // Modificateurs Uber (sauces / suppléments / options) → extras du snapshot.
        $extras = [];
        foreach (($line['selected_modifier_groups'] ?? $line['modifier_groups'] ?? []) as $group) {
            foreach (($group['selected_items'] ?? $group['items'] ?? []) as $mod) {
                $extras[] = [
                    'extra_name' => (string) ($mod['title'] ?? $mod['name'] ?? ''),
                    'quantity'   => (int) ($mod['quantity'] ?? 1),
                    'line_total' => (float) (($mod['price']['amount'] ?? 0) / 100),
                ];
            }
        }

        return [
            'item_id'    => $itemId,
            'name'       => $title,
            'quantity'   => max(1, $qty),
            'unit_price' => (float) (($line['price']['unit_price']['amount'] ?? 0) / 100),
            'total'      => (float) (($line['price']['total_price']['amount'] ?? 0) / 100),
            'instruction'=> (string) ($line['special_instructions'] ?? ''),
            'composition_snapshot' => [
                'schema_version' => 1,
                'source'         => 'uber_eats',
                'lines'          => [], // Uber ne détaille pas viande/sauce en attributs → gardé en extras
                'extras'         => $extras,
                'addons'         => [],
                'uber_title'     => $title,
            ],
        ];
    }

    /** Résout l'item_id catalogue : map par titre, par id Uber, puis fallback match nom DB. */
    public function resolveItemId(string $title, string $uberId = ''): ?int
    {
        $mapTitle = (array) config('uber_menu_map.by_title', []);
        $mapUberId = (array) config('uber_menu_map.by_uber_id', []);

        if ($uberId !== '' && isset($mapUberId[$uberId])) {
            return (int) $mapUberId[$uberId];
        }
        $n = $this->norm($title);
        if (isset($mapTitle[$n])) {
            return (int) $mapTitle[$n];
        }
        // Fallback : match par nom sur le catalogue actif (best-effort).
        $item = Item::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($title))])
            ->first(['id']);
        if ($item) {
            return (int) $item->id;
        }
        $item = Item::query()->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower(trim($title)) . '%'])->first(['id']);

        return $item ? (int) $item->id : null;
    }

    private function shortDisplay(array $uberOrder): ?string
    {
        $d = (string) ($uberOrder['display_id'] ?? '');
        return $d !== '' ? ('U' . mb_substr($d, -4)) : null;
    }

    private function norm(string $s): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return trim(mb_strtolower($ascii !== false ? $ascii : $s));
    }
}
