<?php

namespace App\Services\Hardware;

use App\Contracts\BroadcastableOrder;
use App\Services\Receipt\ReceiptDataService;

/**
 * [PRINT-SAGA 2026-06-24] Renders an Order into a raw ESC/POS byte stream for a
 * thermal receipt printer (e.g. the counter SAGA, USB on the caisse PC).
 *
 * Reads the SAME source of truth as the on-screen receipt:
 *   - line items + composition from order_items.composition_snapshot (NF525 SSOT),
 *   - NF525 header (fiscal seq / SIRET / TVA intra / legal footer / operator) via
 *     {@see ReceiptDataService} so the printed ticket cannot drift from the API/HTML one.
 *
 * ENCODING CONTRACT: the EscPosCommandBuilder text methods (lineKV/textLine/...)
 * do UTF-8-safe layout (mb_strlen / mb_strimwidth / preg `/u`). So we assemble the
 * WHOLE ticket in UTF-8 and transcode to the printer code page (CP858) ONCE at the
 * end — never per-string, which would feed invalid-UTF-8 bytes back into the
 * `/u` sanitizer and silently DROP accented labels (« Viande supplémentaire »).
 *
 * Money is formatted FR ("7,90 EUR").
 */
final class OrderReceiptEscPosRenderer
{
    public function __construct(
        private readonly ReceiptDataService $receiptData = new ReceiptDataService,
        private readonly KitchenTicketSymbolicFormatter $symbolic = new KitchenTicketSymbolicFormatter,
    ) {}

