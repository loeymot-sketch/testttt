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
    // [CV1-DASHBOARD-CLEANUP-2] Non-V1 operational modules hidden from admin nav.
    // Keep code/routes/tables intact while DROP TABLE gates are pending human approval.
    'deliveryBoys',
    'onlineOrders',
    'tableOrders',
    'waiters',
    'diningTables',
    'settings.mail',
    // [2026-08-10 · propriétaire : « une limite utilisable à partir de 1000 points »] L'écran des
    // règles de fidélité est DÉMASQUÉ. Il était caché depuis le nettoyage V1 du 2 mai, si bien que
    // l'exploitant n'avait aucun moyen de régler son barème ni son plancher : les trois valeurs
    // (points par €, points pour 1 € de remise, minimum utilisable) tournaient sur leurs défauts et
    // toute demande de changement exigeait un développeur. Ce n'est pas un module « différé V2 » :
    // c'est le tableau de bord de sa propre mécanique de fidélité, et il le pilote lui-même.
    // L'écran existe et est complet (LoyaltySetupComponent.vue, route admin.settings.loyaltySetup,
    // libellés FR présents, aperçu « 10 € d'achat = X pts → Y € de réduction »).
    // 'settings.loyalty-setup',
    'settings.notification',
    'settings.theme',
    'settings.item-categories',
    'settings.item-attributes',
    'settings.permission',
    'settings.role',
    'settings.tax',
    'settings.charge',
    'settings.translation',
    'settings.activity-log',
    'settings.languages',
    'settings.otp',
    'settings.notification-alert',
    'settings.social-media',
    'settings.cookies',
    'settings.analytics',
    'settings.time-slots',
    'settings.sliders',
    'settings.pages',
    'settings.sms-gateway',
    'settings.payment-gateway',
    'settings.license',
]);

export function isV1HiddenMenuModule(moduleKey) {
    return V1_HIDDEN_MENU_MODULES.includes(moduleKey);
}

/**
 * URLs of DB-driven backend menus (BackendMenuComponent.vue) hidden from the
 * V1 admin sidebar. The legacy "items" top link is suppressed when children
 * (e.g. Catalog Studio virtual row) remain visible; `/admin/items` redirects to Studio.
 */
export const V1_HIDDEN_BACKEND_MENU_URLS = Object.freeze(['items']);
