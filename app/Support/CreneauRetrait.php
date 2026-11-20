<?php

namespace App\Support;

use App\Enums\Ask;
use App\Libraries\AppLibrary;
use Carbon\CarbonInterface;

/**
 * [E2E stores · vague B · F-B2 · 2026-10-01] Date et heure du créneau de retrait, en UN seul endroit.
 *
 * Trois ressources (fiche caisse, cuisine, livreur) ajoutaient un jour à `order_datetime` dès que
 * `is_advance_order` valait OUI — l'ancienne règle « commande prise pour le lendemain » — et
 * ignoraient `scheduled_at`. Une commande passée à 04 h pour 18 h 20 le même jour se lisait donc
 * « 02-10-2026 », sans heure. Règle désormais : une heure programmée fait foi ; sans elle,
 * l'ancien comportement est conservé à l'identique.
 */
final class CreneauRetrait
{
    private static function programmee(object $commande): ?CarbonInterface
    {
        $prevue = $commande->scheduled_at ?? null;

        return $prevue instanceof CarbonInterface ? $prevue->copy()->timezone(config('app.timezone')) : null;
    }

    /** Date du créneau, ou null s'il n'y en a pas (commande immédiate sans créneau). */
    public static function date(object $commande): ?string
    {
        if ($prevue = self::programmee($commande)) {
            return AppLibrary::date($prevue);
        }
        if ((int) ($commande->is_advance_order ?? 0) === Ask::YES) {
            return AppLibrary::increaseDate($commande->order_datetime, 1);
        }
        if (AppLibrary::deliveryTime($commande->delivery_time ?? null) !== '') {
            return AppLibrary::date($commande->order_datetime);
        }

        return null;
    }

    /** Heure du créneau : l'heure programmée si elle existe, sinon le créneau saisi (ou ''). */
    public static function heure(object $commande): string
    {
        $creneau = AppLibrary::deliveryTime($commande->delivery_time ?? null);
        if ($creneau === '' && ($prevue = self::programmee($commande))) {
            return $prevue->format('H:i');
        }

        return $creneau;
    }
}
