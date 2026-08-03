# ARCHIVE — kiosk_implementation/

**Statut** : LEGACY ARCHIVE — non runtime depuis 2026-04-25.

Ce dossier contient une ancienne implémentation kiosk (Flutter/Dart) **conservée pour référence historique uniquement**. Il n'est **pas** importé par le runtime web (Laravel + Vue) sous `resources/js/`.

**Interdictions** :
- Toute modification doit passer par un gate humain explicite (`docs/gates/GATE_LEGACY_KIOSK_IMPL_*.md`).
- Aucun import JS/TS depuis `resources/js/` vers ce dossier.
- Aucun chunk JavaScript ne doit contenir `kiosk_implementation` après build.

**Lints associés** : `scripts/lint-fk-legacy-imports.sh`, `scripts/scan-bundle-legacy.sh`.

**Référence** : `docs/orchestration/LEGACY_QUARANTINE_2026-04-25.md`.
