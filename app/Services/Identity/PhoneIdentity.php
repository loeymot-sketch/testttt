<?php

namespace App\Services\Identity;

/**
 * « CE NUMÉRO », défini une seule fois pour tout le logiciel.
 *
 * ── POURQUOI CETTE CLASSE VIT ICI, ET PAS DANS LA ROUE ───────────────────────────────────────
 * La définition est née dans `WheelService` le 10 août, pour réparer un défaut mesuré : 62 comptes
 * sur 348 portent une forme non normalisée du même numéro. Le service qui CRÉAIT le compte
 * connaissait les quatre écritures ; celui qui CRÉDITAIT les points, trente lignes plus loin, n'en
 * cherchait qu'une. Pour ces clients, le compte existait, la réclamation le retrouvait, et la
 * remise au comptoir répondait « aucun compte à ce numéro, crée-le puis reviens » — un ordre
 * impossible à exécuter.
 *
 * Le comptoir a maintenant besoin de la même définition pour retrouver un client par son
 * téléphone — le moyen d'identification que l'exploitant préfère. En écrire une seconde version
 * dans le service de caisse, ce serait refaire exactement la faute qu'on vient de réparer. Elle
 * déménage donc dans un foyer neutre : ni la roue, ni la caisse, ni le site n'en sont propriétaires.
 * `WheelService::normalizePhone()` et `::phoneVariants()` restent en place et délèguent ici.
 *
 * ── CE QU'ON NE FAIT PAS ─────────────────────────────────────────────────────────────────────
 * On ne devine AUCUN indicatif étranger. Mieux vaut un compte de plus qu'un lot ou des points
 * crédités au mauvais humain.
 *
 * Sentinelle : tests/Feature/Identity/PhoneIdentityTest.php
 */
final class PhoneIdentity
{
    /** Chiffres seuls : « 06 12 34 56 78 », « +33612345678 » et « 0612345678 » sont UNE personne. */
    public function normalize(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';

        // Forme nationale française : +33 6 … → 06 …
        if (strlen($d) === 11 && str_starts_with($d, '33')) {
            $d = '0' . substr($d, 2);
        }

        return $d;
    }

    /**
     * TOUTES LES ÉCRITURES D'UN MÊME NUMÉRO — la liste à passer à un `whereIn('phone', …)`.
     *
     * Le site n'impose aucune forme : la base contient « 0612345678 », « 612345678 » et
     * « +33612345678 » pour le même humain. Chercher une seule écriture, c'est ne pas le trouver.
     *
     * @return array<int, string>
     */
    public function variants(string $phone): array
    {
        $tel = $this->normalize($phone);
        $v   = [$tel];

        if (str_starts_with($tel, '0') && strlen($tel) === 10) {
            $sansZero = substr($tel, 1);
            $v[] = $sansZero;
            $v[] = '33' . $sansZero;
            $v[] = '+33' . $sansZero;
        }

        // LA RELATION DOIT ÊTRE SYMÉTRIQUE. « 0612345678 » trouvait « 612345678 », mais l'inverse
        // était faux : un caissier qui tape le numéro sans son zéro de tête — ou qui le recopie d'un
        // formulaire où il manque — ne trouvait aucun compte. C'est le même défaut que les 62 comptes
        // non normalisés, pris par l'autre bout. Neuf chiffres sans zéro de tête, c'est un numéro
        // français amputé de son zéro : on essaie donc aussi les trois autres écritures.
        //
        // On ne devine toujours AUCUN indicatif étranger : la seule hypothèse ajoutée est le zéro
        // national manquant, celle que la saisie humaine produit tous les jours.
        if (! str_starts_with($tel, '0') && strlen($tel) === 9) {
            $v[] = '0' . $tel;
            $v[] = '33' . $tel;
            $v[] = '+33' . $tel;
        }

        return array_values(array_unique(array_filter($v, static fn ($x) => $x !== '')));
    }

    /**
     * Assez de chiffres pour désigner quelqu'un ? Un numéro français en compte 10 ; on tolère 9
     * (saisie sans le zéro de tête), en dessous ce n'est pas un numéro, c'est une frappe partielle.
     */
    public function looksComplete(string $phone): bool
    {
        return strlen($this->normalize($phone)) >= 9;
    }

    /**
     * Forme masquée pour un écran de comptoir : « 06 •• •• •• 78 ».
     *
     * Le caissier a besoin de confirmer qu'il tient le bon client sans que le numéro complet
     * s'affiche devant la file d'attente.
     */
    public function masked(string $phone): string
    {
        $d = $this->normalize($phone);
        if (strlen($d) < 4) {
            return '••';
        }

        return substr($d, 0, 2) . ' •• •• •• ' . substr($d, -2);
    }
}
