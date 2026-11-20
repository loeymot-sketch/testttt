<?php

namespace App\Support;

/**
 * [NOM-COMMANDE-BORNE 2026-10-19 · owner] LE nom d'un client sur une commande : une seule définition.
 *
 * Le nom est du texte libre (clavier de la borne, champ de la caisse, note d'un compte) qui finit
 * imprimé sur un ticket et affiché sur trois écrans. Trois relecteurs adverses ont montré que le
 * nettoyage n'existait que sur UN des chemins d'entrée (borne) et laissait passer, sur l'autre, des
 * tabulations et sauts de ligne, et sur les deux des noms INVISIBLES (braille vide U+2800, filler
 * hangul U+3164) qui s'affichent comme un nom vide mais comptent pour deux caractères.
 */
final class NomClient
{
    public const LONGUEUR_MAX = 60;

    /**
     * Caractères qui ne s'affichent pas mais que la classe Unicode « lettre » laisse passer
     * (fillers braille/hangul, séparateur mongol) : un nom fait de ça serait invisible.
     */
    private const INVISIBLES = '/[\x{2800}\x{3164}\x{115F}\x{1160}\x{FFA0}\x{180E}]+/u';

    /** Noms de substitution posés à la création d'un compte invité : jamais un vrai client. */
    private const GENERIQUES = ['guest user', 'walking customer', 'client', 'invité', 'invite'];

    /**
     * Nettoie un nom saisi. Retourne null quand il ne reste aucun nom exploitable (vide, ne
     * contenant aucune lettre : « 12 », « -- », caractères de contrôle ou invisibles seuls).
     */
    public static function nettoyer(?string $brut): ?string
    {
        if ($brut === null) {
            return null;
        }

        // Les caractères de FORMAT (zéro-largeur, joiner U+200D/200C, bidi U+202E…) sont retirés,
        // pas remplacés par une espace : « 👨‍👩 » et « می‌خواهم » doivent rester entiers.
        $nom = (string) preg_replace('/\p{Cf}+/u', '', $brut);
        // Tout autre caractère de contrôle (TAB, LF, ESC, DEL…) devient une espace.
        $nom = (string) preg_replace('/[\p{Cc}\p{Cn}\p{Co}\p{Cs}]+/u', ' ', $nom);
        $nom = (string) preg_replace(self::INVISIBLES, '', $nom);
        $nom = trim((string) preg_replace('/\s+/u', ' ', $nom));
        $nom = trim(mb_substr($nom, 0, self::LONGUEUR_MAX));

        if ($nom === '' || ! preg_match('/\p{L}/u', $nom)) {
            return null;
        }

        return $nom;
    }

    /** « Guest User » et consorts : un nom de substitution, pas une personne. */
    public static function estGenerique(?string $nom): bool
    {
        return in_array(mb_strtolower(trim((string) $nom)), self::GENERIQUES, true);
    }
}
