<!DOCTYPE html>
{{-- [AUDIT-COMPTA 2026-08-29] Rapport Z — vraie pièce PDF.

     Le bouton « PDF » de l'écran des rapports Z livrait un fichier `rapport-z-27.pdf` de
     793 octets commençant par `{"data":{"z_repo` : du JSON sous une extension `.pdf`.
     Ce n'était pas une régression — `ZReportController::pdf()` était déclarée
     `: JsonResponse` et n'avait jamais rendu autre chose. Le nom de la méthode, la route
     `/pdf`, le bouton et le nom du fichier téléchargé promettaient tous un document que
     personne n'avait écrit.

     Un rapport Z est la pièce que le commerçant remet à son comptable et conserve six ans
     (NF525). Il doit être lisible sans l'application.

     Lecture seule stricte : aucune allocation de séquence, aucune écriture dans la chaîne
     d'audit, aucun `UPDATE`. Même discipline que `eod_synthesis.blade.php`, dont ce
     gabarit reprend la feuille de style. --}}
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Rapport Z n°{{ $zReport->sequence_no }}</title>
    <style>
        @page { margin: 18mm 14mm 16mm 14mm; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #1F1F39; font-size: 11px; margin: 0; padding: 0; }
        .header { border-bottom: 2px solid #ff006b; padding-bottom: 8px; margin-bottom: 14px; }
        .company-name { font-size: 18px; font-weight: 700; color: #1F1F39; margin: 0; }
        .company-meta { font-size: 10px; color: #6E7191; margin: 2px 0 0 0; }
        .title-block { margin-top: 8px; }
        .title { font-size: 16px; font-weight: 700; color: #ff006b; margin: 0; }
        .subtitle { font-size: 11px; color: #6E7191; margin: 2px 0 0 0; }

        .kpi-grid { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 16px; }
        .kpi-cell { width: 33.33%; background: #F8FBFB; border: 1px solid #EFF0F6; border-radius: 6px; padding: 10px 12px; vertical-align: top; }
        .kpi-label { font-size: 10px; color: #6E7191; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 4px 0; }
        .kpi-value { font-size: 16px; font-weight: 700; color: #1F1F39; margin: 0; }
        .kpi-value.accent { color: #ff006b; }

        .section { margin-bottom: 14px; page-break-inside: avoid; }
        .section-title { font-size: 12px; font-weight: 700; color: #1F1F39; margin: 0 0 6px 0; padding-bottom: 3px; border-bottom: 1px solid #EFF0F6; }

        table.data { width: 100%; border-collapse: collapse; }
        table.data th, table.data td { border: 1px solid #EFF0F6; padding: 6px 8px; text-align: left; font-size: 10px; }
        table.data th { background-color: #F8FBFB; font-weight: 600; color: #1F1F39; }
        table.data td { color: #4A4A6A; }
        table.data td.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.data tr.total td { background: #FAFAFA; font-weight: 700; color: #1F1F39; }

        .empty { font-style: italic; color: #9999AA; padding: 6px 0; }
        .sceau { background: #F8FBFB; border: 1px solid #EFF0F6; border-radius: 6px; padding: 8px 10px; }
        .sceau .ligne { margin: 0 0 3px 0; font-size: 9px; color: #6E7191; }
        .sceau .empreinte { font-family: DejaVu Sans Mono, monospace; font-size: 8px; color: #4A4A6A; word-break: break-all; }
        .ok { color: #1F9254; font-weight: 700; }
        .ko { color: #C4262E; font-weight: 700; }
        .legal { font-size: 9px; color: #9999AA; margin-top: 14px; font-style: italic; }
        .footer { position: fixed; bottom: -8mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #9999AA; }
        .footer-line { border-top: 1px solid #EFF0F6; padding-top: 4px; }
    </style>
</head>
<body>

@php
    /** Un montant, toujours en français : « 1 234,56 € ». */
    $eur = fn ($v) => number_format((float) $v, 2, ',', ' ') . ' €';

    /** Les répartitions sont stockées en JSON ; selon les casts elles arrivent en tableau
        ou en chaîne. On accepte les deux plutôt que de dépendre d'un cast. */
    $tableau = function ($v) {
        if (is_array($v)) return $v;
        if (is_string($v) && $v !== '') { $d = json_decode($v, true); return is_array($d) ? $d : []; }
        return [];
    };

    $parMode = $tableau($zReport->total_by_method);
    $parTaux = $tableau($zReport->total_by_tax_rate);

    $libelleMode = [
        'cash' => 'Espèces', 'card' => 'Carte bancaire', 'terminal' => 'TPE',
        'check' => 'Chèque', 'voucher' => 'Titre restaurant', 'online' => 'En ligne',
        'unknown' => 'Non renseigné',
    ];
@endphp

<div class="header">
    <p class="company-name">{{ $company['company_name'] ?? 'Le Cayenne' }}</p>
    <p class="company-meta">
        {{ $company['company_address'] ?? '' }}@if(!empty($company['company_phone'])) — {{ $company['company_phone'] }}@endif
    </p>
    <div class="title-block">
        <p class="title">Rapport Z n°{{ $zReport->sequence_no }}</p>
        <p class="subtitle">
            Clôture fiscale — période du
            {{ optional($zReport->opened_at)->format('d/m/Y H:i:s') ?? '—' }}
            au
            {{ optional($zReport->closed_at)->format('d/m/Y H:i:s') ?? '—' }}
        </p>
    </div>
</div>

<table class="kpi-grid">
    <tr>
        <td class="kpi-cell">
            <p class="kpi-label">Total TTC</p>
            <p class="kpi-value accent">{{ $eur($zReport->total_ttc) }}</p>
        </td>
        <td class="kpi-cell">
            <p class="kpi-label">Total HT</p>
            <p class="kpi-value">{{ $eur($zReport->total_ht) }}</p>
        </td>
        <td class="kpi-cell">
            <p class="kpi-label">TVA collectée</p>
            <p class="kpi-value">{{ $eur($zReport->total_tva) }}</p>
        </td>
    </tr>
    <tr>
        <td class="kpi-cell">
            <p class="kpi-label">Commandes</p>
            <p class="kpi-value">{{ (int) $zReport->order_count }}</p>
        </td>
        <td class="kpi-cell">
            <p class="kpi-label">Annulations</p>
            <p class="kpi-value">{{ (int) $zReport->cancel_count }}</p>
        </td>
        <td class="kpi-cell">
            <p class="kpi-label">Remboursements</p>
            <p class="kpi-value">{{ (int) $zReport->refund_count }}</p>
        </td>
    </tr>
</table>

<div class="section">
    <p class="section-title">Encaissements par mode de règlement</p>
    @if (count($parMode) > 0)
        <table class="data">
            <thead><tr><th>Mode</th><th class="num">Montant</th></tr></thead>
            <tbody>
            @foreach ($parMode as $mode => $montant)
                <tr>
                    <td>{{ $libelleMode[$mode] ?? ucfirst((string) $mode) }}</td>
                    <td class="num">{{ $eur(is_array($montant) ? ($montant['total'] ?? 0) : $montant) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">Aucun encaissement sur la période.</p>
    @endif
</div>

<div class="section">
    <p class="section-title">Ventilation de la TVA</p>
    @if (count($parTaux) > 0)
        <table class="data">
            <thead><tr><th>Taux</th><th class="num">Base HT</th><th class="num">TVA</th></tr></thead>
            <tbody>
            @foreach ($parTaux as $taux => $valeurs)
                <tr>
                    <td>{{ is_numeric($taux) ? number_format((float) $taux, 2, ',', ' ') . ' %' : $taux }}</td>
                    <td class="num">{{ $eur(is_array($valeurs) ? ($valeurs['ht'] ?? $valeurs['base'] ?? 0) : 0) }}</td>
                    <td class="num">{{ $eur(is_array($valeurs) ? ($valeurs['tva'] ?? $valeurs['tax'] ?? 0) : $valeurs) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="empty">Aucune ventilation de TVA enregistrée.</p>
    @endif
</div>

<div class="section">
    <p class="section-title">Caisse</p>
    <table class="data">
        <tbody>
        <tr><td>Fonds d'ouverture</td><td class="num">{{ $eur($zReport->cash_opening_amount) }}</td></tr>
        <tr><td>Fonds de clôture</td><td class="num">{{ $eur($zReport->cash_closing_amount) }}</td></tr>
        <tr class="total"><td>Écart de caisse</td><td class="num">{{ $eur($zReport->cash_variance) }}</td></tr>
        <tr><td>Mouvements d'espèces</td><td class="num">{{ (int) $zReport->cash_movements_count }}</td></tr>
        </tbody>
    </table>
    {{-- Dit au comptable ce que la signature couvre — et ce qu'elle ne couvre pas.
         Les quatre colonnes ci-dessus sont renseignées APRÈS la clôture et ne sont pas
         reprises dans l'empreinte (`app/Models/ZReport.php` : « NOT signed by HMAC »).
         Le taire reviendrait à laisser croire à une garantie qui n'existe pas. --}}
    <p class="legal">
        Les montants de caisse ci-dessus sont enregistrés après la clôture et ne sont pas
        couverts par l'empreinte de scellement figurant ci-dessous.
    </p>
</div>

<div class="section">
    <p class="section-title">Scellement fiscal</p>
    <div class="sceau">
        <p class="ligne">
            Vérification de la signature à l'instant de l'édition :
            @if ($verified)
                <span class="ok">CONFORME</span>
            @else
                <span class="ko">NON CONFORME — signaler immédiatement</span>
            @endif
        </p>
        <p class="ligne">Empreinte du rapport :</p>
        <p class="empreinte">{{ $zReport->signature ?? '—' }}</p>
        <p class="ligne">Empreinte du rapport précédent (chaînage) :</p>
        <p class="empreinte">{{ $zReport->prev_hash ?? '—' }}</p>
    </div>
</div>

<p class="legal">
    Document établi conformément à la norme NF525 (loi de finances 2016 relative aux
    logiciels de caisse). Édité le {{ $generated_at }}. À conserver six ans.
    Cette édition est une lecture : elle ne modifie ni la clôture, ni la chaîne d'audit.
</p>

<div class="footer">
    <div class="footer-line">{{ $copyright }}</div>
</div>

</body>
</html>
