import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('Runtime sync flags wiring', () => {
    it('injects OSS and KDS fallback polling config in master runtime payload', () => {
        const file = resolve(process.cwd(), 'resources/views/master.blade.php');
        const source = readFileSync(file, 'utf8');

        expect(source).toContain('ossFallbackPolling: {');
        expect(source).toContain("config('catalog_v15.oss_fallback_polling.enabled'");
        expect(source).toContain("config('catalog_v15.oss_fallback_polling.interval_ms_when_connected'");
        expect(source).toContain("config('catalog_v15.oss_fallback_polling.interval_ms_when_disconnected'");

        expect(source).toContain('kdsFallbackPolling: {');
        expect(source).toContain("config('catalog_v15.kds_fallback_polling.high_activity_base_ms'");
        expect(source).toContain("config('catalog_v15.kds_fallback_polling.degraded_base_ms'");
        expect(source).toContain("config('catalog_v15.kds_fallback_polling.disconnected_base_ms'");
    });
});
