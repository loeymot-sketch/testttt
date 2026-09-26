import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';
import { baseCompile } from '@intlify/message-compiler';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue'),
    'utf8',
);

describe('kiosk loyalty registration never enters an empty balance screen', () => {
    it('escapes the email placeholder so Vue I18n cannot empty the screen on register', () => {
        for (const locale of ['fr', 'en']) {
            const messages = JSON.parse(readFileSync(
                resolve(process.cwd(), `resources/js/languages/${locale}.json`),
                'utf8',
            ));
            const compiled = baseCompile(messages.kiosk.loyalty_screen.placeholder_email).code;
            expect(compiled, `${locale} email placeholder must render a literal @`).toContain('"@"');
            expect(compiled, `${locale} email placeholder must not be a linked message`).not.toContain('_linked');
        }
    });

    it('keeps PHONE_EXISTS and EMAIL_EXISTS on the registration step', () => {
        expect(source).toContain("res.data?.code === 'PHONE_EXISTS'");
        expect(source).toContain("res.data?.code === 'EMAIL_EXISTS'");
        expect(source).toMatch(/registerError\s*=\s*res\.data\?\.message[\s\S]{0,240}return;/);
    });

    it('requires a returned loyalty code before it changes to the balance step', () => {
        const guard = source.indexOf('if (!data.loyalty_code)');
        const balance = source.indexOf("this.step = 'balance';", guard);
        expect(guard).toBeGreaterThan(-1);
        expect(balance).toBeGreaterThan(guard);
    });
});
