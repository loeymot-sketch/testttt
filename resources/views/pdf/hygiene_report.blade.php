<!DOCTYPE html>
{{-- [CAYENNE HYGIÈNE 2026-10-17] Dossier de contrôle sanitaire : relevés de température, nettoyage, réceptions, traçabilité, huile et
     étiquettes DLC d'une période, avec les taux de couverture et les incidents (action corrective). Lecture seule.
     Empreinte SHA-256 du jeu de données en pied : un contrôleur peut vérifier que le document n'a pas été retouché. --}}
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Dossier de contrôle sanitaire</title>
    <style>
        @page { margin: 16mm 12mm 16mm 12mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1F1F39; font-size: 10px; margin: 0; }
        .header { border-bottom: 2px solid #111827; padding-bottom: 8px; margin-bottom: 12px; }
        .company { font-size: 17px; font-weight: 700; margin: 0; }
        .title { font-size: 15px; font-weight: 700; margin: 6px 0 0; }
        .sub { color: #6E7191; margin: 2px 0 0; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #cbd5e1; page-break-after: avoid; }
        table.kpi { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 0 -5px 8px; }
        table.kpi td { border: 1px solid #e2e8f0; background: #f8fafc; padding: 7px 9px; width: 25%; vertical-align: top; }
        .kpi-l { font-size: 8px; text-transform: uppercase; color: #6E7191; margin: 0 0 3px; }
        .kpi-v { font-size: 15px; font-weight: 700; margin: 0; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #e2e8f0; padding: 4px 5px; text-align: left; font-size: 8.5px; vertical-align: top; }
        table.data th { background: #f1f5f9; font-weight: 700; }
        tr.bad td { background: #fef2f2; }
        tr.void td { color: #94a3b8; text-decoration: line-through; }
        .ok { color: #166534; font-weight: 700; } .ko { color: #b91c1c; font-weight: 700; } .warn { color: #b45309; font-weight: 700; }
        .empty { font-style: italic; color: #94a3b8; }
        .legal { font-size: 8px; color: #94a3b8; margin-top: 12px; }
        .footer { position: fixed; bottom: -9mm; left: 0; right: 0; text-align: center; font-size: 8px; color: #94a3b8; }
    </style>
</head>
<body>
@php
    $dt = fn ($iso) => $iso ? \Carbon\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '';
    $d = fn ($iso) => $iso ? \Carbon\Carbon::parse($iso)->format('d/m/Y') : '';
    $n = fn ($v) => $v === null ? '—' : str_replace('.', ',', (string) $v);
    $pct = fn ($v) => $v === null ? '—' : str_replace('.', ',', (string) $v).' %';
    $st = fn ($s) => $s === 'alert' ? '<span class="ko">écart</span>' : ($s === 'warn' ? '<span class="warn">à surveiller</span>' : '<span class="ok">conforme</span>');
    $mod = ['temperature' => 'Températures', 'tasks' => 'Nettoyage', 'receptions' => 'Réception', 'trace' => 'Traçabilité', 'oil' => 'Huile'];
    $p = $report['period'];
    $cut = fn ($m) => ($m['rows_total'] ?? 0) > count($m['rows'] ?? []) ? '<p class="warn">Liste réduite : les '.count($m['rows']).' lignes les plus récentes sur '.$m['rows_total'].'. Le détail complet est dans l\'export CSV.</p>' : '';
    $t = $report['temperature']; $k = $report['tasks']; $r = $report['receptions']; $z = $report['trace']; $o = $report['oil']; $l = $report['dlc'];
@endphp

<div class="header">
    <p class="company">{{ $company['company_name'] ?? 'Le Cayenne' }}</p>
    @if (!empty($company['company_address']))<p class="sub">{{ $company['company_address'] }}</p>@endif
    <p class="title">Dossier de contrôle sanitaire — du {{ $d($p['from']) }} au {{ $d($p['to']) }}</p>
    <p class="sub">{{ $p['days'] }} jour{{ $p['days'] > 1 ? 's' : '' }} · édité le {{ $generatedAt }}@if($generatedBy) par {{ $generatedBy }}@endif</p>
</div>

<div class="footer">Dossier d'hygiène — empreinte {{ substr($fingerprint, 0, 32) }}…</div>

<h2>Synthèse</h2>
<table class="kpi"><tr>
    <td><p class="kpi-l">Relevés de température faits</p><p class="kpi-v">{{ $pct($t['coverage_pct']) }}</p><p class="sub">{{ $t['covered'] }} sur {{ $t['expected'] }} attendus · {{ $t['alerts'] }} écart(s)</p></td>
    <td><p class="kpi-l">Fiches de nettoyage faites</p><p class="kpi-v">{{ $pct($k['completion_pct']) }}</p><p class="sub">{{ $k['done'] }} sur {{ $k['expected'] }} · {{ $k['partial'] }} incomplète(s) · {{ $k['missed'] }} non faite(s)</p></td>
    <td><p class="kpi-l">Réceptions</p><p class="kpi-v">{{ $r['count'] }}</p><p class="sub">{{ $r['alerts'] }} écart(s) · {{ $r['refused'] }} refusée(s)</p></td>
    <td><p class="kpi-l">Huile : tests faits</p><p class="kpi-v">{{ $pct($o['coverage_pct']) }}</p><p class="sub">{{ $o['tests'] }} test(s) · {{ $o['max_polar'] === null ? 'aucune mesure de polaires' : 'max '.$pct($o['max_polar']) }} · {{ $o['alerts'] }} écart(s)</p></td>
</tr><tr>
    <td><p class="kpi-l">Produits tracés</p><p class="kpi-v">{{ $z['count'] }}</p><p class="sub">{{ $z['without_lot'] }} sans lot (expliqué) · {{ $z['alerts'] }} écart(s)</p></td>
    <td><p class="kpi-l">Étiquettes DLC</p><p class="kpi-v">{{ $l['count'] }}</p><p class="sub">{{ $l['copies'] }} étiquette(s) · {{ $l['discarded'] }} jetée(s) · {{ $l['used'] }} utilisée(s)</p></td>
    <td><p class="kpi-l">DLC échues non sorties</p><p class="kpi-v {{ $l['expired_not_out'] ? 'ko' : '' }}">{{ $l['expired_not_out'] }}</p></td>
    <td><p class="kpi-l">Incidents tracés</p><p class="kpi-v">{{ count($report['incidents']) }}</p></td>
</tr></table>

<h2>Incidents et actions correctives</h2>
@if (empty($report['incidents']))
    <p class="empty">Aucun incident sur la période.</p>
@else
    <table class="data"><tr><th style="width:15%">Date</th><th style="width:11%">Domaine</th><th>Incident</th><th>Action / commentaire</th><th style="width:12%">Opérateur</th></tr>
    @foreach ($report['incidents'] as $i)
        <tr class="bad"><td>{{ $dt($i['at']) }}</td><td>{{ $mod[$i['module']] ?? $i['module'] }}</td><td>{{ $i['text'] }}</td><td>{{ $i['action'] }}</td><td>{{ $i['operator'] }}</td></tr>
    @endforeach
    </table>
@endif

<h2>Relevés de température ({{ $t['count'] }})</h2>
@if (empty($t['rows']))<p class="empty">Aucun relevé.</p>@else
    {!! $cut($t) !!}
    <table class="data"><tr><th>Date</th><th>Équipement</th><th>°C</th><th>Seuils</th><th>Verdict</th><th>Moment</th><th>Opérateur</th><th>Action corrective</th></tr>
    @foreach ($t['rows'] as $x)
        <tr class="{{ $x['voided'] ? 'void' : ($x['status'] === 'alert' ? 'bad' : '') }}"><td>{{ $dt($x['at']) }}</td><td>{{ $x['equipment'] }}</td><td>{{ $n($x['value_c']) }}</td>
            <td>{{ $x['min_c'] !== null ? $n($x['min_c']).' à ' : '≤ ' }}{{ $n($x['max_c']) }}</td><td>{!! $st($x['status']) !!}@if($x['voided']) — annulé : {{ $x['void_reason'] }}@endif</td>
            <td>{{ config("hygiene.periods.{$x['period']}") }}</td><td>{{ $x['operator'] }}</td><td>{{ $x['action'] }}@if($x['note']) — {{ $x['note'] }}@endif</td></tr>
    @endforeach
    </table>
@endif

<h2>Plan de nettoyage ({{ $k['count'] }} validation{{ $k['count'] > 1 ? 's' : '' }})</h2>
@if (!empty($k['missed_list']))
    <p class="ko">Fiches non faites ({{ $k['missed'] }}) : @foreach ($k['missed_list'] as $m){{ $d($m['date']) }} {{ $m['task'] }}{{ $loop->last ? '' : ' ; ' }}@endforeach</p>
@endif
@if (empty($k['rows']))<p class="empty">Aucune validation.</p>@else
    {!! $cut($k) !!}
    <table class="data"><tr><th>Date</th><th>Jour</th><th>Fiche</th><th>Points</th><th>Non faits / commentaire</th><th>Opérateur</th></tr>
    @foreach ($k['rows'] as $x)
        <tr class="{{ $x['voided'] ? 'void' : (!$x['complete'] ? 'bad' : '') }}"><td>{{ $dt($x['at']) }}</td><td>{{ $d($x['due_date']) }}</td><td>{{ $x['task'] }}</td>
            <td>{{ $x['items_done'] }}/{{ $x['items_total'] }}@if($x['late']) · après l'heure @endif</td>
            <td>{{ implode(', ', $x['not_done']) }}@if($x['note']) — {{ $x['note'] }}@endif @if($x['voided']) annulé : {{ $x['void_reason'] }}@endif</td><td>{{ $x['operator'] }}</td></tr>
    @endforeach
    </table>
@endif

<h2>Réceptions de marchandises ({{ $r['count'] }})</h2>
@if (empty($r['rows']))<p class="empty">Aucune réception.</p>@else
    {!! $cut($r) !!}
    <table class="data"><tr><th>Date</th><th>Fournisseur</th><th>BL</th><th>Températures</th><th>Contrôles non conformes</th><th>Décision</th><th>Commentaire</th><th>Opérateur</th></tr>
    @foreach ($r['rows'] as $x)
        <tr class="{{ $x['voided'] ? 'void' : ($x['status'] === 'alert' ? 'bad' : '') }}"><td>{{ $dt($x['at']) }}</td><td>{{ $x['supplier'] }}</td><td>{{ $x['delivery_note'] }}</td>
            <td>@foreach ($x['temperatures'] as $tp){{ $tp['label'] }} {{ $n($tp['value_c']) }} °C{{ $loop->last ? '' : ' · ' }}@endforeach</td><td>{{ implode(', ', $x['checks_ko']) }}</td>
            <td>{{ $x['decision'] }}</td><td>{{ $x['note'] }} @if($x['voided']) annulé : {{ $x['void_reason'] }}@endif</td><td>{{ $x['operator'] }}</td></tr>
    @endforeach
    </table>
@endif

<h2>Traçabilité amont ({{ $z['count'] }})</h2>
@if (empty($z['rows']))<p class="empty">Aucun produit tracé.</p>@else
    {!! $cut($z) !!}
    <table class="data"><tr><th>Date</th><th>Produit</th><th>Fournisseur</th><th>BL</th><th>Lot</th><th>DLC / DLUO</th><th>Commentaire</th><th>Opérateur</th></tr>
    @foreach ($z['rows'] as $x)
        <tr class="{{ $x['voided'] ? 'void' : ($x['status'] === 'alert' ? 'bad' : '') }}"><td>{{ $dt($x['at']) }}</td><td>{{ $x['product'] }}</td><td>{{ $x['supplier'] }}</td><td>{{ $x['delivery_note'] }}</td>
            <td>{{ $x['lot'] }}</td><td>{{ $d($x['dlc_date']) }} {{ strtoupper($x['dlc_kind']) }}</td><td>{{ $x['note'] }} @if($x['voided']) annulé : {{ $x['void_reason'] }}@endif</td><td>{{ $x['operator'] }}</td></tr>
    @endforeach
    </table>
@endif

<h2>Huile de friture ({{ $o['tests'] }} test(s), {{ $o['changes'] }} vidange(s), {{ $o['filters'] }} filtrage(s))</h2>
@if (empty($o['rows']))<p class="empty">Aucune opération.</p>@else
    {!! $cut($o) !!}
    <table class="data"><tr><th>Date</th><th>Friteuse</th><th>Opération</th><th>Polaires</th><th>Bain °C</th><th>Verdict</th><th>Action corrective</th><th>Opérateur</th></tr>
    @foreach ($o['rows'] as $x)
        <tr class="{{ $x['voided'] ? 'void' : ($x['status'] === 'alert' ? 'bad' : '') }}"><td>{{ $dt($x['at']) }}</td><td>{{ $x['fryer'] }}</td><td>{{ $x['type'] }}@if($x['oil_litres']) · {{ $n($x['oil_litres']) }} L @endif</td>
            <td>{{ $x['polar_pct'] !== null ? $pct($x['polar_pct']) : '' }}</td><td>{{ $n($x['bath_temp_c']) }}</td><td>{!! $x['type'] === 'Test de l’huile' ? $st($x['status']) : '' !!}</td>
            <td>{{ $x['action'] }} {{ $x['note'] }} @if($x['voided']) annulé : {{ $x['void_reason'] }}@endif</td><td>{{ $x['operator'] }}</td></tr>
    @endforeach
    </table>
@endif

<h2>Étiquettes DLC imprimées ({{ $l['count'] }})</h2>
@if (empty($l['rows']))<p class="empty">Aucune étiquette.</p>@else
    {!! $cut($l) !!}
    <table class="data"><tr><th>Date</th><th>Produit</th><th>N° de lot</th><th>DLC</th><th>Étiq.</th><th>Conservation</th><th>Sortie</th></tr>
    @foreach ($l['rows'] as $x)
        <tr><td>{{ $dt($x['at']) }}</td><td>{{ $x['product'] }}</td><td>{{ $x['lot'] }}@if($x['supplier_lot']) (fourn. {{ $x['supplier_lot'] }})@endif</td><td>{{ $dt($x['expires_at']) }}</td><td>{{ $x['copies'] }}</td><td>{{ $x['storage'] }}</td>
            <td>@if($x['out']){{ ['discarded' => 'Jeté', 'used' => 'Utilisé'][$x['out']['reason']] ?? $x['out']['reason'] }} le {{ $dt($x['out']['at']) }}@endif</td></tr>
    @endforeach
    </table>
@endif

<p class="legal">Les durées de conservation, fréquences de nettoyage et seuils sont ceux enregistrés dans l'application : des valeurs de départ prudentes que l'établissement ajuste et valide dans son plan de maîtrise sanitaire. Les lignes annulées sont conservées et barrées. Empreinte SHA-256 du jeu de données : {{ $fingerprint }}</p>

<script type="text/php">
    if (isset($pdf)) { $pdf->page_script('$pdf->text(500, 826, "page ".$PAGE_NUM."/".$PAGE_COUNT, $fontMetrics->get_font("DejaVu Sans"), 8, array(0.58,0.64,0.72));'); }
</script>
</body>
</html>
