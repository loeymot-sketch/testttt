<?php

namespace App\Services\ProInvoice;

use App\Models\ProInvoice;
use App\Services\Hardware\EscPosCommandBuilder as E;

/**
 * [FACTURE PRO 2026-11-16 · propriétaire] Rendu ESC/POS d'une facture pro, pour l'IMPRIMANTE DE CAISSE.
 *
 * POURQUOI. La facture n'existait qu'en document A4 (HTML) envoyé à l'impression du navigateur. Sur
 * l'imprimante thermique de la caisse, le pilote la comprime sur 80 mm : « un bout de phrase tout collé,
 * pas un vrai ticket » (propriétaire). Ici, le ticket est composé comme tous les autres tickets de la
 * caisse — colonnes alignées, filets, totaux à droite, coupe — et part par le même pont d'impression
 * (`printEscPosViaCaisseBridge`).
 *
 * Même source que le document A4 : l'identité du vendeur vient de ProInvoiceService::sellerIdentity()
 * (jamais inventée : un champ absent n'est pas imprimé), les montants viennent de la ligne figée en base.
 * Le client est FACULTATIF : sans société ni adresse ni SIRET ni TVA, le bloc « Facturé à » n'existe pas.
 *
 * Jeu de caractères : CP858 (page 19) — accents et € passent ; pas de caractère de filet exotique.
 */
class ProInvoiceEscPosRenderer
{
    /**
     * @param  array{name: ?string, address: ?string, siret: ?string, vat_number: ?string, phone: ?string, email: ?string}  $seller
     */
    public function render(ProInvoice $invoice, array $seller, int $widthChars = 0): string
    {
        $w = $widthChars > 0 ? $widthChars : 48;
        $cancelled = $invoice->cancelled_at !== null;

        $out = E::init();
        $out .= E::selectCodePage(19);

        // ── En-tête : le restaurant ────────────────────────────────────────────
        $out .= E::alignCenter();
        $out .= E::doubleSize(true);
        $out .= E::bold(true);
        $out .= $this->wrap(mb_strtoupper((string) ($seller['name'] ?? 'Le Cayenne')), (int) floor($w / 2));
        $out .= E::bold(false);
        $out .= E::doubleSize(false);
        foreach (['address', 'phone', 'email'] as $k) {
            if (! empty($seller[$k])) {
                $out .= $this->wrap((string) $seller[$k], $w);
            }
        }
        if (! empty($seller['siret'])) {
            $out .= $this->wrap('SIRET '.$seller['siret'], $w);
        }
        if (! empty($seller['vat_number'])) {
            $out .= $this->wrap('TVA '.$seller['vat_number'], $w);
        }
        $out .= $this->rule($w);

        // ── Titre + numéro + dates ─────────────────────────────────────────────
        $out .= E::textSize(2, 2);
        $out .= E::bold(true);
        $out .= $this->wrap('FACTURE', (int) floor($w / 2));
        $out .= E::bold(false);
        $out .= E::textSize(1, 1);
        $out .= E::bold(true);
        $out .= $this->wrap('N° '.$invoice->number, $w);
        $out .= E::bold(false);
        if ($cancelled) {
            $out .= E::invert(true);
            $out .= $this->wrap(' FACTURE ANNULÉE ', $w);
            $out .= E::invert(false);
        }

        $out .= E::alignLeft();
        $out .= E::feed(1);
        $issued = ($invoice->created_at ?? now())->format('d/m/Y H:i');
        $out .= $this->kv('Date facture', $issued, $w);
        $out .= $this->kv('Prestation du', $invoice->service_date?->format('d/m/Y') ?? '', $w);

        // ── Client : seulement ce qui est renseigné ────────────────────────────
        $client = $this->clientLines($invoice);
        if ($client !== []) {
            $out .= $this->rule($w);
            $out .= E::bold(true);
            $out .= $this->wrap('FACTURE À', $w);
            $out .= E::bold(false);
            foreach ($client as $line) {
                $out .= $this->wrap($line, $w);
            }
        }

        // ── Désignation ────────────────────────────────────────────────────────
        $out .= $this->rule($w);
        $qty = max(1, (int) $invoice->quantity);
        $label = trim((string) $invoice->label) !== '' ? (string) $invoice->label : ProInvoice::DEFAULT_LABEL;
        $out .= E::encodeForPrinter(E::lineItemKV($qty.' x '.$label, $this->money($invoice->amount_ttc), $w));
        if ($qty > 1) {
            $htCents = (int) round(((float) $invoice->amount_ht) * 100);
            $unit = $htCents % $qty === 0
                ? number_format($htCents / $qty / 100, 2, ',', ' ')
                : number_format($htCents / $qty / 100, 4, ',', ' ');
            $out .= $this->wrap('   PU HT '.$unit.' EUR', $w);
        }

        // ── Totaux ─────────────────────────────────────────────────────────────
        $out .= $this->rule($w);
        $out .= $this->kv('Total HT', $this->money($invoice->amount_ht), $w);
        $out .= $this->kv('TVA '.$this->pct($invoice->vat_rate), $this->money($invoice->vat_amount), $w);
        $out .= E::feed(1);
        $out .= E::doubleHeight(true);
        $out .= E::bold(true);
        $out .= $this->kv('TOTAL TTC', $this->money($invoice->amount_ttc), (int) $w);
        $out .= E::bold(false);
        $out .= E::doubleHeight(false);
        $out .= $this->rule($w);

        // ── Règlement + note ───────────────────────────────────────────────────
        $out .= $this->wrap('Acquittée - réglée par '.$invoice->paymentLabel().' le '.($invoice->service_date?->format('d/m/Y') ?? ''), $w);
        $notes = trim((string) $invoice->notes);
        if ($notes !== '') {
            $out .= E::feed(1);
            $out .= $this->wrap($notes, $w);
        }

        if ($cancelled) {
            $out .= E::feed(1);
            $out .= E::bold(true);
            $out .= $this->wrap('ANNULÉE le '.$invoice->cancelled_at->format('d/m/Y H:i')
                .($invoice->cancel_reason ? ' - '.$invoice->cancel_reason : '').'.', $w);
            $out .= $this->wrap('Le numéro '.$invoice->number.' reste attribué à ce document.', $w);
            $out .= E::bold(false);
        }

        // ── Pied ───────────────────────────────────────────────────────────────
        $out .= E::feed(1);
        $out .= E::alignCenter();
        $out .= E::bold(true);
        $out .= $this->wrap('Facture établie hors ticket de caisse.', $w);
        $out .= E::bold(false);
        $out .= $this->wrap('Pas d\'escompte pour règlement anticipé.', $w);
        $out .= E::feed(4);
        $out .= E::cut();

        return $out;
    }

