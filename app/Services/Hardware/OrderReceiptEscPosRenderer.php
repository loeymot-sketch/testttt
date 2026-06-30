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

        // ── En-tête établissement (centré) ──────────────────────────────────
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine(optional($branch)->name ?: 'LE CAYENNE');
        $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        if (optional($branch)->address) {
            $b .= EscPosCommandBuilder::textLine((string) $branch->address);
        }
        if (optional($branch)->phone) {
            $b .= EscPosCommandBuilder::textLine('Tél : ' . $this->formatPhone((string) $branch->phone));
        }
        if (optional($branch)->email) {
            $b .= EscPosCommandBuilder::textLine('E-mail : ' . $branch->email);
        }
        $website = (string) config('printing.receipt.website', '');
        if ($website !== '') {
            $b .= EscPosCommandBuilder::textLine('Web : ' . $website);
        }
        if (! empty($head['pos_siret'])) {
            $b .= EscPosCommandBuilder::textLine('SIRET ' . $head['pos_siret']);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── N° de ticket (gros) + date (centrés) ────────────────────────────
        $serial = (string) ($order->order_serial_no ?? $order->id);
        $ticketNo = (string) ($order->queue_number ?: $serial);
        $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine($ticketNo);
        $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        $dt = $order->order_datetime ?? $order->created_at;
        if ($dt) {
            $b .= EscPosCommandBuilder::textLine($this->frenchDateTime($dt));
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── Bannière type de commande ───────────────────────────────────────
        $orderType = $this->orderTypeLabel($order);
        if ($orderType !== '') {
            $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine('*** ' . mb_strtoupper($orderType) . ' ***');
            $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        }
        if ($counterCopy) {
            $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('*** COMMANDE BORNE - COPIE CAISSE ***') . EscPosCommandBuilder::bold(false);
        }
        if ($isDuplicata) {
            $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('*** DUPLICATA ***') . EscPosCommandBuilder::bold(false);
        }

        // ── Articles ────────────────────────────────────────────────────────
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::doubleHeight(true); // [TICKET-BIG] corps grand & lisible (48 col conservées)
        $b .= EscPosCommandBuilder::feed(1);
        $b .= EscPosCommandBuilder::lineKV('QT ARTICLES', 'MONTANT', $w);
        $b .= EscPosCommandBuilder::separator('-', $w);
        foreach ($this->lines($order) as $line) {
            $b .= $this->renderClientItem($line, $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── Totaux ──────────────────────────────────────────────────────────
        $b .= EscPosCommandBuilder::lineKV('SOUS-TOTAL :', $this->money((float) ($order->subtotal ?? 0)), $w);
        $discount = (float) ($order->discount ?? 0);
        $b .= EscPosCommandBuilder::lineKV('REDUCTION :', ($discount > 0 ? '-' : '') . $this->money($discount), $w);
        $delivery = (float) ($order->delivery_charge ?? 0);
        if ($delivery > 0) {
            $b .= EscPosCommandBuilder::lineKV('LIVRAISON :', $this->money($delivery), $w);
        }
        $b .= EscPosCommandBuilder::separator('-', $w);
        $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::doubleSize(true);
        $b .= EscPosCommandBuilder::lineKV('MONTANT TOTAL:', $this->money((float) ($order->total ?? 0)), max(20, (int) floor($w / 2)));
        $b .= EscPosCommandBuilder::doubleHeight(true) . EscPosCommandBuilder::bold(false); // retour corps grand (pas normal)
        $b .= EscPosCommandBuilder::separator('-', $w);

        // ── TVA (par taux) ──────────────────────────────────────────────────
        $taxLines = $this->taxLines($order);
        if (! empty($taxLines)) {
            foreach ($taxLines as $tl) {
                $rate = rtrim(rtrim(number_format((float) $tl['rate'], 2, ',', ''), '0'), ',');
                $b .= EscPosCommandBuilder::lineKV('TVA ' . $rate . '% :', $this->money($tl['tax']), $w);
            }
        } elseif ((float) ($order->total_tax ?? 0) > 0) {
            $b .= EscPosCommandBuilder::lineKV('TVA :', $this->money((float) $order->total_tax), $w);
        }

        // ── Paiement (ou à régler en caisse si non payé) ────────────────────
        $payments = $this->payments($order);
        if (! empty($payments)) {
            foreach ($payments as $p) {
                $b .= EscPosCommandBuilder::lineKV(mb_strtoupper($p['label']) . ' :', $this->money($p['amount']), $w);
                if (($p['change'] ?? 0) > 0) {
                    $b .= EscPosCommandBuilder::lineKV('RENDU :', $this->money($p['change']), $w);
                }
            }
        } elseif ((int) ($order->payment_status ?? 0) === \App\Enums\PaymentStatus::PAID) {
            // Paid outside the POS tender flow (e.g. online/web order) — never tell the
            // customer to pay again; just confirm it is settled.
            $b .= EscPosCommandBuilder::alignCenter();
            $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('*** PAYÉ ***') . EscPosCommandBuilder::bold(false);
            $b .= EscPosCommandBuilder::alignLeft();
        } elseif ((float) ($order->total ?? 0) > 0) {
            $b .= EscPosCommandBuilder::lineKV('A REGLER TTC :', $this->money((float) $order->total), $w);
            $b .= EscPosCommandBuilder::alignCenter();
            $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('** A REGLER EN CAISSE **') . EscPosCommandBuilder::bold(false);
            $b .= EscPosCommandBuilder::alignLeft();
        }

        // ── Pied : remerciement + mentions fiscales (centré) ────────────────
        $b .= EscPosCommandBuilder::separator('-', $w);
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('BON APPÉTIT ET À BIENTÔT !') . EscPosCommandBuilder::bold(false);
        $b .= EscPosCommandBuilder::doubleHeight(false); // mentions légales en taille normale (fine print)
        $b .= EscPosCommandBuilder::feed(1);
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

        // ── Bloc opération (gauche, infos techniques discrètes) ─────────────
        $b .= EscPosCommandBuilder::separator('-', $w);
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::textLine('Operation : VENTE');
        if (! empty($head['operator_name'])) {
            $b .= EscPosCommandBuilder::textLine('Caissier : ' . $head['operator_name']);
        }
        $b .= EscPosCommandBuilder::textLine('Ticket : ' . $serial);
        if ($dt) {
            $b .= EscPosCommandBuilder::textLine($this->frenchDateTime($dt));
        }
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
        $b .= EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine('CUISINE');
        $b .= EscPosCommandBuilder::bold(false);
        // [AUDIT F1] Same call number as the client ticket (queue, not the long serial),
        // big so the cook can match it when handing the order over.
        $callNo = (string) ($order->queue_number ?: ($order->order_serial_no ?? $order->id));
        $b .= EscPosCommandBuilder::doubleSize(true) . EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::textLine($callNo);
        $b .= EscPosCommandBuilder::doubleSize(false) . EscPosCommandBuilder::bold(false);
        // [AUDIT F2] Order type — packaging/prep differs (sur place / à emporter / livraison).
        $orderType = $this->orderTypeLabel($order);
        if ($orderType !== '') {
            $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('*** ' . mb_strtoupper($orderType) . ' ***') . EscPosCommandBuilder::bold(false);
        }
        $dt = $order->order_datetime ?? $order->created_at;
        if ($dt) {
            $b .= EscPosCommandBuilder::textLine($dt->format('H:i'));
        }
        $b .= EscPosCommandBuilder::separator('=', $w);
        $b .= EscPosCommandBuilder::alignLeft();
        $b .= EscPosCommandBuilder::doubleHeight(true); // [TICKET-BIG] cuisine grand & lisible

        // [KITCHEN-SYMBOLS 2026-06-28] Owner: the cook reads symbolic shorthand
        // (G | SANDWICH | P | STO | SAM), not prose. Same table as the KDS screen.
        foreach (($order->orderItems ?? collect()) as $oi) {
            $name = (string) ($oi->name ?? optional($oi->orderItem)->name ?? 'Article');
            $snap = is_array($oi->composition_snapshot) ? $oi->composition_snapshot : [];
            $qty = max(1, (int) ($oi->quantity ?? 1));
            $instruction = (string) ($oi->instruction ?? '');

            // [KITCHEN-MENU 2026-06-30] Un item « Menu/Formule » → juste « MENU »
            // (+ sauce frites en SYMBOLE), AUCUN prix ni « Frites + Boisson ». Le menu,
            // c'est frites + boisson : rien à préparer de plus, juste la sauce des frites.
            if ($this->symbolic->isMenuItem($name)) {
                $sym = $this->symbolic->fritesSauceSymbol($instruction);
                $line = $sym !== '' ? 'MENU : ' . $sym : 'MENU';
                $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine($qty . ' x ' . $line) . EscPosCommandBuilder::bold(false);
                $b .= EscPosCommandBuilder::textLine('');

                continue;
            }

            $main = $this->symbolic->mainLine($name, $snap);
            $b .= EscPosCommandBuilder::bold(true);
            $b .= EscPosCommandBuilder::textLine($qty . ' x ' . $main);
            $b .= EscPosCommandBuilder::bold(false);

            foreach ($this->symbolic->supplementLines($snap) as $sup) {
                $b .= EscPosCommandBuilder::textLine('  ' . $sup);
            }
            // Menu attaché au produit (addon) → « MENU » ou « MENU : SYM » (sauce frites symbole).
            $menu = $this->symbolic->menuLine($snap);
            if ($menu === 'MENU') {
                $sym = $this->symbolic->fritesSauceSymbol($instruction);
                $menu = $sym !== '' ? 'MENU : ' . $sym : 'MENU';
            }
            if ($menu !== '') {
                $b .= EscPosCommandBuilder::bold(true) . EscPosCommandBuilder::textLine('  ' . $menu) . EscPosCommandBuilder::bold(false);
            }
            $note = $this->symbolic->cleanInstruction($instruction, $name); // sans menu/sauce-frites (anti double)
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

    /**
     * One article block on the client ticket (VAZY-GOOD style) :
     *   "1  Tacos M ................ 9,40 EUR"
     *   "   Cordon Bleu, Fricadelle, Samouraï"     (compo + free crudités, compact)
     *   "   + Cheddar ............... 0,90 EUR"     (paid supplements, with price)
     */
    private function renderClientItem(array $line, int $w): string
    {
        $qty = (int) $line['qty'];
        $b = EscPosCommandBuilder::bold(true);
        $b .= EscPosCommandBuilder::lineKV($qty . '  ' . $line['name'], $this->money((float) $line['total']), $w);
        $b .= EscPosCommandBuilder::bold(false);
        if ($qty > 1 && (float) $line['total'] > 0) {
            $unit = round((float) $line['total'] / $qty, 2);
            $b .= EscPosCommandBuilder::textLine('   (' . $qty . ' x ' . $this->money($unit) . ')');
        }

        // Compo values (strip the "Group: " prefix) + free (0-price) extras → one compact line.
        $compo = [];
        foreach ($line['comps'] as $c) {
            $pos = mb_strpos($c, ': ');
            $compo[] = $pos !== false ? mb_substr($c, $pos + 2) : $c;
        }
        $paid = [];
        foreach ($line['extras'] as $e) {
            if (($e['amount'] ?? 0) > 0) {
                $paid[] = $e;
            } else {
                $compo[] = $e['name'];
            }
        }
        if (! empty($compo)) {
            $b .= EscPosCommandBuilder::textLine('   ' . implode(', ', $compo));
        }
        foreach ($paid as $e) {
            $b .= EscPosCommandBuilder::lineKV('   + ' . $e['name'], $this->money((float) $e['amount']), $w);
        }
        foreach ($line['addons'] as $a) {
            if (($a['amount'] ?? 0) > 0) {
                $b .= EscPosCommandBuilder::lineKV('   + ' . $a['name'], $this->money((float) $a['amount']), $w);
            } else {
                $b .= EscPosCommandBuilder::textLine('   + ' . $a['name']);
            }
        }
        // Note client assainie : on retire le nom-produit échoé, le blob de compo
        // (déjà affiché) et les lignes menu/sauce-frites (le menu est sa propre ligne).
        $note = $this->symbolic->cleanInstruction((string) ($line['instruction'] ?? ''), (string) $line['name']);
        foreach (array_filter(explode("\n", $note)) as $noteLine) {
            $b .= EscPosCommandBuilder::textLine('   ** ' . trim($noteLine));
        }

        return $b;
    }

    /** "0365678291" → "03 65 67 82 91" (French 10-digit); otherwise unchanged. */
    private function formatPhone(string $p): string
    {
        $digits = preg_replace('/\D+/', '', $p);
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return trim(chunk_split($digits, 2, ' '));
        }

        return trim($p);
    }

    /** "27 juin 2026 23:49" — French long date without a locale dependency. */
    private function frenchDateTime($dt): string
    {
        if (! $dt) {
            return '';
        }
        $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $m = (int) $dt->format('n');

        return (int) $dt->format('j') . ' ' . ($months[$m] ?? '') . ' ' . $dt->format('Y') . ' ' . $dt->format('H:i');
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

        $groups = array_values(array_filter($groups, fn ($g) => $g['tax'] > 0));

        // [AUDIT P2-1] An order-level discount is NOT allocated back to per-line tax,
        // so the gross ventilation would overstate the VAT and not reconcile with the
        // (discounted) total. Prorate each rate by the net/gross ratio on the goods so
        // Σ(base HT + TVA) matches what the customer actually pays.
        $discount = (float) ($order->discount ?? 0);
        if ($discount > 0 && ! empty($groups)) {
            $gross = array_sum(array_map(fn ($g) => $g['ht'] + $g['tax'], $groups));
            if ($gross > 0) {
                $ratio = max(0.0, ($gross - $discount) / $gross);
                foreach ($groups as &$g) {
                    $g['ht'] = round($g['ht'] * $ratio, 2);
                    $g['tax'] = round($g['tax'] * $ratio, 2);
                }
                unset($g);
            }
        }

        return $groups;
    }

    private function money(float $v): string
    {
        // Owner: symbole € (pas « EUR »). CP858 (code page 19) contient € → encodé
        // correctement par encodeForPrinter en fin de flux.
        return number_format(round($v, 2), 2, ',', ' ') . ' €';
    }

    private function encodingFor(int $codePage): string
    {
        return $codePage === 16 ? 'CP1252' : 'CP858';
    }
}
