<!DOCTYPE html>
{{-- [V102-08 HEAL-3 2026-05-26] EOD synthesis PDF — owner / comptable-friendly.
     Synthesis-style output (NOT line-by-line). KPI cells, payment breakdown,
     top items. Branded Le Cayenne header. Read-only — NF525 RO (DM6).      --}}
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clôture du jour — {{ $synthesis['date'] }}</title>
    <style>
        @page { margin: 18mm 14mm 16mm 14mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1F1F39; font-size: 11px; margin: 0; padding: 0; }
        .header { border-bottom: 2px solid #F4501E; padding-bottom: 8px; margin-bottom: 14px; }
        .company-name { font-size: 18px; font-weight: 700; color: #1F1F39; margin: 0; }
        .company-meta { font-size: 10px; color: #6E7191; margin: 2px 0 0 0; }
        .title-block { margin-top: 8px; }
        .title { font-size: 16px; font-weight: 700; color: #F4501E; margin: 0; }
        .subtitle { font-size: 11px; color: #6E7191; margin: 2px 0 0 0; }

        .kpi-grid { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 16px; }
        .kpi-cell { width: 33.33%; background: #F8FBFB; border: 1px solid #EFF0F6; border-radius: 6px; padding: 10px 12px; vertical-align: top; }
        .kpi-label { font-size: 10px; color: #6E7191; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 4px 0; }
        .kpi-value { font-size: 16px; font-weight: 700; color: #1F1F39; margin: 0; }
        .kpi-value.accent { color: #F4501E; }

        .section { margin-bottom: 14px; page-break-inside: avoid; }
        .section-title { font-size: 12px; font-weight: 700; color: #1F1F39; margin: 0 0 6px 0; padding-bottom: 3px; border-bottom: 1px solid #EFF0F6; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #EFF0F6; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background-color: #F8FBFB; font-weight: 600; color: #1F1F39; }
        table.data td { color: #4A4A6A; }
        table.data td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.data tr.total td { background: #FAFAFA; font-weight: 700; color: #1F1F39; }

        .empty { font-style: italic; color: #9999AA; padding: 6px 0; }

        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #9999AA; }
        .footer-line { border-top: 1px solid #EFF0F6; padding-top: 4px; }
        .legal { font-size: 9px; color: #9999AA; margin-top: 14px; font-style: italic; }
    </style>
</head>
<body>
    @php
        // Fallback discipline (matches sales_report.blade.php heal already applied):
        // company_name may be null on a fresh install — fail soft to "Le Cayenne".
        $companyName    = App\Libraries\AppLibrary::textShortener($company['company_name']    ?? 'Le Cayenne', 80);
        $companyAddress = App\Libraries\AppLibrary::textShortener($company['company_address'] ?? '', 100);
        $companyPhone   = $company['company_phone'] ?? '';

        $fmt = fn($n) => number_format((float) $n, 2, ',', ' ') . ' €';
    @endphp

    <div class="header">
        <p class="company-name">{{ $companyName }}</p>
        @if($companyAddress || $companyPhone)
            <p class="company-meta">{{ $companyAddress }}@if($companyAddress && $companyPhone) — @endif{{ $companyPhone }}</p>
        @endif
        <div class="title-block">
            <p class="title">Clôture du jour — Synthèse</p>
            <p class="subtitle">Date : {{ \Carbon\Carbon::parse($synthesis['date'])->locale('fr')->isoFormat('dddd D MMMM YYYY') }} &nbsp;·&nbsp; {{ $synthesis['branch_label'] }} &nbsp;·&nbsp; Édité le {{ now(config('app.timezone'))->format('d/m/Y H:i') }}</p>
        </div>
    </div>

    {{-- 6 KPI cells : CA · TVA · Commandes · Payées · Remboursées · Ticket moyen --}}
    <table class="kpi-grid">
        <tr>
            <td class="kpi-cell">
                <p class="kpi-label">Chiffre d'affaires (TTC)</p>
                <p class="kpi-value accent">{{ $fmt($synthesis['total_ca']) }}</p>
            </td>
            <td class="kpi-cell">
                <p class="kpi-label">TVA collectée</p>
                <p class="kpi-value">{{ $fmt($synthesis['total_tva']) }}</p>
            </td>
            <td class="kpi-cell">
                <p class="kpi-label">Ticket moyen</p>
                <p class="kpi-value">{{ $fmt($synthesis['avg_ticket']) }}</p>
            </td>
        </tr>
        <tr>
            <td class="kpi-cell">
                <p class="kpi-label">Commandes totales</p>
                <p class="kpi-value">{{ $synthesis['total_orders'] }}</p>
            </td>
            <td class="kpi-cell">
                <p class="kpi-label">Commandes payées</p>
                <p class="kpi-value">{{ $synthesis['paid_orders'] }}</p>
            </td>
            <td class="kpi-cell">
                <p class="kpi-label">Commandes remboursées</p>
                <p class="kpi-value">{{ $synthesis['refunded_orders'] }}</p>
            </td>
        </tr>
    </table>

    {{-- Payment-method breakdown --}}
    <div class="section">
        <p class="section-title">Encaissements par mode de paiement</p>
        @if(count($synthesis['by_payment']) === 0)
            <p class="empty">Aucun encaissement enregistré ce jour.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Mode de paiement</th>
                        <th class="num">Nombre</th>
                        <th class="num">Montant TTC</th>
                    </tr>
                </thead>
                <tbody>
                    @php $payTotalAmt = 0; $payTotalCnt = 0; @endphp
                    @foreach($synthesis['by_payment'] as $row)
                        @php $payTotalAmt += $row['total']; $payTotalCnt += $row['count']; @endphp
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $row['count'] }}</td>
                            <td class="num">{{ $fmt($row['total']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td>Total</td>
                        <td class="num">{{ $payTotalCnt }}</td>
                        <td class="num">{{ $fmt($payTotalAmt) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif
    </div>

    {{-- Channel breakdown --}}
    <div class="section">
        <p class="section-title">Encaissements par canal</p>
        @if(count($synthesis['by_channel']) === 0)
            <p class="empty">Aucun encaissement enregistré ce jour.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Canal</th>
                        <th class="num">Nombre</th>
                        <th class="num">Montant TTC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($synthesis['by_channel'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $row['count'] }}</td>
                            <td class="num">{{ $fmt($row['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Top items --}}
    <div class="section">
        <p class="section-title">Top 5 produits vendus (quantité)</p>
        @if(count($synthesis['top_items']) === 0)
            <p class="empty">Aucun produit vendu ce jour.</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th class="num">Quantité</th>
                        <th class="num">CA généré</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($synthesis['top_items'] as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="num">{{ $row['qty'] }}</td>
                            <td class="num">{{ $fmt($row['revenue']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <p class="legal">
        Document interne — Synthèse non-fiscale. Pour la clôture fiscale officielle, se référer au Rapport Z journalier
        signé HMAC (NF525, art. 286-I-3 bis du CGI). Conservation 6 ans.
    </p>

    <div class="footer">
        <div class="footer-line">
            {{ $copyright }} &nbsp;·&nbsp; Clôture du jour {{ $synthesis['date'] }}
        </div>
    </div>
</body>
</html>
