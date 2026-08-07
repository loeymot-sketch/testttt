<?php

namespace App\Services\Promo;

use App\Services\Hardware\EscPosCommandBuilder as E;

/**
 * [FLYER PROMO UBER 2026-08-07] Rendu ESC/POS du ticket promotionnel glissé
 * dans les sacs Uber Eats.
 *
 * Intention commerciale (owner) : Uber prélève 30 à 35 % de commission. Ce
 * ticket a un seul travail — convaincre un client déjà conquis par la cuisine
 * de commander la prochaine fois en direct. Toute la mise en page sert cet
 * objectif :
 *
 *   - le PRÉNOM en grand, parce qu'un ticket nominatif se lit, alors qu'un
 *     prospectus anonyme se jette ;
 *   - le CODE isolé, en double hauteur, encadré : c'est la seule chose que le
 *     client devra retaper ;
 *   - le QR juste en dessous, pour supprimer complètement cette retape sur
 *     mobile — avec l'URL EN CLAIR à côté, car toutes les imprimantes
 *     thermiques ne savent pas dessiner un QR (voir EscPosCommandBuilder::qrCode) ;
 *   - l'argument d'économie en bas, parce qu'un client ne change d'habitude
 *     que s'il comprend ce qu'il y gagne.
 *
 * Tous les textes viennent des réglages (`Settings::group('promo_flyer')`) :
 * l'exploitant doit pouvoir réécrire son message sans redéploiement.
 */
class PromoFlyerEscPosRenderer
{
    /**
     * @param  array{
     *     customer_name:string, code:string, discount_percent:int|float,
     *     site_url:string, qr_url:string, valid_until:?string,
     *     headline:string, intro:string, footer_note:string, savings_note:string
     * }  $data
     */
    public function render(array $data, int $widthChars = 48): string
    {
        $w = $widthChars > 0 ? $widthChars : 48;
        $out = '';

        $out .= E::init();
        $out .= E::selectCodePage(19);
        $out .= E::alignCenter();

        // --- En-tête : l'enseigne, en gros. C'est ce qui fait reconnaître le
        //     ticket comme venant du restaurant et non d'un tract publicitaire.
        $out .= E::doubleSize(true);
        $out .= E::bold(true);
        $out .= $this->line($data['headline'] ?? 'LE CAYENNE', $w / 2);
        $out .= E::bold(false);
        $out .= E::doubleSize(false);
        $out .= E::feed(1);

        // --- Le prénom. En double hauteur seulement (pas double largeur) :
        //     un prénom long comme « CHRISTOPHE » déborderait sinon.
        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name !== '') {
            $out .= E::doubleHeight(true);
            $out .= E::bold(true);
            $out .= $this->line('Merci ' . $name . ' !', $w);
            $out .= E::bold(false);
            $out .= E::doubleHeight(false);
        }

        $out .= E::feed(1);

        // --- L'offre, en clair.
        $intro = (string) ($data['intro'] ?? '');
        if ($intro !== '') {
            $out .= E::encodeForPrinter(E::textWrap($intro, $w));
        }

        $out .= E::feed(1);
        $out .= E::encodeForPrinter(E::separator('=', $w));

        // --- Le pourcentage : l'information qui doit se voir à un mètre.
        $percent = $this->formatPercent($data['discount_percent'] ?? 10);
        $out .= E::doubleSize(true);
        $out .= E::bold(true);
        $out .= $this->line('-' . $percent . '%', $w / 2);
        $out .= E::bold(false);
        $out .= E::doubleSize(false);

        $out .= $this->line('sur ta premiere commande', $w);
        $out .= E::encodeForPrinter(E::separator('=', $w));
        $out .= E::feed(1);

        // --- Le code. Isolé, en double hauteur, précédé de son étiquette :
        //     c'est l'élément que le client doit recopier, il ne doit jamais
        //     être confondu avec le reste du texte.
        $out .= $this->line('TON CODE PERSONNEL', $w);
        $out .= E::doubleSize(true);
        $out .= E::bold(true);
        $out .= $this->line((string) ($data['code'] ?? ''), $w / 2);
        $out .= E::bold(false);
        $out .= E::doubleSize(false);

        $validUntil = $data['valid_until'] ?? null;
        if ($validUntil) {
            $out .= $this->line('valable jusqu\'au ' . $validUntil, $w);
        }
        $out .= $this->line('utilisable une seule fois', $w);

        $out .= E::feed(1);

        // --- QR + URL en clair. L'URL n'est PAS un doublon décoratif : si
        //     l'imprimante ignore GS ( k, le QR ne sort pas du tout et sans
        //     l'URL le ticket deviendrait inutilisable sans que personne ne
        //     s'en aperçoive.
        $qrUrl = trim((string) ($data['qr_url'] ?? ''));
        if ($qrUrl !== '') {
            $out .= E::qrCode($qrUrl, 6, 'M');
            $out .= E::feed(1);
        }

        $siteUrl = trim((string) ($data['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $out .= E::bold(true);
            $out .= $this->line($siteUrl, $w);
            $out .= E::bold(false);
        }

        $out .= E::feed(1);

        // --- L'argument d'économie. C'est la raison d'être du ticket : sans
        //     lui, le client n'a aucune raison de changer d'habitude.
        $savings = (string) ($data['savings_note'] ?? '');
        if ($savings !== '') {
            $out .= E::encodeForPrinter(E::separator('-', $w));
            $out .= E::encodeForPrinter(E::textWrap($savings, $w));
        }

        $footer = (string) ($data['footer_note'] ?? '');
        if ($footer !== '') {
            $out .= E::encodeForPrinter(E::textWrap($footer, $w));
        }

        $out .= E::feed(3);
        $out .= E::cut();
        $out .= E::alignLeft();

        return $out;
    }

    /**
     * Une ligne centrée, transcodée pour l'imprimante.
     *
     * La largeur utile est divisée par deux pour les textes en double largeur :
     * le compteur de colonnes de l'imprimante compte des caractères NORMAUX, un
     * caractère double occupe donc deux colonnes. Sans cette division, tout
     * texte agrandi serait centré de travers.
     */
    private function line(string $text, float $widthChars): string
    {
        return E::encodeForPrinter(E::centerLine($text, (int) max(1, floor($widthChars))));
    }

    /**
     * 10 → « 10 », 12.5 → « 12,5 ». Virgule décimale : le ticket est français.
     */
    private function formatPercent($percent): string
    {
        $value = (float) $percent;

        if (abs($value - round($value)) < 0.01) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 1, ',', ''), '0'), ',');
    }
}
