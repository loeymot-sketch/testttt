# Quarantaine Legacy Caisse V1 — 2026-04-25

## Statut

Owner : FoodKing Caisse V1 orchestration.
Date : 2026-04-25.
Mission : `CV1-M12-LEGACY-GUARDS-CI`.

Cette quarantaine empêche la réintroduction de chemins legacy dans le runtime web Laravel + Vue pendant la finition Caisse V1.

## Périmètre legacy

Chemins ciblés :

- `kiosk_implementation/` : archive Flutter/Dart, référence historique uniquement.
- `borne (Remix)/` : archive Remix, référence historique uniquement.
- `public/js/pos-wizard.js` / `pos-wizard` : ancien chemin wizard, interdit comme import runtime depuis `resources/js/`.
- Routes frontend legacy monitorées dans `routes/api.php` : `frontend/item-category`, `frontend/item`, `frontend/item/kiosk-upsell`.

## Garde-fous

- `scripts/lint-fk-legacy-imports.sh` bloque les imports JS/TS/Vue depuis `resources/js/` vers `kiosk_implementation/`, `borne (Remix)/` ou `pos-wizard`.
- `scripts/lint-fk-legacy-routes.sh` liste les routes frontend legacy encore présentes dans `routes/api.php`. Ce monitor est informatif sur M-12 ; la suppression relève de M-11.
- `scripts/scan-bundle-legacy.sh` et `scripts/lint-fk-bundle-legacy.sh` inspectent les sorties `public/build/` et `public/js/`. Les références aux archives `kiosk_implementation` / `borne (Remix)` bloquent toujours. Les références `pos-wizard` hors fichier shim `public/js/pos-wizard.js` avertissent par défaut et bloquent en release avec `FK_LEGACY_STRICT_POS_WIZARD=1`.
- `scripts/lint-fk-archive-banner.sh` vérifie les banners `ARCHIVE_BANNER.md` dans les deux dossiers legacy.
- `.github/workflows/legacy-guards.yml` exécute ces garde-fous en pull request et sur push `main`, avec déclenchement sur `public/build/**` et `public/js/**`.

## Processus d'exception

Toute modification de code dans `kiosk_implementation/` ou `borne (Remix)/` exige un gate humain explicite sous `docs/gates/` avant édition.

Exemples de gates attendus :

- `docs/gates/GATE_LEGACY_KIOSK_IMPL_*.md`
- `docs/gates/GATE_LEGACY_BORNE_REMIX_*.md`

Sans gate signé, ces dossiers restent des archives non runtime. Les seules modifications autorisées par M-12 sont les fichiers `ARCHIVE_BANNER.md`.

## Invariants associés

- Aucun import runtime depuis `resources/js/` vers les archives legacy.
- Aucun chunk JavaScript de production ne doit référencer les archives legacy. Les références au shim `pos-wizard` restent une dette de cutover et doivent être tranchées par les gates HG-W2 avant GO release.
- Les routes legacy sont monitorées sans suppression dans ce cycle.
- Aucun changement de code produit n'est autorisé par cette mission.
