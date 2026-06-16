/**
 * [GENIE Wave 3 — B9 T3.2 2026-06-16] The 6 admin *ShowComponent password-change forms had a dangling
 * label: the confirm-password label said for="password" while the confirm input is id="confirm_password",
 * so the confirm field had no associated label (a11y). Heal: the confirm label now binds
 * for="confirm_password". This guards every `<label for>` in those forms resolving to an existing input id.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const FILES = [
    'customers/CustomerShowComponent',
    'administrators/AdministratorShowComponent',
    'chefs/ChefShowComponent',
    'deliveryBoys/DeliveryBoyShowComponent',
    'employees/EmployeeShowComponent',
    'waiters/WaiterShowComponent',
].map((f) => resolve(process.cwd(), `resources/js/components/admin/${f}.vue`));

describe('B9 T3.2 — password-confirm label binds to its own input (no dangling for=)', () => {
    for (const path of FILES) {
        const name = path.split('/').slice(-1)[0];
        it(`${name}: every label for= resolves to an input id present in the file`, () => {
            const src = readFileSync(path, 'utf8');
            const fors = [...src.matchAll(/<label[^>]*\bfor="([^"]+)"/g)].map((m) => m[1]);
            const ids = new Set([...src.matchAll(/<input[^>]*\bid="([^"]+)"/g)].map((m) => m[1]));
            const dangling = fors.filter((f) => f !== 'photo' && !ids.has(f)); // 'photo' = file input handled separately
            expect(dangling, `dangling label for= with no matching input id: ${dangling.join(', ')}`).toEqual([]);
        });

        it(`${name}: the confirm-password label is bound to confirm_password (the bug fix)`, () => {
            const src = readFileSync(path, 'utf8');
            // the confirm label (whose text is label.confirm_password) must use for="confirm_password"
            expect(src).toMatch(/for="confirm_password"[^>]*>\s*\{\{\s*\$t\("label\.confirm_password"\)/);
        });
    }
});
