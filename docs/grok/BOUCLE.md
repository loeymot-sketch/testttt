# BOUCLE GROK — un tour = un geste commerçant vrai

Complète la boucle dépôt `BOUCLE.md` (Cursor/Codex). Ici : cadence **Grok
back-office**. Ne pas éditer `BOUCLE.md` racine (contrat Claude/Cursor).

## Étapes d'un tour

1. **Choisir un geste** dans `docs/grok/MISSION_GROK.md` (un écran, un verbe :
   créer / modifier / supprimer / réouvrir).
2. **Frontière** : le fichier est dans `grok-owned` de `FRONTIERES.md` ? Sinon STOP.
3. **Reproduire** le défaut sur le chemin réel (service ou `PUT/POST` admin).
4. **Écrire le test d'abord** qui échoue en nommant la cause (pas « assert true »).
5. **Montrer le rouge** (`vendor/bin/phpunit --filter=...` après
   `safe-test.sh --check`). Coller l'extrait dans le JOURNAL.
6. **Correctif scope-minimal** + commentaire FR commerçant.
7. **Remontrer le vert** sur le même filtre.
8. **JOURNAL** : une entrée, format ci-dessous.

## JOURNAL (`reports/grok/JOURNAL.md`)

```
## YYYY-MM-DD — <geste commerçant>

- Écran / route :
- Avant (vécu commerçant) :
- Cause (file:line) :
- Correctif :
- Preuve rouge : (extrait phpunit FAIL)
- Preuve verte : (extrait phpunit PASS)
- Fichiers (liste explicite, jamais git add .) :
```

## Tests

```bash
bash ~/.claude/skills/brain/scripts/safe-test.sh --check
vendor/bin/phpunit --filter=MissionGrokContractTest
vendor/bin/phpunit --filter=<TestDuGeste>
```

Interdit : `php artisan test` nu (a déjà wipe la base opérationnelle).