    /** @return list<string> */
    private function clientLines(ProInvoice $invoice): array
    {
        $lines = [];
        if (trim((string) $invoice->client_name) !== '') {
            $lines[] = trim((string) $invoice->client_name);
        }
        if (trim((string) $invoice->client_address) !== '') {
            foreach (preg_split('/\R/u', trim((string) $invoice->client_address)) ?: [] as $l) {
                if (trim($l) !== '') {
                    $lines[] = trim($l);
                }
            }
        }
        if ($invoice->client_siret) {
            $lines[] = 'SIRET '.$invoice->client_siret;
        }
        if ($invoice->client_vat_number) {
            $lines[] = 'TVA '.$invoice->client_vat_number;
        }

        return $lines;
    }

    /** Texte réenroulé à la largeur du papier (jamais coupé en plein mot si possible). */
    private function wrap(string $text, int $w): string
    {
        return E::encodeForPrinter(E::textWrap($text, max(8, $w)));
    }

    private function kv(string $label, string $value, int $w): string
    {
        return E::encodeForPrinter(E::lineKV($label, $value, $w));
    }

    private function rule(int $w): string
    {
        return E::encodeForPrinter(E::separator('-', $w));
    }

    private function money(string|float $v): string
    {
        return number_format(round((float) $v, 2), 2, ',', ' ').' €';
    }

    private function pct(string|float $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',').' %';
    }
}
