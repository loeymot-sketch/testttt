<?php

namespace App\Services\Promo;

use App\Services\Hardware\EscPosCommandBuilder as E;
use Illuminate\Support\Facades\Cache;

/**
 * [FLYER PROMO 2026-08-07, logo + civilité 2026-08-08] Rendu ESC/POS du ticket
 * promotionnel glissé dans les sacs des commandes de plateformes.
 *
 * Intention commerciale : les plateformes prélèvent 30 à 35 % de commission. Ce
 * ticket a un seul travail — convaincre un client déjà conquis par la cuisine
 * de commander la prochaine fois en direct. Toute la mise en page sert ça :
 *
 *   1. le LOGO, pour que le ticket soit immédiatement reconnu comme venant du
 *      restaurant et non d'un prospectus publicitaire glissé au hasard ;
 *   2. le NOM du client, précédé de sa civilité : un mot adressé se lit, un
 *      tract anonyme se jette ;
 *   3. la REMISE en très grand, lisible à un mètre ;
 *   4. le CODE isolé et encadré — la seule chose que le client devra retaper ;
 *   5. le QR juste dessous pour supprimer complètement cette retape, avec
 *      l'URL en clair à côté (toutes les thermiques ne dessinent pas les QR) ;
 *   6. l'argument d'économie en bas : sans lui, le client n'a aucune raison de
 *      changer d'habitude.
 *
 * Tous les textes viennent des réglages : l'exploitant réécrit son message sans
 * redéploiement.
 */
class PromoFlyerEscPosRenderer
{
    /**
     * Le logo ne change jamais d'un ticket à l'autre. Sa conversion en points
     * coûte ~40 ms (tramage Floyd-Steinberg sur ~70 000 pixels) : la refaire à
     * chaque impression serait du gaspillage pur, et se verrait sur une rafale
     * de tickets. La clé inclut la date de modification du fichier, donc
     * remplacer le logo suffit à rafraîchir le cache.
     */
    private const LOGO_CACHE_TTL = 86400;

