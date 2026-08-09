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

        // --- 4. LA RÉCOMPENSE — UN COUPON, PAS UN PAVÉ ----------------------
        // [OWNER 2026-08-09] « mieux que 10% en bloc noir ». Le bandeau inversé pleine largeur
        // attirait bien l'œil, mais il ressemblait à une erreur d'impression : sur du papier
        // thermique, une grande surface noire bave, chauffe la tête et se lit comme un défaut.
        // Il donnait au ticket un air de rappel de facture, pas de cadeau.
        //
        // On dessine donc un vrai COUPON : deux barres pleines qui l'isolent du reste, le montant
        // en très grande taille, et le code DANS son propre cadre — l'objet que le client va
        // découper mentalement. Le contraste vient de la TAILLE et du CADRE, pas d'un aplat.
        // Tous les caractères de filet utilisés ici ont été vérifiés un par un contre l'encodage
        // CP858 de l'imprimante (═ ║ ┌ ─ ┐ └ ┘ ▄ ▀ passent ; ★ et ✓ sont PERDUS — ne pas les
        // réintroduire, ils sortiraient en « ? »).
        $percent = $this->formatPercent($data['discount_percent'] ?? 10);
        $code = (string) ($data['code'] ?? '');

        $out .= $this->rule('╔', '═', '╗', $w);
        $out .= E::bold(true);
        $out .= $this->line('TON CADEAU, RIEN QUE POUR TOI', $w);
        $out .= E::bold(false);

        // Le montant : le plus gros élément du ticket, sans concurrence.
        $out .= E::textSize(3, 3);
        $out .= E::bold(true);
        $out .= $this->line('-' . $percent . '%', $w / 3);
        $out .= E::bold(false);
        $out .= E::textSize(1, 1);
        $out .= $this->line('sur ta prochaine commande en direct', $w);
        $out .= E::feed(1);

        // [OWNER 2026-08-09] « le code promo avec son nom plus visible ». Le code PORTE le prénom
        // du client : c'est ce qui transforme un bon de réduction anonyme en cadeau personnel.
        // La version précédente l'avait rétrogradé en repli discret, sous le QR — logique du point
        // de vue du geste (scanner coûte moins cher que taper), mais elle effaçait justement ce qui
        // rend le ticket touchant. Il reprend donc sa place ici, en double taille, dans son cadre.
        if ($code !== '') {
            $largeurCadre = min($w - 2, 36);
            $out .= $this->line('┌' . str_repeat('─', $largeurCadre - 2) . '┐', $w);
            $out .= E::textSize(2, 2);
            $out .= E::bold(true);
            $out .= $this->line($code, $w / 2);
            $out .= E::bold(false);
            $out .= E::textSize(1, 1);
            $out .= $this->line('└' . str_repeat('─', $largeurCadre - 2) . '┘', $w);
        }

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
        $out .= $this->rule('╚', '═', '╝', $w);
        $out .= E::feed(1);

        // --- 5. L'ACTION AVANT LE REPLI -------------------------------------
        // [CONVERSION 2026-08-08] Avant, le CODE venait en premier, en double taille, et le QR
        // ensuite. C'était l'inverse de ce qu'on veut : taper un code à la main est le chemin le
        // plus coûteux, scanner est le moins coûteux. Le geste facile passe donc devant, en grand,
        // avec un verbe à l'impératif ; le code devient le repli de celui qui n'a pas pu scanner.
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

        // Le code n'est PAS répété ici : il est déjà en grand dans le coupon ci-dessus. Le
        // réimprimer une seconde fois donnait au ticket un air de formulaire, et diluait
        // l'élément même qu'on veut rendre mémorable. Une seule mention, mais la bonne.
        if ($code !== '') {
            $out .= $this->line('ou tape ton code ci-dessus sur le site', $w);
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
        $out .= $this->strengths((string) ($data['strengths'] ?? ''), $w);

        $savings = trim((string) ($data['savings_note'] ?? ''));
        if ($savings !== '') {
            $out .= E::feed(1);
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
     * « Bonsoir Camille, » — la salutation d'ouverture.
     *
     * [OWNER 2026-08-09] « au debut bonsoir (prenom) ». La salutation est calculée à l'heure
     * de création du ticket (voir PromoFlyerService) : un ticket glissé dans un sac à 21 h
     * doit dire « Bonsoir », pas « Merci ». C'est la première ligne que le client lit, et
     * c'est elle qui décide s'il lit la suite ou s'il froisse le papier.
     *
     * La civilité reste facultative : les plateformes ne fournissent qu'un prénom et on ne
     * devine pas le genre de quelqu'un. Sans elle la phrase reste naturelle.
     *
     * Virgule finale et non point d'exclamation : « Bonsoir Camille, » ouvre une phrase que
     * le message d'introduction termine. Un « ! » refermerait l'adresse sur elle-même.
     */
    private function greeting(array $data): string
    {
        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $civility = trim((string) ($data['civility'] ?? ''));
        $hello = trim((string) ($data['greeting'] ?? '')) ?: 'Bonjour';

        $who = $civility !== '' ? ($civility . ' ' . $name) : $name;

        return $hello . ' ' . $who . ',';
    }

    /**
     * Filet de coupon, pleine largeur, avec ses coins.
     *
     * [OWNER 2026-08-09] Premier essai fait en demi-blocs pleins (`▄` / `▀`) : le résultat
     * redevenait un pavé noir, exactement ce qu'on cherchait à quitter. Le filet double avec
     * coins (`╔══╗` / `╚══╝`) se lit comme une CARTE — un objet qu'on garde — au lieu d'une
     * surface encrée. Il économise aussi l'encre et la chauffe de la tête d'impression.
     * Caractères vérifiés un par un contre CP858 : 0xC9 0xCD 0xBB 0xC8 0xBC.
     */
    private function rule(string $gauche, string $milieu, string $droite, int $widthChars): string
    {
        $w = max(3, $widthChars);

        return E::encodeForPrinter(E::textLine($gauche . str_repeat($milieu, $w - 2) . $droite));
    }

    /**
     * « Pourquoi commander en direct » — les points forts, un par ligne.
     *
     * [OWNER 2026-08-09] « tout autre details de dire nos point forts ». Le ticket demandait
     * au client de changer d'habitude sans jamais lui dire ce qu'il y gagne AUTRE que la
     * remise. Une remise seule achète une commande ; une raison en fabrique une habitude.
     *
     * Les lignes viennent des réglages (une par ligne) : l'exploitant connaît ses arguments
     * mieux que moi, et il doit pouvoir les réécrire sans redéploiement.
     */
    private function strengths(string $raw, int $w): string
    {
        $lignes = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw) ?: [])));
        if ($lignes === []) {
            return '';
        }

        $out = E::encodeForPrinter(E::separator('-', $w));
        $out .= E::bold(true);
        $out .= $this->line('POURQUOI COMMANDER EN DIRECT ?', $w);
        $out .= E::bold(false);

        foreach ($lignes as $ligne) {
            // Le texte est aligné à GAUCHE le temps de la liste : une puce centrée produit
            // un escalier illisible dès que deux lignes ont des longueurs différentes.
            $out .= E::alignLeft();
            foreach (E::wrapIndented('> ' . $ligne, $w, '  ') as $morceau) {
                $out .= E::encodeForPrinter(E::textLine($morceau));
            }
            $out .= E::alignCenter();
        }

        return $out;
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
