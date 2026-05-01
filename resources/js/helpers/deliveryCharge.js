export function calculateDeliveryChargeFromDistance(distanceKm) {
    if (distanceKm === null || distanceKm === undefined || distanceKm === '') {
        return 0;
    }

    const distance = Number(distanceKm);
    if (!Number.isFinite(distance) || distance < 0) {
        return 0;
    }

    // FoodKing V1 rule: 5 EUR per started 5 km tranche.
    // Preview UI only; backend DeliveryFeeService::fromDistanceKm remains authoritative.
    return Math.max(5, Math.ceil(distance / 5) * 5);
}
