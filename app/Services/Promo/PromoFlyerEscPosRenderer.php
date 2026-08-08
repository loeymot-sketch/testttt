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

        // --- 4. LA RÉCOMPENSE — BANDEAU NOIR --------------------------------
        // [CONVERSION 2026-08-08] Avant : un « -10% » gras entre deux lignes de « = ». Du noir sur
        // blanc au milieu de noir sur blanc — rien ne ressortait. Une imprimante thermique n'a ni
        // couleur ni graisse variable : l'inversion vidéo est le SEUL contraste dont on dispose.
        // La récompense passe donc en blanc sur noir, encadrée de deux lignes pleines : c'est la
        // première chose que l'œil trouve, avant même de lire. Sur un ticket qu'on regarde deux
        // secondes, cette hiérarchie EST le taux de conversion.
        $percent = $this->formatPercent($data['discount_percent'] ?? 10);
        // [OWNER 2026-08-08] « Enlève le trait au-dessus du -10% ». C'était un bandeau noir VIDE,
        // posé au-dessus de la remise pour l'encadrer — sur le papier il se lit comme un trait
        // parasite, pas comme un cadre. Les deux bandeaux vides sont supprimés et le sous-titre
        // entre DANS le noir : la remise devient un tampon d'un seul morceau, et plus rien ne
        // flotte au-dessus. Aucun élément ajouté au ticket, deux lignes de papier gagnées.
        $out .= E::textSize(2, 3);
        $out .= E::bold(true);
        $out .= $this->band('-' . $percent . '%', (int) floor($w / 2));
        $out .= E::bold(false);
        $out .= E::textSize(1, 1);
        $out .= $this->band('sur ta prochaine commande en direct', $w);

        // L'ÉCHÉANCE COLLÉE À LA RÉCOMPENSE, en gras. Une remise sans date se remet à plus tard,
        // et « plus tard » ne revient jamais. Deux raisons de ne pas attendre sur une ligne :
        // usage unique (rareté) et date limite (perte). Elles pèsent plus que la remise elle-même.
        $validUntil = $data['valid_until'] ?? null;
        $out .= E::bold(true);
        $out .= $this->line(
            $validUntil
                ? 'Valable 1 seule fois, jusqu\'au ' . $validUntil
                : 'Valable 1 seule fois, rien que pour toi',
            $w
        );
        $out .= E::bold(false);
        $out .= E::feed(1);

        // --- 5. L'ACTION AVANT LE REPLI -------------------------------------
        // [CONVERSION 2026-08-08] Avant, le CODE venait en premier, en double taille, et le QR
        // ensuite. C'était l'inverse de ce qu'on veut : taper un code à la main est le chemin le
        // plus coûteux, scanner est le moins coûteux. Le geste facile passe donc devant, en grand,
        // avec un verbe à l'impératif ; le code devient le repli de celui qui n'a pas pu scanner.
        $code = (string) ($data['code'] ?? '');
        $qrUrl = trim((string) ($data['qr_url'] ?? ''));
        if ($qrUrl !== '') {
            $out .= E::bold(true);
            $out .= $this->line('SCANNE : ton code est deja rempli', $w);
            $out .= E::bold(false);
            $out .= E::feed(1);
            // Module 8 (au lieu de 7) : un QR se scanne à bout de bras, dans la lumière d'un
            // couloir d'immeuble. Chaque module gagné est une tentative de scan en moins.
            $out .= E::qrCode($qrUrl, 8, 'M');
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

        // Le code en REPLI : pour qui n'a pas pu scanner. Taille normale et gras suffisent — le
        // mettre en double taille le mettait en concurrence visuelle avec la récompense, et deux
        // éléments qui crient aussi fort qu'une remise, c'est une remise qu'on ne voit plus.
        if ($code !== '') {
            $out .= $this->line('ou tape ce code sur le site :', $w);
            $out .= E::bold(true);
            $out .= $this->line($code, $w);
            $out .= E::bold(false);
        }

        // [OWNER 2026-08-08] Le téléphone, remis SEUL et DISCRET. La version précédente l'annonçait
        // sur deux lignes en gras et double hauteur, au milieu de trois autres appels à l'action :
        // le ticket donnait l'impression d'insister, et un ticket qui insiste finit reposé. Ici une
        // seule ligne, sans effet : elle ne s'adresse qu'à qui ne scanne pas, et pour cette personne
        // c'est le seul chemin. Le numéro est formaté par paires — assez lisible sans crier.
        $phone = trim((string) ($data['order_phone'] ?? ''));
        if ($phone !== '') {
            $out .= $this->line('ou par telephone : ' . $phone, $w);
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
    /**
     * [CONVERSION 2026-08-08] Bandeau BLANC SUR NOIR, pleine largeur.
     *
     * ATTENTION — ici le remplissage par espaces est DÉLIBÉRÉ, à l'exact opposé de `line()` juste
     * en dessous, et les deux règles sont justes :
     *   · `line()` ne doit PAS remplir, parce que le pilote centre déjà (remplir décale à droite) ;
     *   · `band()` DOIT remplir, parce qu'en inversion vidéo le fond noir ne couvre que les
     *     caractères imprimés — les espaces SONT le bandeau. Sans eux, on obtient une petite
     *     étiquette noire au milieu du papier au lieu d'une bande pleine largeur.
     * La ligne faisant exactement la largeur, le centrage du pilote est un non-événement : le
     * bandeau touche les deux bords.
     *
     * Ne jamais « harmoniser » ces deux méthodes : elles répondent à deux contraintes opposées.
     */
    private function band(string $text, int $widthChars): string
    {
        $w = max(1, $widthChars);
        $t = mb_substr($text, 0, $w);
        $libre = max(0, $w - mb_strlen($t));
        $gauche = intdiv($libre, 2);

        $ligne = str_repeat(' ', $gauche) . $t . str_repeat(' ', $libre - $gauche);

        return E::invert(true) . E::encodeForPrinter(E::textLine($ligne)) . E::invert(false);
    }

    private function line(string $text, float $widthChars): string
    {
        $w = (int) max(1, floor($widthChars));

        // [PHOTO OWNER IMG_2090 · 2026-08-08] CENTRAGE FAIT DEUX FOIS.
        // Le pilote est mis en mode centré une fois pour tout le ticket (E::alignCenter(), en
        // tête de render()). `centerLine()` ajoutait EN PLUS des espaces à gauche. L'imprimante
        // centrait donc « <espaces>-10% », espaces compris : la ligne partait vers la DROITE au
        // lieu d'être centrée. C'est le « pas au milieu, pas bien centré » du propriétaire, et il
        // touchait tout ce qui passe par ici — le « Merci M. Dorian ! », le -10%, le code promo et
        // l'adresse du site.
        //
        // On laisse donc l'imprimante faire le centrage (elle le fait juste, au point près) et on
        // se contente de tenir la ligne DANS la largeur : une ligne trop longue se réenroulerait,
        // et une ligne réenroulée n'est plus centrée du tout.
        return E::encodeForPrinter(E::textLine(mb_substr($text, 0, $w)));
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
