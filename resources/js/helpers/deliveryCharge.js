// [LIVRAISON 2026-10-20 · décision propriétaire] Miroir D'AFFICHAGE du barème de livraison.
// Source de vérité : config/delivery.php → App\Services\Delivery\DeliveryTariff (le serveur recalcule
// TOUJOURS le prix depuis l'adresse enregistrée ; ce fichier ne sert qu'à l'aperçu à l'écran).
// Une sentinelle PHPUnit (tests/Feature/Delivery/DeliveryTariffMirrorSentinelTest.php) échoue si les
// constantes ci-dessous divergent de la configuration serveur.
//
//   Hénin-Beaumont ........ 3,90 €  (forfait unique)
//   autre commune ≤ 5 km .. 4,90 €
//   autre commune ≤ 8 km .. 5,90 €
//   autre commune ≤ 10 km . 6,90 €
//   au-delà de 10 km ...... refusé → Uber Eats
export const HOME_CITY = 'Hénin-Beaumont';
export const HOME_POSTAL_CODES = ['62110'];
export const HOME_FEE = 3.9;
export const TIERS = [
    { maxKm: 5, fee: 4.9 },
    { maxKm: 8, fee: 5.9 },
    { maxKm: 10, fee: 6.9 },
];
export const MAX_KM = 10;

const fold = (s) => String(s || '')
    .toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[\s\-–—']+/g, ' ')
    .trim();

export function isHomeCity(city, postalCode) {
    const postal = String(postalCode || '').replace(/\s+/g, '');
    if (postal && HOME_POSTAL_CODES.includes(postal)) return true;
    const c = fold(city);
    return c !== '' && c === fold(HOME_CITY);
}

/** @returns {{allowed: boolean, fee: number, tier: string, suggestUberEats: boolean}} */
export function quoteDelivery(distanceKm, city = null, postalCode = null) {
    if (isHomeCity(city, postalCode)) {
        return { allowed: true, fee: HOME_FEE, tier: 'home', suggestUberEats: false };
    }
    const km = distanceKm === null || distanceKm === undefined || distanceKm === '' ? NaN : Number(distanceKm);
    if (!Number.isFinite(km) || km < 0) {
        return { allowed: false, fee: 0, tier: 'invalid', suggestUberEats: false };
    }
    if (km > MAX_KM) {
        return { allowed: false, fee: 0, tier: 'outside', suggestUberEats: true };
    }
    const i = TIERS.findIndex((t) => km <= t.maxKm);
    return i === -1
        ? { allowed: false, fee: 0, tier: 'outside', suggestUberEats: true }
        : { allowed: true, fee: TIERS[i].fee, tier: `radius_${i + 1}`, suggestUberEats: false };
}

/** Montant à afficher (0 quand la livraison n'est pas possible). */
export function calculateDeliveryChargeFromDistance(distanceKm, city = null, postalCode = null) {
    return quoteDelivery(distanceKm, city, postalCode).fee;
}
