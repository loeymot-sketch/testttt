/**
 * [kds/sprint-2 F-2] Single source of truth for source mapping between the
 * `source_surface` string column (server) and the KDS UI chip enum.
 *
 * Adding a new source = one map entry + one i18n key + one theme entry.
 * Vue templates MUST NOT branch on rawSource — they only read `KDS_SOURCE_THEME[source]`
 * and `KDS_SOURCE_I18N_KEYS[source]`.
 */

// Canonical client-side enum.
export const KDS_SOURCE = Object.freeze({
    POS: 'POS',
    KIOSK: 'KIOSK',
    DELIVERY: 'DELIVERY',
    ONLINE: 'ONLINE',
    APP: 'APP',
    DINE_IN: 'DINE_IN',
});

// Reverse map: source_surface (DB lowercase) → KDS_SOURCE.
// 'web' fallback in FrontendOrderService maps to ONLINE (web checkout).
// 'admin' (loyalty controller, manual creation) collapses to POS visually —
// the kitchen still treats it like a counter order.
const SURFACE_TO_SOURCE = {
    pos: KDS_SOURCE.POS,
    admin: KDS_SOURCE.POS,
    kiosk: KDS_SOURCE.KIOSK,
    delivery: KDS_SOURCE.DELIVERY,
    web: KDS_SOURCE.ONLINE,
    online: KDS_SOURCE.ONLINE,
    mobile: KDS_SOURCE.APP,
    app: KDS_SOURCE.APP,
    dinein: KDS_SOURCE.DINE_IN,
    dine_in: KDS_SOURCE.DINE_IN,
};

/**
 * @param {string|null|undefined} rawSurface  the server column value
 * @returns {'POS'|'KIOSK'|'DELIVERY'|'ONLINE'|'APP'|'DINE_IN'}
 */
export function kdsSourceFromSurface(rawSurface) {
    if (rawSurface == null || rawSurface === '') {
        return KDS_SOURCE.POS;
    }
    const key = String(rawSurface).toLowerCase();
    return SURFACE_TO_SOURCE[key] ?? KDS_SOURCE.POS;
}

// Design tokens per `plans/DESIGN_SPEC_KDS_V2_2026-05-11.md`. The reserved
// futures (ONLINE/APP/DINE_IN) are wired now so they appear correctly when
// the data starts flowing — no template edit when the flag flips.
export const KDS_SOURCE_THEME = Object.freeze({
    POS: { bg: '#F3F4F6', text: '#374151', icon: 'receipt' },
    KIOSK: { bg: '#EFF6FF', text: '#1E40AF', icon: 'tablet' },
    DELIVERY: { bg: '#F0FDFA', text: '#0F766E', icon: 'truck' },
    ONLINE: { bg: '#ECFDF5', text: '#047857', icon: 'globe' },
    APP: { bg: '#FDF2F8', text: '#BE185D', icon: 'phone' },
    DINE_IN: { bg: '#FEF3C7', text: '#B45309', icon: 'chair' },
});

export const KDS_SOURCE_I18N_KEYS = Object.freeze({
    POS: 'label.kds_type_pos',
    KIOSK: 'label.kds_type_kiosk',
    DELIVERY: 'label.kds_type_delivery',
    ONLINE: 'label.kds_source_online',
    APP: 'label.kds_source_app',
    DINE_IN: 'label.kds_type_dinein',
});
