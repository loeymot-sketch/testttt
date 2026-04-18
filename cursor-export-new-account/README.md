# Export Cursor — nouveau compte / même PC

Ce dossier regroupe ce que tu peux **copier manuellement** vers l’autre compte Cursor.  
Les **règles projet** (`.cursor/rules/project-continuity.mdc`) restent dans le repo : rien à faire si tu ouvres le **même workspace**.

---

## 1. Skill `foodking-handoff`

### macOS / Linux

Copie **tout le dossier** `skills/foodking-handoff/` vers :

```text
~/.cursor/skills/foodking-handoff/
```

Résultat attendu : le fichier `~/.cursor/skills/foodking-handoff/SKILL.md` existe.

### Windows

Copie vers :

```text
%USERPROFILE%\.cursor\skills\foodking-handoff\
```

(Créer `skills` et `foodking-handoff` s’ils n’existent pas.)

### Vérification

Dans Cursor (nouveau compte) : invoquer *« Session FoodKing handoff »* ou *« Applique le skill foodking-handoff »*, ou laisser l’agent lire ce skill selon ta version.

**Note** : le même skill existe aussi dans le repo sous `.cursor/skills/foodking-handoff/` ; cette copie sert surtout au **compte** qui n’ouvre pas encore le projet ou pour forcer la détection globale.

---

## 2. User Rules (manuel — obligatoire sur un nouveau compte souvent)

1. Ouvre **`rules/COPY_INTO_CURSOR_USER_RULES.md`** dans ce dossier.
2. Cursor → **Settings** → **Rules** (User Rules).
3. **Copie-colle tout le contenu** (tu peux ignorer les 2–3 lignes d’en-tête « instruction humaine » si tu préfères commencer à « You are working… »).

Ce texte est une copie de `.cursor/rules/global-operating-principles.md` du dépôt.

---

## 3. Après installation

1. **Open Folder** → racine Laravel du projet FoodKing (là où se trouve `README.md`).
2. Premier chat : voir `docs/HANDOFF_NEW_CURSOR/PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md`.

---

## Contenu de ce dossier

| Élément | Usage |
|---------|--------|
| `skills/foodking-handoff/SKILL.md` | Copier vers `~/.cursor/skills/foodking-handoff/` |
| `rules/COPY_INTO_CURSOR_USER_RULES.md` | Coller dans Cursor → Settings → Rules |

Tu peux **zipper** tout `cursor-export-new-account/` pour le transférer sur une autre machine.
