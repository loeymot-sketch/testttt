import { describe, it, expect } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

describe('Admin stock rupture route declaration', () => {
    it('stockRoutes module exists', () => {
        const pathFile = resolve(process.cwd(), 'resources/js/router/modules/stockRoutes.js');
        const content = readFileSync(pathFile, 'utf-8');
        expect(content).toContain('admin.stock.rupture');
        expect(content).toContain('StockRuptureDashboardComponent');
    });

    it('stockRoutes is registered in main router', () => {
        const indexPath = resolve(process.cwd(), 'resources/js/router/index.js');
        const content = readFileSync(indexPath, 'utf-8');
        expect(content).toMatch(/stockRoutes/);
    });
});
