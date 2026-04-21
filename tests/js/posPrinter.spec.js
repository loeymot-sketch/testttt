import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

import axios from 'axios';
import { listPrinters, testPrint } from '../../resources/js/services/posPrinter';

describe('posPrinter service', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('testPrint posts to the printer test endpoint and parses ok=true', async () => {
        axios.post.mockResolvedValue({
            status: 200,
            data: { ok: true },
        });

        const result = await testPrint(12);

        expect(axios.post).toHaveBeenCalledWith('admin/printers/12/test-print');
        expect(result).toEqual({ ok: true, status: 200 });
    });

    it('testPrint returns ok=false on transport failure', async () => {
        axios.post.mockRejectedValue({
            response: {
                status: 502,
                data: { message: 'printer_unreachable' },
            },
        });

        const result = await testPrint(7);

        expect(result).toEqual({
            ok: false,
            status: 502,
            error: 'printer_unreachable',
        });
    });

    it('listPrinters parses the laravel resource collection wrapper', async () => {
        axios.get.mockResolvedValue({
            data: {
                data: [
                    { id: 1, name: 'Receipt Printer' },
                    { id: 2, name: 'Kitchen Printer' },
                ],
            },
        });

        const printers = await listPrinters();

        expect(axios.get).toHaveBeenCalledWith('admin/printers', { params: {} });
        expect(printers).toEqual([
            { id: 1, name: 'Receipt Printer' },
            { id: 2, name: 'Kitchen Printer' },
        ]);
    });
});
