/**
 * Modules cachés du menu admin en V1.
 * Source: plans/PLAN_CV1-V1-CLOSEOUT-MASTER-2026-05-02.md §3 Groupe 2.
 * Audit: reports/audit/CV1_AUDIT_AXE5_CLEANUP_INVENTORY_2026-05-02.md.
 *
 * Modules conservés en code mais retirés du nav admin pour clarifier la V1
 * (différés V2). Restent accessibles par URL directe.
 *
 * Pour réafficher : retirer la clé de cette liste.
 */
export const V1_HIDDEN_MENU_MODULES = Object.freeze([
    'customers',
    'coupons',
    'offers',
    'creditBalanceReport',
    'settings.mail',
    'settings.loyalty-setup',
    'settings.notification',
    'settings.theme',
]);

export function isV1HiddenMenuModule(moduleKey) {
    return V1_HIDDEN_MENU_MODULES.includes(moduleKey);
}
