<?php

namespace App\Services\Promo;

use App\Models\Coupon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * [FLYER PROMO UBER 2026-08-07] Fabrique un code promo NOMINATIF et UNIQUE.
 *
 * Exigence owner, mot pour mot : « le code doit être unique parce que y a plein
 * de gens qui s'appellent par exemple Camille, on va pas mettre Camille 10 ».
 *
 * Forme retenue : PRÉNOM-XXXX (ex. `CAMILLE-7K2P`).
 *
 *   - le prénom rend le code personnel, donc mémorable et valorisant : c'est
 *     tout l'intérêt commercial du ticket ;
 *   - le suffixe aléatoire garantit l'unicité entre deux clients homonymes ET
 *     empêche de deviner le code d'un autre (un code devinable, c'est une
 *     remise offerte à qui essaie « CAMILLE10 »).
 *
 * Alphabet volontairement réduit : ni 0/O, ni 1/I/L, ni U/V. Le code est lu sur
 * un ticket thermique, souvent mal imprimé, puis retapé à la main sur un
 * téléphone. Chaque caractère ambigu est un client perdu au moment de payer.
 */
class PromoCodeGenerator
{
    /** Sans 0 O 1 I L U V — voir docblock. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    private const SUFFIX_LENGTH = 4;

    /** Longueur max du préfixe issu du prénom (le champ `coupons.code` fait 24). */
    private const NAME_MAX = 12;

    private const MAX_ATTEMPTS = 12;

    /**
     * @param  string  $customerName  prénom saisi par l'exploitant
     * @return string  code prêt à imprimer, garanti libre au moment du rendu
     */
    public function generate(string $customerName): string
    {
        $prefix = $this->normalizeName($customerName);

        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            $code = $prefix . '-' . $this->randomSuffix();

            if (! $this->isTaken($code)) {
                return $code;
            }
        }

        // 29^4 ≈ 707 000 possibilités par prénom : 12 collisions d'affilée est
        // un signal d'anomalie (base saturée, générateur cassé), pas de malchance.
        // On échoue bruyamment plutôt que de retourner un code déjà distribué.
        throw new RuntimeException(
            "Impossible de générer un code promo libre pour « {$customerName} » après " . self::MAX_ATTEMPTS . ' tentatives.'
        );
    }

    /**
     * Prénom → préfixe imprimable. Les accents sont translittérés (« Chloé » →
     * CHLOE) : le ticket est encodé en CP858 et un caractère non transposable
     * sortirait en « ? » sur le papier.
     */
    public function normalizeName(string $customerName): string
    {
        $name = trim($customerName);

        // Translittération best-effort ; iconv échoue sur certains alphabets
        // (arabe, cyrillique) et renvoie false — d'où le repli.
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($ascii === false) {
            $ascii = $name;
        }

        $ascii = strtoupper($ascii);
        $ascii = preg_replace('/[^A-Z0-9]/', '', $ascii) ?? '';
        $ascii = substr($ascii, 0, self::NAME_MAX);

        // Un prénom entièrement non-latin (ou vide) ne doit pas produire un
        // code tronqué à « - » : on retombe sur un préfixe neutre.
        return $ascii !== '' ? $ascii : 'CLIENT';
    }

    private function randomSuffix(): string
    {
        $suffix = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::SUFFIX_LENGTH; $i++) {
            // random_int : cryptographiquement sûr. Un code devinable est une
            // remise offerte — mt_rand ne convient pas ici.
            $suffix .= self::ALPHABET[random_int(0, $max)];
        }

        return $suffix;
    }

    /**
     * Un code est pris s'il existe un coupon (même supprimé : on ne recycle pas
     * un code qu'un client a peut-être encore sur un ticket dans sa poche) ou
     * un ticket déjà émis portant ce code.
     */
    private function isTaken(string $code): bool
    {
        $couponTaken = Coupon::withTrashed()->where('code', $code)->exists();
        if ($couponTaken) {
            return true;
        }

        return DB::table('promo_flyers')->where('code', $code)->exists();
    }
}
