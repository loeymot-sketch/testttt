<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Services\Hygiene\ReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Dossier de contrôle sanitaire : résumé à l'écran, PDF à remettre à l'inspection, exports CSV par
 * domaine. Une seule source (ReportBuilder) pour les trois. LECTURE SEULE : rien n'est écrit. Période : 366 jours au plus.
 *
 * Le PDF porte une EMPREINTE (SHA-256 du jeu de données) : un contrôleur peut s'assurer que le document n'a pas été retouché après coup.
 */
class ReportController extends HygieneController
{
    public const MODULES = ['temperature', 'tasks', 'receptions', 'trace', 'oil', 'dlc'];

    public function summary(Request $request): JsonResponse
    {
        // sans période : le MOIS EN COURS d'après l'horloge du SERVEUR (une tablette mal réglée ne décale jamais la période du dossier)
        if (! $request->has('from') && ! $request->has('to')) {
            $request->merge(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]);
        }
        [$from, $to] = $this->validated($request);
        $data = ReportBuilder::between($from, $to)->build(false);
        // l'écran ne reçoit que le résumé : les journaux complets sont dans le PDF et les CSV
        foreach (self::MODULES as $m) unset($data[$m]['rows']);

        return response()->json(['data' => $data, 'meta' => $this->clock()]);
    }

    public function pdf(Request $request)
    {
        [$from, $to] = $this->validated($request);
        // un dossier d'inspection doit sortir sur un serveur ordinaire : lignes plafonnées (les plus récentes, avec le nombre manquant), taux et incidents complets
        @ini_set('memory_limit', '768M');
        @set_time_limit(120);
        $report = ReportBuilder::between($from, $to)->capRows(ReportBuilder::PDF_ROW_CAP)->build(true);
        $company = app(\App\Services\CompanyService::class)->list();
        $generatedAt = now()->timezone(config('app.timezone'))->format('d/m/Y à H:i');
        $generatedBy = $request->user()?->name;
        $fingerprint = hash('sha256', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.hygiene_report', compact('report', 'company', 'generatedAt', 'generatedBy', 'fingerprint'))->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), "dossier-hygiene-{$from}-{$to}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->validated($request);
        $module = $request->validate(['module' => ['required', 'in:'.implode(',', self::MODULES)]])['module'];
        $report = ReportBuilder::between($from, $to)->build(true);

        [$headers, $rows] = $this->table($module, $report[$module]['rows']);

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit l'UTF-8 (accents)
            fputcsv($out, $headers, ';');
            foreach ($rows as $r) fputcsv($out, array_map([$this, 'cell'], $r), ';');
            fclose($out);
        }, "hygiene-{$module}-{$from}-{$to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0:string,1:string} */
    private function validated(Request $request): array
    {
        $v = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']], [], ['from' => 'début', 'to' => 'fin']);
        $days = \Carbon\Carbon::parse($v['from'])->diffInDays(\Carbon\Carbon::parse($v['to']));
        if ($days > 365) {
            abort(response()->json(['message' => 'La période ne peut pas dépasser 366 jours.', 'errors' => ['to' => ['Période trop longue (366 jours au plus).']]], 422));
        }

        return [$v['from'], $v['to']];
    }

    /** Une cellule CSV : jamais une formule (=, +, -, @ en tête → préfixe d'apostrophe : neutralise l'injection de formule Excel). */
    private function cell($v): string
    {
        if (is_bool($v)) return $v ? 'oui' : 'non';
        $s = is_array($v) ? implode(' | ', $v) : (string) $v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric(str_replace(',', '.', $s))) {
            return "'".$s;
        }

        return $s;
    }

    private function dt(?string $iso): string
    {
        return $iso ? \Carbon\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i') : '';
    }

    private function num($v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) $v);
    }

    /** @return array{0:list<string>,1:list<list<mixed>>} */
    private function table(string $module, array $rows): array
    {
        $status = fn ($s) => ['ok' => 'conforme', 'alert' => 'écart', 'warn' => 'à surveiller'][$s] ?? (string) $s;
        $void = fn ($r) => ! empty($r['voided']) ? 'ANNULÉ : '.($r['void_reason'] ?? '') : '';

        switch ($module) {
            case 'temperature':
                return [['Date', 'Équipement', 'Température °C', 'Seuil bas', 'Seuil haut', 'Verdict', 'Moment', 'Opérateur', 'Saisie', 'Action corrective', 'Commentaire', 'Suivi après écart clos', 'Annulation'],
                    array_map(fn ($r) => [$this->dt($r['at']), $r['equipment'], $this->num($r['value_c']), $this->num($r['min_c']), $this->num($r['max_c']), $status($r['status']), config("hygiene.periods.{$r['period']}") ?? $r['period'], $r['operator'],
                        ! empty($r['offline']) ? 'hors ligne, envoyé le '.$this->dt($r['synced_at']) : 'direct', $r['action'], $r['note'], $r['followup_closed'] ?? '', $void($r)], $rows)];
            case 'tasks':
                return [['Date', 'Jour concerné', 'Fiche', 'Zone', 'Points faits', 'Points total', 'Complète', 'Après l\'heure', 'Points non faits', 'Commentaire', 'Opérateur', 'Annulation'],
                    array_map(fn ($r) => [$this->dt($r['at']), $r['due_date'], $r['task'], $r['zone'], $r['items_done'], $r['items_total'], $r['complete'], $r['late'], $r['not_done'], $r['note'], $r['operator'], $void($r)], $rows)];
            case 'receptions':
                return [['Date', 'Fournisseur', 'N° BL', 'Livreur', 'Températures', 'Contrôles non conformes', 'Verdict', 'Décision', 'Commentaire', 'Opérateur', 'Annulation'],
                    array_map(fn ($r) => [$this->dt($r['at']), $r['supplier'], $r['delivery_note'], $r['driver'], implode(' | ', array_map(fn ($t) => $t['label'].' '.$this->num($t['value_c']).' °C', $r['temperatures'])), $r['checks_ko'], $status($r['status']), $r['decision'], $r['note'], $r['operator'], $void($r)], $rows)];
            case 'trace':
                return [['Date', 'Produit', 'Fournisseur', 'N° BL', 'N° de lot', 'DLC/DLUO', 'Type', 'Verdict', 'Commentaire', 'Opérateur', 'Annulation'],
                    array_map(fn ($r) => [$this->dt($r['at']), $r['product'], $r['supplier'], $r['delivery_note'], $r['lot'], $r['dlc_date'], strtoupper((string) $r['dlc_kind']), $status($r['status']), $r['note'], $r['operator'], $void($r)], $rows)];
            case 'oil':
                return [['Date', 'Friteuse', 'Opération', 'Composés polaires %', 'Bain °C', 'Litres', 'Huile', 'Verdict', 'Action corrective', 'Commentaire', 'Opérateur', 'Annulation'],
                    array_map(fn ($r) => [$this->dt($r['at']), $r['fryer'], $r['type'], $this->num($r['polar_pct']), $this->num($r['bath_temp_c']), $this->num($r['oil_litres']), $r['oil_brand'], $status($r['status']), $r['action'], $r['note'], $r['operator'], $void($r)], $rows)];
            default: // dlc
                $labels = \App\Models\DlcLabelProduct::ACTION_LABELS;

                return [['Date', 'Produit', 'Événement', 'N° de lot', 'Lot fournisseur', 'DLC', 'Étiquettes', 'Conservation', 'Sortie', 'Date de sortie'],
                    array_map(fn ($r) => [$this->dt($r['at']), $r['product'], $labels[$r['action']] ?? $r['action'], $r['lot'], $r['supplier_lot'], $this->dt($r['expires_at']), $r['copies'], $r['storage'],
                        $r['out'] ? ['discarded' => 'jeté', 'used' => 'utilisé'][$r['out']['reason']] ?? $r['out']['reason'] : '', $r['out'] ? $this->dt($r['out']['at']) : ''], $rows)];
        }
    }
}