    /** Client (fiscal) ticket — prices, totals, TVA, payment, NF525 footer. */
    public function renderClientTicket(BroadcastableOrder $order, array $opts = []): string
    {
        $w = (int) ($opts['width_chars'] ?? 48);
        $codePage = (int) ($opts['code_page'] ?? 19); // 19 = CP858 (FR accents + €)
        $isDuplicata = (bool) ($opts['is_duplicata'] ?? false);
        $counterCopy = (bool) ($opts['counter_copy'] ?? false);
        $head = $this->receiptData->buildForOrderModel($order);
        $branch = $order->branch;

        $b = EscPosCommandBuilder::init();
        $b .= EscPosCommandBuilder::selectCodePage($codePage);

        // Header
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine(optional($branch)->name ?: 'LE CAYENNE');
        $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        if (optional($branch)->address) {
            $b .= EscPosCommandBuilder::textLine((string) $branch->address);
        }
        if (optional($branch)->phone) {
            $b .= EscPosCommandBuilder::textLine('Tel: ' . $branch->phone);
        }
        if (! empty($head['pos_siret'])) {
            $b .= EscPosCommandBuilder::textLine('SIRET ' . $head['pos_siret']);
        }
        $b .= EscPosCommandBuilder::separator('=', $w);

        // Customer pickup number — big & centered (the number called out).
        $queue = (string) ($order->queue_number ?? $order->order_serial_no ?? $order->id);
        if ($queue !== '') {
            $b .= EscPosCommandBuilder::alignCenter();
            $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine('N ' . $queue);
            $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        }
        $orderType = $this->orderTypeLabel($order);
        if ($orderType !== '') {
            $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine($orderType) . EscPosCommandBuilder::bold(false);
        }
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::separator('-', $w);

        if ($counterCopy) {
            $b .= EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine('*** COMMANDE BORNE - COPIE CAISSE ***');
            $b .= EscPosCommandBuilder::bold(false);
        }
        if ($isDuplicata) {
            $b .= EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine('*** DUPLICATA ***');
            $b .= EscPosCommandBuilder::bold(false);
        }

        // Order meta
        $b .= EscPosCommandBuilder::alignLeft();
        $serial = (string) ($order->order_serial_no ?? $order->id);
        $b .= EscPosCommandBuilder::lineKV('Commande', 'N ' . $serial, $w);
        $dt = $order->order_datetime ?? $order->created_at;
        if ($dt) {
            $b .= EscPosCommandBuilder::lineKV('Date', $dt->format('d/m/Y H:i'), $w);
        }
        if (! empty($head['operator_name'])) {
            $b .= EscPosCommandBuilder::lineKV('Caissier', (string) $head['operator_name'], $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // Line items (with prices)
        foreach ($this->lines($order) as $line) {
            $b .= $this->renderLine($line, $w, true);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // Totals
        $b .= EscPosCommandBuilder::lineKV('Sous-total', $this->money((float) ($order->subtotal ?? 0)), $w);
        $discount = (float) ($order->discount ?? 0);
        if ($discount > 0) {
            $b .= EscPosCommandBuilder::lineKV('Remise', '-' . $this->money($discount), $w);
        }
        $delivery = (float) ($order->delivery_charge ?? 0);
        if ($delivery > 0) {
            $b .= EscPosCommandBuilder::lineKV('Livraison', $this->money($delivery), $w);
        }

        $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::doubleSize(true);
        $b .= EscPosCommandBuilder::lineKV('TOTAL', $this->money((float) ($order->total ?? 0)), max(20, (int) floor($w / 2)));
        $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);

        // Payment (right after the total, as on a standard receipt).
        foreach ($this->payments($order) as $p) {
            $b .= EscPosCommandBuilder::lineKV($p['label'], $this->money($p['amount']), $w);
            if (($p['change'] ?? 0) > 0) {
                $b .= EscPosCommandBuilder::lineKV('  Rendu', $this->money($p['change']), $w);
            }
        }

        // NF525: ventilation de la TVA par taux (taux / base HT / montant).
        $taxLines = $this->taxLines($order);
        if (! empty($taxLines)) {
            $b .= EscPosCommandBuilder::separator('-', $w);
            $b .= EscPosCommandBuilder::textLine('Detail TVA :');
            foreach ($taxLines as $tl) {
                // "10.00" → "10", "5.50" → "5,5" (strip only trailing decimals).
                $rate = rtrim(rtrim(number_format((float) $tl['rate'], 2, ',', ''), '0'), ',');
                $label = 'TVA ' . $rate . '% (HT ' . number_format($tl['ht'], 2, ',', ' ') . ')';
                $b .= EscPosCommandBuilder::lineKV($label, $this->money($tl['tax']), $w);
            }
        } elseif ((float) ($order->total_tax ?? 0) > 0) {
            $b .= EscPosCommandBuilder::lineKV('Dont TVA', $this->money((float) $order->total_tax), $w);
        }

        // NF525 footer
        $b .= EscPosCommandBuilder::separator('=', $w);
        $b .= EscPosCommandBuilder::alignCenter();
        if (! empty($head['fiscal_sequence_no'])) {
            $b .= EscPosCommandBuilder::textLine('Ticket fiscal N ' . $head['fiscal_sequence_no']);
        }
        if (! empty($head['pos_vat_intra'])) {
            $b .= EscPosCommandBuilder::textLine('TVA ' . $head['pos_vat_intra']);
        }
        if (! empty($head['pos_legal_footer'])) {
            $b .= EscPosCommandBuilder::textLine((string) $head['pos_legal_footer']);
        }
        $b .= EscPosCommandBuilder::textLine('Prix nets en euros TTC');
        $b .= EscPosCommandBuilder::feed(1);
        $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('Merci et a tres bientot !') . EscPosCommandBuilder::bold(false);
        $b .= EscPosCommandBuilder::feed(3);
        $b .= EscPosCommandBuilder::cut();

        // Transcode the WHOLE assembled stream once (control bytes are <0x80 and
        // pass through iconv unchanged; only the UTF-8 text becomes CP858).
        return EscPosCommandBuilder::encodeForPrinter($b, $this->encodingFor($codePage));
    }

    /** Kitchen (production) ticket — composition + instruction, NO prices. */
    public function renderKitchenTicket(BroadcastableOrder $order, array $opts = []): string
    {
        $w = (int) ($opts['width_chars'] ?? 48);
        $codePage = (int) ($opts['code_page'] ?? 19);

        $b = EscPosCommandBuilder::init();
        $b .= EscPosCommandBuilder::selectCodePage($codePage);
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine('CUISINE');
        $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        $serial = (string) ($order->order_serial_no ?? $order->id);
        $b .= EscPosCommandBuilder::textLine('Commande N ' . $serial);
        $dt = $order->order_datetime ?? $order->created_at;
        if ($dt) {
            $b .= EscPosCommandBuilder::textLine($dt->format('H:i'));
        }
        $b .= EscPosCommandBuilder::separator('=', $w);
        $b .= EscPosCommandBuilder::alignLeft();

        // [KITCHEN-SYMBOLS 2026-06-28] Owner: the cook reads symbolic shorthand
        // (G | SANDWICH | P | STO | SAM), not prose. Same table as the KDS screen.
        foreach (($order->orderItems ?? collect()) as $oi) {
            $name = (string) ($oi->name ?? optional($oi->orderItem)->name ?? 'Article');
            $snap = is_array($oi->composition_snapshot) ? $oi->composition_snapshot : [];
            $qty = max(1, (int) ($oi->quantity ?? 1));

            $main = $this->symbolic->mainLine($name, $snap);
            $b .= EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine($qty . ' x ' . $main);
            $b .= EscPosCommandBuilder::bold(false);

            foreach ($this->symbolic->supplementLines($snap) as $sup) {
                $b .= EscPosCommandBuilder::textLine('  ' . $sup);
            }
            $menu = $this->symbolic->menuLine($snap);
            if ($menu !== '') {
                $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('  ' . $menu) . EscPosCommandBuilder::bold(false);
            }
            $note = $this->symbolic->cleanInstruction((string) ($oi->instruction ?? ''), $name);
            foreach (array_filter(explode("\n", $note)) as $noteLine) {
                $b .= EscPosCommandBuilder::textLine('  ** ' . trim($noteLine));
            }
            $b .= EscPosCommandBuilder::textLine('');
        }
        $b .= EscPosCommandBuilder::feed(3);
        $b .= EscPosCommandBuilder::cut();

        return EscPosCommandBuilder::encodeForPrinter($b, $this->encodingFor($codePage));
    }

    /**
     * @return array<int, array{name:string, qty:int, total:float, comps:array<int,string>, extras:array<int,array{name:string,amount:float}>, addons:array<int,array{name:string,amount:float}>, instruction:string}>
     */
    private function lines(BroadcastableOrder $order): array
    {
        $items = $order->orderItems ?? collect();
        $out = [];
        foreach ($items as $oi) {
            $name = (string) ($oi->name ?? optional($oi->orderItem)->name ?? 'Article');
            $snap = is_array($oi->composition_snapshot) ? $oi->composition_snapshot : [];
            $comps = [];
            foreach (($snap['lines'] ?? []) as $l) {
                $group = trim((string) ($l['attribute_name'] ?? ''));
                $value = trim((string) ($l['variation_name'] ?? $l['name'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $comps[] = $group !== '' ? "$group: $value" : $value;
            }
            $extras = [];
            foreach (($snap['extras'] ?? []) as $e) {
                $en = trim((string) ($e['extra_name'] ?? $e['name'] ?? ''));
                if ($en === '') {
                    continue;
                }
                $extras[] = ['name' => $en, 'amount' => (float) ($e['line_total'] ?? 0)];
            }
            $addons = [];
            foreach (($snap['addons'] ?? []) as $a) {
                $an = trim((string) ($a['addon_name'] ?? $a['name'] ?? ''));
                if ($an === '') {
                    continue;
                }
                $addons[] = ['name' => $an, 'amount' => (float) ($a['line_total'] ?? 0)];
            }
            $out[] = [
                'name' => $name,
                'qty' => max(1, (int) ($oi->quantity ?? 1)),
                'total' => (float) ($oi->total_price ?? 0),
                'comps' => $comps,
                'extras' => $extras,
                'addons' => $addons,
                'instruction' => trim((string) ($oi->instruction ?? '')),
            ];
        }

        return $out;
    }

    private function renderLine(array $line, int $w, bool $withPrices): string
    {
        $head = $line['qty'] . ' x ' . $line['name'];
        if ($withPrices) {
            $b = EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::lineKV($head, $this->money($line['total']), $w);
            $b .= EscPosCommandBuilder::bold(false);
            // Unit price for multi-quantity lines (clarity + good fiscal practice).
            if ((int) $line['qty'] > 1 && (float) $line['total'] > 0) {
                $unit = round((float) $line['total'] / (int) $line['qty'], 2);
                $b .= EscPosCommandBuilder::textLine('  (' . $line['qty'] . ' x ' . $this->money($unit) . ')');
            }
        } else {
            $b = EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine($head) . EscPosCommandBuilder::bold(false);
        }
        foreach ($line['comps'] as $c) {
            $b .= EscPosCommandBuilder::textLine('  - ' . $c);
        }
        foreach (array_merge($line['extras'], $line['addons']) as $opt) {
            $label = '  + ' . $opt['name'];
            if ($withPrices && $opt['amount'] > 0) {
                $b .= EscPosCommandBuilder::lineKV($label, $this->money($opt['amount']), $w);
            } else {
                $b .= EscPosCommandBuilder::textLine($label);
            }
        }
        if ($line['instruction'] !== '') {
            $b .= EscPosCommandBuilder::textLine('  ** ' . $line['instruction']);
        }

        return $b;
    }

    /** @return array<int, array{label:string, amount:float, change:float}> */
    private function payments(BroadcastableOrder $order): array
    {
        $labels = [1 => 'Espèces', 2 => 'Carte', 3 => 'Mixte', 4 => 'Carte', 5 => 'Ticket Resto'];
        $method = $order->pos_payment_method ?? null;
        if ($method === null || $method === '') {
            return [];
        }
        $amount = $order->pos_received_amount !== null && $order->pos_received_amount !== ''
            ? (float) $order->pos_received_amount
            : (float) ($order->total ?? 0);

        return [[
            'label' => $labels[(int) $method] ?? ('Paiement ' . $method),
            'amount' => $amount,
            'change' => (float) ($order->cash_back_amount ?? 0),
        ]];
    }

    private function orderTypeLabel(BroadcastableOrder $order): string
    {
        return match ((int) ($order->order_type ?? 0)) {
            \App\Enums\OrderType::DELIVERY => 'Livraison',
            \App\Enums\OrderType::TAKEAWAY => 'À emporter',
            \App\Enums\OrderType::DINING_TABLE => 'Sur place',
            \App\Enums\OrderType::KIOSK => 'Commande borne',
            \App\Enums\OrderType::POS => 'Sur place',
            default => '',
        };
    }

    /**
     * Ventilation de la TVA par taux — mirror of OrderDetailsResource::buildTaxLines
     * (groupe par taux, base HT = total TTC ligne - TVA ligne).
     *
     * @return array<int, array{name:string, rate:string, ht:float, tax:float}>
     */
    private function taxLines(BroadcastableOrder $order): array
    {
        $groups = [];
        foreach (($order->orderItems ?? collect()) as $oi) {
            $rate = (string) (0 + (float) ($oi->tax_rate ?? 0));
            $name = (string) ($oi->tax_name ?? 'TVA');
            $type = (int) ($oi->tax_type ?? 0);
            $key = $type . '|' . $rate . '|' . $name;
            if (! isset($groups[$key])) {
                $groups[$key] = ['name' => $name, 'rate' => $rate, 'ht' => 0.0, 'tax' => 0.0];
            }
            $tax = (float) ($oi->tax_amount ?? 0);
            $ttc = (float) ($oi->total_price ?? 0);
            $groups[$key]['tax'] += $tax;
            $groups[$key]['ht'] += max(0.0, $ttc - $tax);
        }

        // Drop zero-tax groups (e.g. items with no rate) — nothing to ventilate.
        return array_values(array_filter($groups, fn ($g) => $g['tax'] > 0));
    }

    private function money(float $v): string
    {
        return number_format(round($v, 2), 2, ',', ' ') . ' EUR';
    }

    private function encodingFor(int $codePage): string
    {
        return $codePage === 16 ? 'CP1252' : 'CP858';
    }
}
