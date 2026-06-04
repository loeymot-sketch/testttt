<?php

namespace App\Services\Receipt;

use App\Contracts\BroadcastableOrder;
use App\Enums\PosPaymentMethod;
use App\Services\Hardware\EscPosCommandBuilder as B;

/**
 * [POS PRINTER 2026-06-04] Server-side ESC/POS renderer for the NF525 POS
 * customer ticket.
 *
 * Turns an Order into the raw ESC/POS byte stream sent to the SAGA SGPR-200II
 * (80mm thermal, ESC/POS, escpos_tcp transport on LAN port 9100). It is the
 * server-side mirror of the owner-validated on-screen ticket
 * ({@see resources/js/components/admin/pos/ReceiptComponent.vue} +
 * {@see resources/js/helpers/posReceiptBuilder.js}) and the kiosk ESC/POS
 * builder ({@see resources/js/helpers/kioskPrinter.js::buildEscPosReceipt}).
 *
 * The six NF525 fiscal/operator header fields come from the SSOT
 * {@see ReceiptDataService} so the printed ticket never drifts from the HTTP
 * API receipt. The per-rate VAT breakdown mirrors
 * {@see \App\Http\Resources\OrderDetailsResource::buildTaxLines()}
 * (CGI art. 242 nonies A — every receipt lists HT base + tax per rate).
 *
 * Pure: no DB writes, no network, no mutation. Accents are transcoded to the
 * printer code page (default CP858 / page 19) so "é à € ç" do not print as
 * mojibake and the column padding stays aligned (byte width == char width).
 */
final class PosReceiptEscPosRenderer
{
    public function __construct(
        private readonly ReceiptDataService $receiptData,
    ) {
    }

