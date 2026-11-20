<?php

namespace App\Libraries;

use App\Enums\Ask;
use App\Enums\ItemType;
use App\Enums\Status;

/**
 * [ONB-02 2026-08-28] Reconnaître une valeur d'énumération écrite par un humain.
 *
 * CE QUI N'ALLAIT PAS, et qui coûtait une carte entière.
 *
 * Les trois méthodes ne reconnaissaient QUE l'anglais canonique (`active`,
 * `veg`, `yes`) et retombaient SILENCIEUSEMENT sur une valeur par défaut sinon.
 * Or `ItemExport` écrit des valeurs TRADUITES — `trans('statuse.'.$status)` donne
 * « Actif », `trans('itemType.…')` donne « Végétarien », `trans('ask.…')` donne
 * « Oui ». Le commerçant exportait sa carte, corrigeait deux prix, réimportait le
 * même fichier :
 *
 *   · « Actif »      → ni `active` ni `inactive` → **Status::INACTIVE**
 *   · « Végétarien » → pas `veg`                 → ItemType::VEG, quoi qu'il arrive
 *   · « Oui »        → pas `yes`                 → Ask::NO
 *
 * Résultat : ses 45 produits étaient créés INACTIFS, donc invisibles à la borne,
 * à la caisse et en cuisine. L'écran annonçait « import réussi », et il découvrait
 * le lendemain matin une borne vide, sans un seul indice de la cause.
 *
 * DEUX CORRECTIONS, et la seconde compte autant que la première :
 *
 * 1. On reconnaît maintenant les valeurs TRADUITES, dans toutes les langues
 *    installées — et on les dérive des MÊMES clés de traduction que l'export
 *    utilise, pour qu'elles ne puissent pas diverger si quelqu'un reformule un
 *    libellé.
 *
 * 2. Une valeur non reconnue rend désormais `null` au lieu d'un défaut silencieux.
 *    C'est le vrai défaut : un repli muet sur un STATUT est de la même famille que
 *    le repli muet sur une taxe à 0 % — il transforme une faute de frappe en
 *    catastrophe invisible. Les deux appelants (`ItemImport`, `ItemCategoryImport`)
 *    traitent ce `null` comme un échec de validation nommé.
 */
class EnumAppLibrary
{
    /** Les langues dans lesquelles un fichier peut avoir été exporté. */
    private const LANGUES = ['fr', 'en', 'ar', 'bn', 'de'];

    public static function itemType($itemType): ?int
    {
        return self::reconnaitre($itemType, 'itemType', [
            ItemType::VEG     => ['veg', 'vegetarian'],
            ItemType::NON_VEG => ['non veg', 'non-veg', 'nonveg'],
        ]);
    }

    public static function itemFeature($featureType): ?int
    {
        return self::reconnaitre($featureType, 'ask', [
            Ask::YES => ['yes', 'y', '1', 'true'],
            Ask::NO  => ['no', 'n', '0', 'false'],
        ]);
    }

    public static function itemStatus($status): ?int
    {
        return self::reconnaitre($status, 'statuse', [
            Status::ACTIVE   => ['active'],
            Status::INACTIVE => ['inactive'],
        ]);
    }

    /**
     * Les valeurs acceptées, telles qu'on peut les MONTRER au commerçant dans un
     * message d'erreur. Sans cette liste, « valeur invalide » ne lui dit pas quoi
     * écrire à la place.
     *
     * @return list<string>
     */
    public static function valeursAcceptees(string $fichierDeLangue): array
    {
        $valeurs = [];

        foreach (self::LANGUES as $langue) {
            foreach ((array) trans($fichierDeLangue, [], $langue) as $traduction) {
                if (is_string($traduction) && trim($traduction) !== '') {
                    $valeurs[mb_strtolower($traduction)] = $traduction;
                }
            }
        }

        return array_values($valeurs);
    }

    /**
     * @param  array<int, list<string>>  $synonymes  valeur d'énumération => écritures anglaises acceptées
     */
    private static function reconnaitre($saisie, string $fichierDeLangue, array $synonymes): ?int
    {
        $saisie = mb_strtolower(trim((string) $saisie));

        if ($saisie === '') {
            return null;
        }

        foreach ($synonymes as $valeur => $ecritures) {
            if (in_array($saisie, $ecritures, true)) {
                return $valeur;
            }
        }

        // Les traductions, dérivées des mêmes clés que l'export écrit — c'est ce qui
        // garantit qu'un fichier sorti de l'application y rentre à nouveau.
        foreach (self::LANGUES as $langue) {
            foreach ($synonymes as $valeur => $ignore) {
                $traduit = trans($fichierDeLangue . '.' . $valeur, [], $langue);

                if (is_string($traduit) && $traduit !== '' && mb_strtolower($traduit) === $saisie) {
                    return $valeur;
                }
            }
        }

        return null;
    }
}
