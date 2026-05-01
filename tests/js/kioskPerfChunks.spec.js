import { describe, it, expect } from 'vitest';
import { execSync } from 'child_process';
import { readFileSync } from 'fs';
import { resolve } from 'path';

const root = resolve(process.cwd());

function read(rel) {
  return readFileSync(resolve(root, rel), 'utf-8');
}

describe('Perf — chunks lazy loading', () => {
  it('customer kiosk does not load a staff admin component', () => {
    const appSrc = read('resources/js/components/frontend/kiosk/KioskAppComponent.vue');
    const routesSrc = read('resources/js/router/modules/kioskRoutes.js');

    expect(appSrc).not.toMatch(/KioskAdminComponent/);
    expect(appSrc).not.toMatch(/kiosk-admin/);
    expect(routesSrc).not.toMatch(/KioskAdminComponent/);
    expect(routesSrc).toMatch(/path:\s*["']admin["'][\s\S]*redirect:\s*{\s*name:\s*["']kiosk\.idle["']\s*}/);
  });

  it('Wizard steps imported via defineAsyncComponent', () => {
    const src = read('resources/js/components/frontend/kiosk/KioskWizardComponent.vue');
    expect(src).toMatch(/defineAsyncComponent.*KioskStep/s);
    expect(src).toMatch(/import\(.*kiosk-wizard.*KioskStep/);
  });

  it('kioskRoutes uses sub-chunk names', () => {
    const src = read('resources/js/router/modules/kioskRoutes.js');
    expect(src).toMatch(/webpackChunkName:\s*["']kiosk-shell["']/);
    expect(src).toMatch(/webpackChunkName:\s*["']kiosk-wizard["']/);
    expect(src).toMatch(/webpackChunkName:\s*["']kiosk-errors["']/);
  });

  it('KioskProductListComponent is not imported anywhere (dead code removed)', () => {
    const grep = execSync(
      `grep -r "KioskProductListComponent" "${root}/resources/js" --include="*.vue" --include="*.js" -l 2>&1 || true`,
      { encoding: 'utf-8' }
    );
    expect(grep.trim()).toBe('');
  });
});
