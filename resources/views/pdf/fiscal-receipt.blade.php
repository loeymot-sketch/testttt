@php
    /**
     * [P0-4 / KIO-6] Fallback fiscal receipt template (NF525).
     *
     * Width: 80 mm thermal ticket → 220 pt page width, 210 px content.
     * Stored variations / extras on the OrderItem row are JSON STRINGS
     * (cast='string') so we decode them inline. The shape is:
     *   [{ id, name?, price?, quantity? }, ...]
     * (Names may be missing if the row was written before the rich
     * snapshot; we degrade gracefully.)
     */

    $currency = config('settings.currency_symbol', env('CURRENCY', '€'));

    $decode = static function ($maybeJson): array {
        if (is_array($maybeJson)) {
            return $maybeJson;
        }
        if (!is_string($maybeJson) || $maybeJson === '') {
            return [];
        }
        $decoded = json_decode($maybeJson, true);
        return is_array($decoded) ? $decoded : [];
    };

    $totalTax = (float) ($order->total_tax ?? 0);
    $subtotal = (float) ($order->subtotal ?? 0);
    $totalHt  = max(0.0, $subtotal - $totalTax);
    $total    = (float) ($order->total ?? 0);
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Ticket #{{ $order->order_serial_no ?? $order->id }}</title>
    <style>
        body  { font-family: monospace, "Courier New", Courier; font-size: 9px; margin: 5px; width: 210px; color: #000; }
        .center { text-align: center; }
        .right  { text-align: right; }
        .b      { font-weight: bold; }
        .line   { border-top: 1px dashed #000; margin: 3px 0; }
        table   { width: 100%; border-collapse: collapse; }
        td      { vertical-align: top; padding: 0; }
        .small  { font-size: 7px; }
    </style>
</head>
<body>

<div class="center b">{{ $company_name }}</div>
@if($branch)
    <div class="center">{{ $branch->name }}</div>
    @if(!empty($branch->address))
        <div class="center">{{ $branch->address }}</div>
    @endif
@endif
<div class="line"></div>

<div>Ticket N° {{ $order->order_serial_no ?? $order->id }}</div>
<div>Date : {{ $order->order_datetime ?? $order->created_at }}</div>
@if(!empty($fiscal_sequence_no))
    <div class="b">N° fiscal : #{{ $fiscal_sequence_no }}</div>
@endif

<div class="line"></div>

<table>
    @foreach($order->orderItems as $oi)
        @php
            $itemName = $oi->orderItem->name ?? 'N/A';
            $variations = $decode($oi->item_variations);
            $extras     = $decode($oi->item_extras);
            $lineTotal  = (float) ($oi->total_price ?? 0);
        @endphp
        <tr>
            <td>{{ $oi->quantity }}× {{ $itemName }}</td>
            <td class="right">{{ number_format($lineTotal, 2) }}{{ $currency }}</td>
        </tr>
        @foreach($variations as $v)
            @php $vName = is_array($v) ? ($v['name'] ?? '') : ''; @endphp
            @if($vName !== '')
                <tr>
                    <td colspan="2" style="padding-left:10px">+ {{ $vName }}</td>
                </tr>
            @endif
        @endforeach
        @foreach($extras as $e)
            @php $eName = is_array($e) ? ($e['name'] ?? '') : ''; @endphp
            @if($eName !== '')
                <tr>
                    <td colspan="2" style="padding-left:10px">+ {{ $eName }}</td>
                </tr>
            @endif
        @endforeach
    @endforeach
</table>

<div class="line"></div>

<table>
    <tr><td>Sous-total HT</td><td class="right">{{ number_format($totalHt, 2) }}{{ $currency }}</td></tr>
    <tr><td>TVA</td>           <td class="right">{{ number_format($totalTax, 2) }}{{ $currency }}</td></tr>
    <tr><td class="b">Total TTC</td><td class="right b">{{ number_format($total, 2) }}{{ $currency }}</td></tr>
</table>

<div class="line"></div>

<div class="center">Mode : {{ $order->payment_method ?? '?' }}</div>
@if(!empty($order->transaction))
    <div class="center">Transaction : {{ $order->transaction->transaction_no ?? 'N/A' }}</div>
@endif

<div class="line"></div>
<div class="center">Merci de votre visite</div>
<div class="center small">Conservez ce ticket pour toute reclamation</div>

</body>
</html>
