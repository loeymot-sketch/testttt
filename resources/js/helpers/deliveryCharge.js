export function calculateDeliveryChargeFromDistance(distanceKm) {
    if (distanceKm === null || distanceKm === undefined || distanceKm === '') {
        return 0;
    }

    const distance = Number(distanceKm);
    if (!Number.isFinite(distance) || distance < 0) {
        return 0;
    }

    // [DEL-FEE whole-km 2026-06-01, owner decision] Le Cayenne rule: 5 EUR covers
    // the first 5 km, then +1 EUR per STARTED km beyond (rounded up). Mirrors the
    // authoritative backend DeliveryFeeService (base=5/per_km=1/min=5/free_km=5).
    // Preview UI only; the backend recompute on the order remains authoritative.
    return Math.max(5, 5 + Math.ceil(Math.max(0, distance - 5)));
}
