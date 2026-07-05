export function calculateDeliveryChargeFromDistance(distanceKm) {
    if (distanceKm === null || distanceKm === undefined || distanceKm === '') {
        return 0;
    }

    const distance = Number(distanceKm);
    if (!Number.isFinite(distance) || distance < 0) {
        return 0;
    }

    // [DEL-FEE whole-km — owner rule, base lowered 5€→4€ on 2026-06-27] Le Cayenne:
    // 4 EUR covers the first 5 km, then +1 EUR per STARTED km beyond (rounded up).
    // Mirrors the authoritative backend DeliveryFeeService (base=4/per_km=1/min=4/
    // free_km=5). Preview UI only; the backend recompute on the order remains
    // authoritative (and applies the separate ≥30€ free-delivery gate).
    return Math.max(4, 4 + Math.ceil(Math.max(0, distance - 5)));
}
