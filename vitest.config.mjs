import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    /**
     * L5.1 — resolve.extensions élargi pour matcher la résolution webpack
     * de prod : certains composants (OSS, KDS, admin) utilisent des imports
     * sans suffixe (`from "./Foo"`). Sans cet override, Vite/Vitest échoue
     * sur Failed-to-resolve. Aucun impact prod (webpack non concerné).
     */
    resolve: {
        extensions: ['.mjs', '.js', '.ts', '.jsx', '.tsx', '.json', '.vue'],
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.js'],
        globals: true,
        /** Webpack `require.context` (resources/js/i18n.js) — absent sous Vitest. */
        setupFiles: ['./tests/js/kioskRtl-require-context-polyfill.js'],
        /**
         * [FLAKE-FIX 2026-07-30 · validation finale] Exécution des fichiers en
         * SÉRIE (pas en parallèle). RACINE PROUVÉE : happy-dom réutilise le même
         * `document` global entre fichiers d'un même worker → les MutationObservers
         * enregistrés par le VRAI pos-wizard.js (specs posWizard + harness) s'accumulent
         * d'un fichier à l'autre → races intermittentes (1-3 échecs qui CHANGENT de run
         * en run, alors que chaque fichier passe SEUL et les 8 posWizard ENSEMBLE
         * passent). Preuve : parallèle = intermittent ; `--no-file-parallelism` =
         * 371/371 · 2653 verts · 0 échec, reproductible. Le code produit est sain.
         * isolate reste true (registre de modules frais par fichier) ; on ajoute
         * seulement la sérialisation pour un `document` propre par fichier.
         * Compromis assumé (CLAUDE.md §3 : correction > vitesse) : une suite
         * DÉTERMINISTE est indispensable aux gates de convergence des sessions //.
         */
        fileParallelism: false,
        isolate: true,
    },
});
