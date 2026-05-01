import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const root = resolve(process.cwd());
const read = (rel) => readFileSync(resolve(root, rel), 'utf-8');

describe('user-reported runtime blockers', () => {
  it('suppresses noisy websocket/session banners only on POS and kiosk shells', () => {
    const banner = read('resources/js/components/common/ConnectionStatusBanner.vue');
    const kiosk = read('resources/js/components/frontend/kiosk/KioskAppComponent.vue');
    const pos = read('resources/js/components/admin/pos/PosComponent.vue');
    const kds = read('resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');

    expect(banner).toMatch(/suppressSessionInvalid/);
    expect(kiosk).toMatch(/<ConnectionStatusBanner suppress-transient suppress-session-invalid \/>/);
    expect(pos).toMatch(/<ConnectionStatusBanner suppress-transient suppress-session-invalid \/>/);
    expect(kds).toMatch(/<ConnectionStatusBanner \/>/);
  });

  it('keeps the customer kiosk locked without maintenance/admin escape paths', () => {
    const routes = read('resources/js/router/modules/kioskRoutes.js');
    const login = read('resources/js/components/frontend/kiosk/KioskLoginComponent.vue');
    const settingResource = read('app/Http/Resources/SettingResource.php');

    expect(routes).not.toMatch(/KioskAdminComponent/);
    expect(routes).toMatch(/path:\s*["']admin["'][\s\S]*redirect:\s*{\s*name:\s*["']kiosk\.idle["']\s*}/);
    expect(routes).not.toMatch(/kiosk_maintenance_mode/);
    expect(login).not.toMatch(/kiosk_maintenance_mode|exit_maintenance|disableMaintenanceMode/);
    expect(settingResource).toMatch(/'kiosk_admin_pin'\s*=>\s*null/);
  });

  it('filters admin branch selector to active branches and keeps delivery fee distance authoritative', () => {
    const navbar = read('resources/js/components/layouts/backend/BackendNavbarComponent.vue');
    const pos = read('resources/js/components/admin/pos/PosComponent.vue');
    const request = read('app/Http/Requests/PosOrderRequest.php');
    const controller = read('app/Http/Controllers/Admin/PosController.php');

    expect(navbar).toMatch(/status:\s*statusEnum\.ACTIVE/);
    expect(pos).toMatch(/delivery_distance_km:\s*null/);
    expect(pos).toMatch(/ensureWalkInCustomer\(\)/);
    expect(request).toMatch(/delivery_distance_km/);
    expect(controller).toMatch(/WalkInCustomerResolver/);
    expect(controller).toMatch(/DeliveryFeeService/);
  });

  it('keeps KDS composer addon choices visible on boards and printed kitchen tickets', () => {
    const kds = read('resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue');
    const resource = read('app/Http/Resources/KDSOrderItemsResource.php');

    expect(resource).toMatch(/'item_addons'\s*=>\s*\$this->resolveAddonsForKds\(\)/);
    expect(resource).toMatch(/composition_snapshot/);
    expect(kds).toMatch(/Array\.isArray\(item\.item_addons\)/);
    expect(kds).toMatch(/Array\.isArray\(orderItem\.item_addons\)/);
    expect(kds).toMatch(/kdsAddonDisplayName\(addon\)/);
    expect(kds).toMatch(/item\.item_addons\.map\(addon/);
  });
});