    /**
     * @param  array{is_duplicata?:bool,width_chars?:int,code_page?:int,reprint_count?:int}  $opts
     */
    public function render(BroadcastableOrder $order, array $opts = []): string
    {
        $w = (int) ($opts['width_chars'] ?? 48);
        $w = $w > 0 ? $w : 48;
        $codePage = (int) ($opts['code_page'] ?? 19);
        $isDuplicata = (bool) ($opts['is_duplicata'] ?? false);

        // SSOT fiscal/operator header (fiscal_sequence_no, siret, vat_intra,
        // register_id, legal_footer, operator_name, order_serial_no).
        $header = $this->receiptData->buildForOrderModel($order);
        $branch = $order->branch ?? null;
        $branchName = $this->str($branch->name ?? null) ?: 'LE CAYENNE';

        $out = '';
        $out .= B::init();
        $out .= B::selectCodePage($codePage);

        // ── Header ────────────────────────────────────────────────────────
        $out .= B::alignCenter();
        $out .= B::doubleSize(true) . B::bold(true);
        $out .= $this->enc(B::textLine($branchName));
        $out .= B::doubleSize(false) . B::bold(false);

        $address = $this->str(($branch->address ?? null));
        if ($address !== '') {
            $out .= $this->enc(B::textLine($address));
        }
        if (! empty($header['pos_siret'])) {
            $out .= $this->enc(B::textLine('SIRET ' . $this->str($header['pos_siret'])));
        }
        if (! empty($header['pos_vat_intra'])) {
            $out .= $this->enc(B::textLine('TVA ' . $this->str($header['pos_vat_intra'])));
        }

        $out .= B::feed(1);
        $out .= B::separator('-', $w);

        if ($isDuplicata) {
            $out .= B::bold(true) . B::doubleSize(true);
            $out .= $this->enc(B::textLine('DUPLICATA'));
            $out .= B::doubleSize(false) . B::bold(false);
            $out .= B::separator('-', $w);
        }

        // ── Order identity + operator ───────────────────────────────────────
        $out .= B::alignLeft();
        $serial = $this->str($header['order_serial_no'] ?? $order->order_serial_no ?? '');
        if ($serial !== '') {
            $out .= $this->enc(B::lineKV('Commande', $serial, $w));
        }
        $queue = $this->str($order->queue_number ?? '');
        if ($queue !== '') {
            $out .= $this->enc(B::lineKV('N. appel', $queue, $w));
        }
        if (! empty($header['fiscal_sequence_no'])) {
            $out .= $this->enc(B::lineKV('Ticket fiscal', '#' . $this->str($header['fiscal_sequence_no']), $w));
        }
        if (! empty($header['pos_register_id'])) {
            $out .= $this->enc(B::lineKV('Caisse', $this->str($header['pos_register_id']), $w));
        }
        if (! empty($header['operator_name'])) {
            $out .= $this->enc(B::lineKV('Operateur', $this->str($header['operator_name']), $w));
        }
        $date = $this->orderDate($order);
        if ($date !== '') {
            $out .= $this->enc(B::lineKV('Date', $date, $w));
        }

        $out .= B::separator('-', $w);

        // ── Items ────────────────────────────────────────────────────────
        foreach ($this->items($order) as $row) {
            $label = $row['quantity'] . 'x ' . $row['name'];
            $out .= B::bold(true);
            $out .= $this->enc(B::lineKV($label, $this->money($row['total_price']), $w));
            $out .= B::bold(false);
            foreach ($row['details'] as $detail) {
                $out .= $this->enc(B::textLine('  ' . $detail));
            }
        }

        $out .= B::separator('-', $w);

        // ── Totals ─────────────────────────────────────────────────────────
        $subtotal = $this->num($order->subtotal ?? null);
        $totalTax = $this->num($order->total_tax ?? null);
        $discount = $this->num($order->discount ?? null);
        $total = $this->num($order->total ?? null);

        if ($subtotal > 0 && ($discount > 0 || $totalTax > 0)) {
            $out .= $this->enc(B::lineKV('Sous-total', $this->money($subtotal), $w));
        }
        if ($discount > 0) {
            $out .= $this->enc(B::lineKV('Remise', '-' . $this->money($discount), $w));
        }

        // Bold only (NOT doubleSize): doubleSize doubles WIDTH too, so a
        // full-width lineKV would overflow the 80mm carriage (~24 double-wide
        // columns). Bold keeps the 48-column alignment intact.
        $out .= B::bold(true);
        $out .= $this->enc(B::lineKV('TOTAL A PAYER', $this->money($total), $w));
        $out .= B::bold(false);

        // Per-rate VAT breakdown (NF525 — CGI art. 242 nonies A).
        $taxLines = $this->taxLines($order);
        if ($taxLines !== []) {
            $out .= B::separator('-', $w);
            $out .= $this->enc(B::textLine('TVA'));
            foreach ($taxLines as $tl) {
                // Rate is already normalised in taxLines() via (string)(0+(float)…)
                // so "10.000000" -> "10" and "5.5" stays "5.5" (no trailing zeros).
                $rate = (string) $tl['rate'];
                $out .= $this->enc(B::lineKV(
                    '  ' . $rate . '%  HT ' . $this->money($tl['base_ht']),
                    'TVA ' . $this->money($tl['tax']),
                    $w
                ));
            }
        }

        // ── Payment ─────────────────────────────────────────────────────────
        $payments = $this->payments($order);
        if ($payments !== []) {
            $out .= B::separator('-', $w);
            foreach ($payments as $p) {
                $out .= $this->enc(B::lineKV($p['method'], $this->money($p['amount']), $w));
                if ($p['change'] > 0) {
                    $out .= $this->enc(B::lineKV('  Rendu', $this->money($p['change']), $w));
                }
            }
        }

        // ── Footer ──────────────────────────────────────────────────────────
        $out .= B::separator('-', $w);
        $out .= B::alignCenter();
        $footer = $this->str($header['pos_legal_footer'] ?? '');
        if ($footer !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $footer) ?: [] as $fl) {
                $fl = trim($fl);
                if ($fl !== '') {
                    $out .= $this->enc(B::textLine($fl));
                }
            }
        }
        $out .= $this->enc(B::textLine('Merci de votre visite !'));
        $out .= $this->enc(B::textLine('A bientot'));

        $out .= B::feed(3);
        $out .= B::cut();

        return $out;
    }

    /** encodeForPrinter wrapper — only ever called on pure text+LF segments. */
    private function enc(string $textSegment): string
    {
        return B::encodeForPrinter($textSegment);
    }

    /**
     * @return list<array{quantity:int,name:string,total_price:float,details:list<string>}>
     */
    private function items(BroadcastableOrder $order): array
    {
        $rows = [];

        try {
            $items = $order->relationLoaded('orderItems')
                ? $order->orderItems
                : (method_exists($order, 'orderItems') ? $order->orderItems()->with('orderItem')->get() : collect());
        } catch (\Throwable) {
            return [];
        }

        foreach ($items as $oi) {
            $name = $this->str($oi->orderItem->name ?? $oi->name ?? 'Article') ?: 'Article';
            $rows[] = [
                'quantity' => (int) ($oi->quantity ?? 1),
                'name' => $name,
                'total_price' => $this->num($oi->total_price ?? $oi->price ?? null),
                'details' => $this->itemDetails($oi),
            ];
        }

        return $rows;
    }

    /**
     * Human-readable composition lines (variations / extras / addons /
     * instruction) extracted from the immutable composition_snapshot, tolerant
     * of the several historical shapes (snapshot.lines / .extras / .addons,
     * legacy item_variations / item_extras JSON).
     *
     * @return list<string>
     */
    private function itemDetails($oi): array
    {
        $details = [];
        $snapshot = $this->decode($oi->composition_snapshot ?? null);

        $collect = function ($bucket) use (&$details): void {
            if (! is_array($bucket)) {
                return;
            }
            foreach ($bucket as $line) {
                if (! is_array($line)) {
                    if (is_string($line) && trim($line) !== '') {
                        $details[] = '- ' . trim($line);
                    }
                    continue;
                }
                $name = $line['variation_name']
                    ?? $line['name']
                    ?? $line['attribute_name']
                    ?? $line['extra_name']
                    ?? $line['addon_name']
                    ?? null;
                if ($name === null || trim((string) $name) === '') {
                    continue;
                }
                $qty = isset($line['quantity']) && (int) $line['quantity'] > 1
                    ? (int) $line['quantity'] . 'x '
                    : '';
                $details[] = '- ' . $qty . $this->str((string) $name);
            }
        };

        if (is_array($snapshot)) {
            $collect($snapshot['lines'] ?? null);
            $collect($snapshot['extras'] ?? null);
            $collect($snapshot['addons'] ?? null);
        }
        if ($details === []) {
            $collect($this->decode($oi->item_variations ?? null));
            $collect($this->decode($oi->item_extras ?? null));
        }

        $instruction = $this->str($oi->instruction ?? '');
        if ($instruction !== '') {
            $details[] = '> ' . $instruction;
        }

        return $details;
    }

    /**
     * Per-rate VAT grouping — mirror of OrderDetailsResource::buildTaxLines().
     *
     * @return list<array{rate:string,base_ht:float,tax:float}>
     */
    private function taxLines(BroadcastableOrder $order): array
    {
        try {
            $items = $order->relationLoaded('orderItems')
                ? $order->orderItems
                : (method_exists($order, 'orderItems') ? $order->orderItems()->get() : collect());
        } catch (\Throwable) {
            return [];
        }

        $groups = [];
        foreach ($items as $oi) {
            $rate = (string) (0 + (float) ($oi->tax_rate ?? 0));
            $taxAmount = (float) ($oi->tax_amount ?? 0);
            $totalTtc = (float) ($oi->total_price ?? 0);
            if (! isset($groups[$rate])) {
                $groups[$rate] = ['rate' => $rate, 'tax' => 0.0, 'base_ht' => 0.0];
            }
            $groups[$rate]['tax'] += $taxAmount;
            $groups[$rate]['base_ht'] += max(0.0, $totalTtc - $taxAmount);
        }

        $out = [];
        foreach ($groups as $g) {
            if ($g['tax'] <= 0 && $g['base_ht'] <= 0) {
                continue;
            }
            $out[] = [
                'rate' => $g['rate'],
                'base_ht' => round($g['base_ht'], 2),
                'tax' => round($g['tax'], 2),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{method:string,amount:float,change:float}>
     */
    private function payments(BroadcastableOrder $order): array
    {
        $out = [];

        try {
            if (method_exists($order, 'payments')) {
                $payments = $order->relationLoaded('payments')
                    ? ($order->payments ?? collect())
                    : $order->payments()->get();
                foreach ($payments as $p) {
                    $method = $this->str($p->method ?? $p->payment_method ?? '') ?: 'Paiement';
                    $out[] = [
                        'method' => $this->paymentLabel($method),
                        'amount' => (float) ($p->amount ?? 0),
                        'change' => (float) ($p->change_amount ?? 0),
                    ];
                }
            }
        } catch (\Throwable) {
            // fall through to synthetic single-tender line
        }

        if ($out === [] && ! empty($order->pos_payment_method)) {
            $received = (float) ($order->pos_received_amount ?? 0);
            $total = (float) ($order->total ?? 0);
            $out[] = [
                'method' => $this->paymentLabel($this->str($order->pos_payment_method)),
                'amount' => $received > 0 ? $received : $total,
                'change' => max(0.0, $received - $total),
            ];
        }

        return $out;
    }

    private function paymentLabel(string $method): string
    {
        $m = trim($method);

        // pos_payment_method and OrderPayment.method are stored as the numeric
        // PosPaymentMethod enum value (e.g. "1"), not a string label.
        if ($m !== '' && ctype_digit($m)) {
            return match ((int) $m) {
                PosPaymentMethod::CASH => 'Especes',
                PosPaymentMethod::CARD => 'Carte bancaire',
                PosPaymentMethod::MOBILE_BANKING => 'Paiement mobile',
                PosPaymentMethod::TICKET_RESTAURANT => 'Titre-resto',
                PosPaymentMethod::COUNTER_DEFERRED => 'Au comptoir',
                default => 'Autre',
            };
        }

        return match (strtolower($m)) {
            'cash', 'especes', 'cash_on_delivery' => 'Especes',
            'card', 'cb', 'credit_card', 'carte' => 'Carte bancaire',
            'tr', 'ticket_restaurant', 'titre_restaurant', 'conecs' => 'Titre-resto',
            'mobile_banking', 'mobile' => 'Paiement mobile',
            default => $m !== '' ? ucfirst($m) : 'Paiement',
        };
    }

    private function orderDate(BroadcastableOrder $order): string
    {
        $dt = $order->order_datetime ?? $order->created_at ?? null;
        if ($dt === null) {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::parse($dt)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ') . ' €';
    }

    private function num(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function str(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
