import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

/**
 * [MISE À JOUR DANS L'APPLICATION 2026-10-19] Le lien https://…/etiquettes.apk servait encore la version 1.0.0 alors que le
 * code en était à 1.1.0 : personne ne l'avait remarqué, et la tablette installait l'ancienne application (sans micro).
 * Ce garde-fou refuse qu'une des trois choses diverge : le projet Android (build.gradle), le descripteur de version lu
 * par l'application (public/etiquettes-app.json) et le fichier réellement publié (public/etiquettes.apk, empreinte).
 */
const ROOT = path.resolve(__dirname, '../..');
const lire = (f) => fs.readFileSync(path.join(ROOT, f));

describe('application tablette : fichier publié, descripteur et projet Android cohérents', () => {
    const gradle = lire('native/labels-android/app/build.gradle').toString('utf8');
    const json = JSON.parse(lire('public/etiquettes-app.json').toString('utf8'));

    it('versionCode et versionName du projet = descripteur publié', () => {
        const code = Number(/versionCode\s+(\d+)/.exec(gradle)[1]);
        const nom = /versionName\s+"([^"]+)"/.exec(gradle)[1];
        expect(json.version_code, 'versionCode du descripteur ≠ build.gradle').toBe(code);
        expect(json.version_name, 'versionName du descripteur ≠ build.gradle').toBe(nom);
    });

    it('l\'empreinte SHA-256 du descripteur est celle du fichier .apk réellement servi', () => {
        const sha = crypto.createHash('sha256').update(lire('public/etiquettes.apk')).digest('hex');
        expect(json.sha256, 'etiquettes.apk a été remplacé sans mettre à jour etiquettes-app.json (ou l\'inverse)').toBe(sha);
    });

    it('le fichier est bien un APK signé (archive ZIP contenant un manifeste et une signature)', () => {
        const b = lire('public/etiquettes.apk');
        expect(b.slice(0, 2).toString('latin1')).toBe('PK');
        const texte = b.toString('latin1');
        expect(texte).toContain('AndroidManifest.xml');
        expect(texte).toContain('APK Sig Block 42'); // signature v2/v3
    });

    it('le lien public pointe sur ce fichier, en chemin relatif', () => {
        expect(json.url).toBe('/etiquettes.apk');
    });

    it('le manifeste déclare ce que les fonctions exigent : micro, installation de mises à jour', () => {
        const m = lire('native/labels-android/app/src/main/AndroidManifest.xml').toString('utf8');
        expect(m).toContain('android.permission.RECORD_AUDIO');
        expect(m).toContain('android.permission.REQUEST_INSTALL_PACKAGES');
    });

    it('l\'application relaie la demande de micro et prend en charge le téléchargement de mise à jour', () => {
        const j = lire('native/labels-android/app/src/main/java/fr/lecayenne/etiquettes/MainActivity.java').toString('utf8');
        expect(j).toContain('onPermissionRequest');
        expect(j).toContain('setDownloadListener');
        expect(j).toContain('showOfflinePage');
    });
});
