import { execFileSync } from 'child_process';
import path from 'path';
import { describe, expect, it } from 'vitest';

const root = path.resolve('.');

function readWebServer(extraEnv = {}) {
    const script = "const c=require('./playwright.config.js'); process.stdout.write(JSON.stringify(c.webServer || null));";
    const env = { ...process.env };
    delete env.PLAYWRIGHT_BASE_URL;
    delete env.PLAYWRIGHT_WEB_SERVER_CMD;
    delete env.PLAYWRIGHT_NO_WEB_SERVER;
    Object.assign(env, extraEnv);
    return JSON.parse(execFileSync(process.execPath, ['-e', script], { cwd: root, env, encoding: 'utf8' }));
}

describe('playwright.config — serveur aligné sur la base URL', () => {
    it('utilise 8000 par défaut pour la commande et la sonde', () => {
        const webServer = readWebServer();
        expect(webServer.command).toContain('--host=127.0.0.1 --port=8000');
        expect(webServer.url).toBe('http://127.0.0.1:8000');
    });

    it('dérive host et port quand PLAYWRIGHT_BASE_URL utilise 8766', () => {
        const webServer = readWebServer({ PLAYWRIGHT_BASE_URL: 'http://localhost:8766' });
        expect(webServer.command).toContain('--host=127.0.0.1 --port=8766');
        expect(webServer.url).toBe('http://127.0.0.1:8766');
    });

    it('désactive entièrement le serveur quand PLAYWRIGHT_NO_WEB_SERVER=1', () => {
        expect(readWebServer({ PLAYWRIGHT_NO_WEB_SERVER: '1' })).toBeNull();
    });

    it('respecte une commande serveur explicite sans la réécrire', () => {
        const command = 'php artisan serve --host=127.0.0.1 --port=9123';
        const webServer = readWebServer({
            PLAYWRIGHT_BASE_URL: 'http://127.0.0.1:9123',
            PLAYWRIGHT_WEB_SERVER_CMD: command,
        });
        expect(webServer.command).toBe(command);
        expect(webServer.url).toBe('http://127.0.0.1:9123');
    });

    it('ne démarre pas Laravel contre une URL distante sans commande explicite', () => {
        expect(readWebServer({ PLAYWRIGHT_BASE_URL: 'https://staging.example.test' })).toBeNull();
    });
});