    /**
     * @param  array<string,mixed>  $data
     */
    public function render(array $data, int $widthChars = 48): string
    {
        $w = $widthChars > 0 ? $widthChars : 48;
        // 48 colonnes = papier 80 mm (576 points). On laisse une marge : un logo
        // pleine largeur touche le bord et sort souvent tronqué.
        $logoDots = $w >= 48 ? 512 : 384;

        $out = '';
        $out .= E::init();
        $out .= E::selectCodePage(19);
        $out .= E::alignCenter();

        // --- 1. LOGO ---------------------------------------------------------
        $logo = $this->logoBytes((string) ($data['logo_path'] ?? ''), $logoDots);
        if ($logo !== '') {
            $out .= $logo;
            $out .= E::feed(1);
        } else {
            // Repli texte : un ticket sans logo reste un bon ticket, un ticket
            // sans en-tête n'en est plus un.
            $out .= E::doubleSize(true);
            $out .= E::bold(true);
            $out .= $this->line((string) ($data['headline'] ?? 'LE CAYENNE'), $w / 2);
            $out .= E::bold(false);
            $out .= E::doubleSize(false);
            $out .= E::feed(1);
        }

        // --- 2. LE CLIENT, NOMMÉ --------------------------------------------
        $greeting = $this->greeting($data);
        if ($greeting !== '') {
            $out .= E::doubleHeight(true);
            $out .= E::bold(true);
            $out .= $this->line($greeting, $w);
            $out .= E::bold(false);
            $out .= E::doubleHeight(false);
            $out .= E::feed(1);
        }

        // --- 3. LE MESSAGE ---------------------------------------------------
        $intro = trim((string) ($data['intro'] ?? ''));
        if ($intro !== '') {
            $out .= E::encodeForPrinter(E::textWrap($intro, $w));
            $out .= E::feed(1);
        }

        // --- 4. LA REMISE, EN GRAND -----------------------------------------
        $out .= E::encodeForPrinter(E::separator('=', $w));
        $percent = $this->formatPercent($data['discount_percent'] ?? 10);
        $out .= E::textSize(2, 3);
        $out .= E::bold(true);
        $out .= $this->line('-' . $percent . '%', $w / 2);
        $out .= E::bold(false);
        $out .= E::textSize(1, 1);
        $out .= $this->line('sur ta prochaine commande en direct', $w);
        $out .= E::encodeForPrinter(E::separator('=', $w));
        $out .= E::feed(1);

        // --- 5. LE CODE ------------------------------------------------------
        $out .= $this->line('TON CODE PERSONNEL', $w);
        $out .= E::feed(1);
        $code = (string) ($data['code'] ?? '');
        $out .= E::textSize(2, 2);
        $out .= E::bold(true);
        $out .= $this->line($code, $w / 2);
        $out .= E::bold(false);
        $out .= E::textSize(1, 1);
        $out .= E::feed(1);

        $validUntil = $data['valid_until'] ?? null;
        if ($validUntil) {
            $out .= $this->line('valable jusqu\'au ' . $validUntil, $w);
        }
        $out .= $this->line('valable une seule fois, rien que pour toi', $w);
        $out .= E::feed(1);

        // --- 6. QR + URL EN CLAIR -------------------------------------------
        $qrUrl = trim((string) ($data['qr_url'] ?? ''));
        if ($qrUrl !== '') {
            $out .= $this->line('Scanne, ton code est deja rempli :', $w);
            $out .= E::feed(1);
            $out .= E::qrCode($qrUrl, 7, 'M');
            $out .= E::feed(1);
        }

        $siteUrl = trim((string) ($data['site_url'] ?? ''));
        if ($siteUrl !== '') {
            $out .= E::doubleHeight(true);
            $out .= E::bold(true);
            $out .= $this->line($siteUrl, $w);
            $out .= E::bold(false);
            $out .= E::doubleHeight(false);
        }
        $out .= E::feed(1);

        // --- 7. POURQUOI COMMANDER EN DIRECT --------------------------------
        $savings = trim((string) ($data['savings_note'] ?? ''));
        if ($savings !== '') {
            $out .= E::encodeForPrinter(E::separator('-', $w));
            $out .= E::encodeForPrinter(E::textWrap($savings, $w));
        }

        $footer = trim((string) ($data['footer_note'] ?? ''));
        if ($footer !== '') {
            $out .= E::feed(1);
            $out .= E::encodeForPrinter(E::textWrap($footer, $w));
        }

        $out .= E::feed(4);
        $out .= E::cut();
        $out .= E::alignLeft();

        return $out;
    }

    /**
     * « Merci Mme Camille ! » — la civilité est facultative, parce que les
     * plateformes ne fournissent qu'un prénom et qu'on ne devine pas le genre
     * de quelqu'un. Quand elle est absente, la phrase reste naturelle.
     */
    private function greeting(array $data): string
    {
        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $civility = trim((string) ($data['civility'] ?? ''));
        $hello = trim((string) ($data['greeting'] ?? 'Merci')) ?: 'Merci';

        $who = $civility !== '' ? ($civility . ' ' . $name) : $name;

        return $hello . ' ' . $who . ' !';
    }

    /**
     * Octets du logo, mis en cache. Un logo illisible ou absent renvoie une
     * chaîne vide : l'appelant retombe alors sur l'en-tête texte.
     */
    private function logoBytes(string $path, int $dots): string
    {
        if ($path === '' || ! is_readable($path)) {
            return '';
        }

        $key = 'promo_flyer.logo.' . md5($path . '|' . $dots . '|' . (string) @filemtime($path));

        try {
            return (string) Cache::remember(
                $key,
                self::LOGO_CACHE_TTL,
                static fn () => E::rasterImage($path, $dots)
            );
        } catch (\Throwable $e) {
            // Un cache indisponible ne doit jamais empêcher un ticket de sortir.
            return E::rasterImage($path, $dots);
        }
    }

    /**
     * Ligne centrée, transcodée pour l'imprimante.
     *
     * La largeur utile est divisée pour les textes agrandis : le compteur de
     * colonnes de l'imprimante compte des caractères NORMAUX, un caractère
     * double occupe deux colonnes. Sans cette division, tout texte agrandi
     * serait centré de travers.
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
