{{--
    [FACTURE PRO 2026-11-14 · propriétaire] Document A4 d'une facture pro, imprimé depuis la caisse
    (iframe + impression du navigateur). Mentions de l'article 242 nonies A du CGI.

    HORS TICKET DE CAISSE : ce document n'est pas une vente certifiée NF525 et le dit en toutes
    lettres. L'identité du vendeur vient des réglages ; un champ absent s'affiche « à compléter
    dans les réglages » — jamais une valeur inventée.
--}}
@php
    $eur = fn ($v) => number_format((float) $v, 2, ',', "\u{202F}") . "\u{00A0}€";
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') . "\u{00A0}%";
    $missing = 'à compléter dans les réglages';
    $htCents = (int) round(((float) $invoice->amount_ht) * 100);
    $qty = max(1, (int) $invoice->quantity);
    $unitHt = $htCents % $qty === 0
        ? number_format($htCents / $qty / 100, 2, ',', "\u{202F}")
        : number_format($htCents / $qty / 100, 4, ',', "\u{202F}");
    $issued = $invoice->created_at ?? now();
    $cancelled = $invoice->cancelled_at !== null;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facture {{ $invoice->number }}</title>
    <style>
        @page { size: A4; margin: 14mm 14mm 16mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #FFFFFF; color: #1A1A1A; }
        body { font-family: -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 12.5px; line-height: 1.45; }
        .page { position: relative; max-width: 182mm; margin: 0 auto; padding: 8mm 0; }
        @media screen { .page { padding: 12mm 10mm; } }
        .head { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid #F4501E; padding-bottom: 14px; }
        .seller-name { font-size: 20px; font-weight: 800; margin: 0 0 4px; }
        .seller p, .client p { margin: 1px 0; }
        .missing { color: #B42318; font-style: italic; }
        .doc { text-align: right; }
        .doc h1 { margin: 0; font-size: 26px; letter-spacing: .06em; }
        .doc .num { font-size: 15px; font-weight: 700; margin-top: 2px; }
        .doc p { margin: 2px 0; }
        .client { margin: 22px 0 18px auto; width: 55%; border: 1px solid #DADADA; border-radius: 6px; padding: 10px 14px; }
        .client .tag { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em; color: #6B6B6B; margin-bottom: 4px; }
        .client .name { font-weight: 700; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #4A4A4A; border-bottom: 1.5px solid #1A1A1A; padding: 6px 6px; }
        td { padding: 9px 6px; border-bottom: 1px solid #E5E5E5; vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .totals { margin: 14px 0 0 auto; width: 46%; }
        .totals div { display: flex; justify-content: space-between; padding: 4px 6px; }
        .totals .ttc { border-top: 1.5px solid #1A1A1A; margin-top: 4px; padding-top: 8px; font-size: 15px; font-weight: 800; }
        .paid { margin-top: 22px; padding: 10px 14px; border-left: 4px solid #1A7F37; background: #F1F8F3; }
        .paid strong { color: #1A7F37; }
        .notes { margin-top: 14px; color: #3A3A3A; white-space: pre-line; }
        .mention { margin-top: 26px; padding: 10px 14px; border: 1.5px dashed #F4501E; border-radius: 6px; font-weight: 700; text-align: center; }
        .foot { margin-top: 14px; font-size: 10.5px; color: #6B6B6B; text-align: center; }
        .cancel-box { margin-top: 18px; padding: 10px 14px; border: 2px solid #B42318; color: #B42318; font-weight: 700; border-radius: 6px; }
        .stamp { position: absolute; top: 42%; left: 50%; transform: translate(-50%, -50%) rotate(-24deg); font-size: 96px; font-weight: 900; letter-spacing: .08em; color: rgba(180, 35, 24, .18); border: 8px solid rgba(180, 35, 24, .18); padding: 4px 28px; pointer-events: none; white-space: nowrap; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    </style>
</head>
<body>
<div class="page" data-testid="pro-invoice-document">
    @if ($cancelled)
        <div class="stamp" aria-hidden="true">ANNULÉE</div>
    @endif

    <header class="head">
        <div class="seller">
            <p class="seller-name">{!! $seller['name'] !== null ? e($seller['name']) : '<span class="missing">Nom du restaurant : '.e($missing).'</span>' !!}</p>
            <p>{!! $seller['address'] !== null ? e($seller['address']) : '<span class="missing">Adresse : '.e($missing).'</span>' !!}</p>
            <p>SIRET : {!! $seller['siret'] !== null ? e($seller['siret']) : '<span class="missing">'.e($missing).'</span>' !!}</p>
            <p>N° TVA intracommunautaire : {!! $seller['vat_number'] !== null ? e($seller['vat_number']) : '<span class="missing">'.e($missing).'</span>' !!}</p>
            @if ($seller['phone'] !== null || $seller['email'] !== null)
                <p>{{ implode(' · ', array_filter([$seller['phone'], $seller['email']])) }}</p>
            @endif
        </div>
        <div class="doc">
            <h1>FACTURE</h1>
            <p class="num">N° {{ $invoice->number }}</p>
            <p>Date de facture : {{ $issued->format('d/m/Y') }}</p>
            <p>Date de la prestation : {{ $invoice->service_date?->format('d/m/Y') }}</p>
        </div>
    </header>

    @if ($invoice->client_name !== '' || $invoice->client_address || $invoice->client_siret || $invoice->client_vat_number)
    <section class="client">
        <div class="tag">Facturé à</div>
        @if ($invoice->client_name !== '')
            <p class="name">{{ $invoice->client_name }}</p>
        @endif
        @if ($invoice->client_address)
            <p style="white-space: pre-line">{{ $invoice->client_address }}</p>
        @endif
        @if ($invoice->client_siret)
            <p>SIRET : {{ $invoice->client_siret }}</p>
        @endif
        @if ($invoice->client_vat_number)
            <p>N° TVA : {{ $invoice->client_vat_number }}</p>
        @endif
    </section>
    @endif

    <table>
        <thead>
        <tr>
            <th>Désignation</th>
            <th class="r">Qté</th>
            <th class="r">PU HT</th>
            <th class="r">TVA</th>
            <th class="r">Total HT</th>
            <th class="r">Total TTC</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>{{ $invoice->label }}</td>
            <td class="r">{{ $qty }}</td>
            <td class="r">{{ $unitHt }}&nbsp;€</td>
            <td class="r">{{ $pct($invoice->vat_rate) }}</td>
            <td class="r">{{ $eur($invoice->amount_ht) }}</td>
            <td class="r">{{ $eur($invoice->amount_ttc) }}</td>
        </tr>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Total HT</span><span>{{ $eur($invoice->amount_ht) }}</span></div>
        <div><span>TVA {{ $pct($invoice->vat_rate) }}</span><span>{{ $eur($invoice->vat_amount) }}</span></div>
        <div class="ttc"><span>Total TTC</span><span>{{ $eur($invoice->amount_ttc) }}</span></div>
    </div>

    <div class="paid">
        <strong>Acquittée</strong> — réglée par {{ $invoice->paymentLabel() }} le {{ $invoice->service_date?->format('d/m/Y') }}.
    </div>

    @if ($invoice->notes)
        <p class="notes">{{ $invoice->notes }}</p>
    @endif

    @if ($cancelled)
        <div class="cancel-box">
            FACTURE ANNULÉE le {{ $invoice->cancelled_at->format('d/m/Y à H:i') }}@if ($invoice->cancel_reason) — motif : {{ $invoice->cancel_reason }}@endif.
            Le numéro {{ $invoice->number }} reste attribué à ce document.
        </div>
    @endif

    <div class="mention">Facture établie hors ticket de caisse.</div>
    <p class="foot">Pas d'escompte pour règlement anticipé. · Facture {{ $invoice->number }}</p>
</div>
</body>
</html>
