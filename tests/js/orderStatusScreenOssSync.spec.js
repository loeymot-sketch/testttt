import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('PreparingAndReadyComponent OSS sync wiring', () => {
    it('imports OssSyncService and wires start/stop + sync hydration hooks', () => {
        const file = resolve(
            process.cwd(),
            'resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue'
        );
        const source = readFileSync(file, 'utf8');

        expect(source).toContain("import ossSyncService from \"../../../services/OssSyncService\";");
        expect(source).toContain('startOssSync()');
        expect(source).toContain('stopOssSync()');
        expect(source).toContain("ossSyncService.on('sync'");
        expect(source).toContain('ossSyncService.start({');
        expect(source).toContain('ossSyncService.stop()');
        expect(source).toContain('_hydrateFromRows(rows)');
    });
});
