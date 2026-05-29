import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [GOAL-2026-05-29 BTN-P1 DEAD-BUTTON-FIX] The Livreur cash-session admin surface
 * was non-functional via UI (surface-button agent-army audit, 3×P1):
 *   - List "Voir" did $emit('view') — but List is a TOP-LEVEL route component,
 *     no parent listened => dead.
 *   - Show "Clôturer"/"Rapprocher" did $emit('close-session'/'reconcile-session')
 *     to a non-existent parent => dead.
 *   - DeliveryBoyCashSessionFormComponent (self-contained open/close/reconcile,
 *     direct axios to real endpoints) was NEVER mounted anywhere => orphaned;
 *     no UI path to open a session.
 * Fix (all non-frozen, no backend change — endpoints already exist at
 * routes/api.php delivery-boy/cash-sessions {index,open,show,close,reconcile}):
 *   - List "Voir" -> $router.push(.show); add an Open entry that mounts Form(open).
 *   - Show Close/Reconcile -> mount Form(close|reconcile) inline; refresh on success.
 * This sentinel locks the wiring so the surface can never silently go dead again.
 */
const list = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionListComponent.vue'),
  'utf-8',
);
const show = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/deliveryBoyCashSession/DeliveryBoyCashSessionShowComponent.vue'),
  'utf-8',
);

describe('Livreur cash-session — List buttons are wired (not dead emits)', () => {
  it('View button calls viewSession (NOT $emit(view))', () => {
    expect(list).toMatch(/@click=["']viewSession\(session\.id\)["']/);
    expect(list).not.toMatch(/@click=["']\$emit\(\s*['"]view['"]/);
  });
  it('viewSession routes to the .show route with the id param', () => {
    expect(list).toMatch(/viewSession\s*\(id\)\s*\{[\s\S]*?\$router\.push\(\s*\{\s*name:\s*['"]admin\.delivery-boy-cash-sessions\.show['"][\s\S]*?params:\s*\{\s*id\s*\}/);
  });
  it('imports + registers + mounts the Form in open mode (orphan fixed)', () => {
    expect(list).toMatch(/import\s+DeliveryBoyCashSessionFormComponent\s+from/);
    expect(list).toMatch(/components:\s*\{[^}]*DeliveryBoyCashSessionFormComponent[^}]*\}/);
    expect(list).toMatch(/<DeliveryBoyCashSessionFormComponent[\s\S]*?mode=["']open["'][\s\S]*?@submitted=["']onSessionOpened["']/);
  });
});

describe('Livreur cash-session — Show Close/Reconcile are wired (not dead emits)', () => {
  it('Close sets activeFormMode=close, Reconcile sets reconcile (NOT $emit)', () => {
    expect(show).toMatch(/data-testid="delivery-cash-action-close"[\s\S]*?@click=["']activeFormMode\s*=\s*['"]close['"]["']/);
    expect(show).toMatch(/data-testid="delivery-cash-action-reconcile"[\s\S]*?@click=["']activeFormMode\s*=\s*['"]reconcile['"]["']/);
    expect(show).not.toMatch(/@click=["']\$emit\(\s*['"](close|reconcile)-session['"]/);
  });
  it('mounts the Form bound to activeFormMode + session id with @submitted handler', () => {
    expect(show).toMatch(/import\s+DeliveryBoyCashSessionFormComponent\s+from/);
    expect(show).toMatch(/<DeliveryBoyCashSessionFormComponent[\s\S]*?:mode=["']activeFormMode["'][\s\S]*?:session-id=["']session\.id["'][\s\S]*?@submitted=["']onActionSubmitted["']/);
  });
  it('onActionSubmitted re-fetches the session (status/variance refresh in place)', () => {
    expect(show).toMatch(/onActionSubmitted\s*\(\)\s*\{[\s\S]*?this\.fetch\(\)/);
  });
});
