# Orchestration — fichiers à avoir sur l’autre compte Cursor

**Principe** : le **dépôt Git** est la vérité. Le « transfert » vers un autre compte Cursor consiste à **cloner ou ouvrir le même repo** + recopier les **User Rules** globales si tu les utilises.

---

## 1. Ce qui voyage automatiquement avec le repo (rien à recopier à la main)

| Élément | Emplacement |
|---------|-------------|
| Règles projet | `.cursor/rules/*.mdc`, `.cursor/rules/*.md` |
| Commandes Cursor | `.cursor/commands/*.md` (si présentes) |
| Hooks | `.cursor/hooks.json`, scripts associés |
| Bugbot | `.cursor/BUGBOT.md` |
| Skill projet (optionnel) | `.cursor/skills/foodking-handoff/SKILL.md` |
| Doc handoff | `docs/HANDOFF_NEW_CURSOR/*` |
| Workflow | `AGENTS.md`, `workflows/` |

**Action** : `git pull` sur la machine liée au nouveau compte ; ouvrir **la même racine** du projet Laravel comme workspace.

---

## 2. Ce qui est **par compte Cursor** (à reconfigurer une fois)

| Élément | Où le remettre |
|---------|----------------|
| **User Rules** (texte global) | Cursor **Settings → Rules** : coller le contenu de `.cursor/rules/global-operating-principles.md` si tu veux les mêmes principes partout |
| **Skills personnels** | Si tu copies le skill dans `~/.cursor/skills/foodking-handoff/` (voir ci-dessous), il est disponible **tous projets** sur ce compte |
| Clés API / secrets | `.env` local (non commité) — recréer depuis `.env.example` |

---

## 3. Checklist premier lancement sur le nouveau compte

- [ ] Repo cloné à jour ; branche correcte (`main` ou la tienne).
- [ ] Workspace Cursor = **racine Laravel** (là où sont `artisan`, `composer.json`, `README.md`).
- [ ] Lire `README.md` → `docs/HANDOFF_NEW_CURSOR/00_INDEX.md`.
- [ ] Coller le prompt `PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md` dans le premier chat (ou invoquer le skill).
- [ ] Vérifier **Rules** : `project-continuity.mdc` doit être actif (alwaysApply dans le dépôt).
- [ ] Optionnel : installer le skill **global** (section 4).

---

## 4. Skill « FoodKing handoff » (recommandé pour changement de compte)

- **Paquet prêt à copier** : dossier **`cursor-export-new-account/`** à la racine du repo (`README.md` + `skills/` + `rules/` pour import manuel).
- **Version projet** : `.cursor/skills/foodking-handoff/SKILL.md` (versionné avec Git).
- **Version tous projets** : copier le dossier `foodking-handoff/` dans `~/.cursor/skills/` sur la machine du nouveau compte (ou utiliser la copie depuis `cursor-export-new-account/skills/foodking-handoff/`).

Ensuite, dans n’importe quel chat, tu peux écrire : *« Applique le skill foodking-handoff »* ou *« Session FoodKing handoff »* pour forcer la lecture des docs listées dans le skill.

---

## 5. Fichiers de vérité par thème (audit rapide)

| Thème | Fichiers |
|-------|----------|
| Vision & backlog | `docs/PROJECT_CONTINUITY_AND_VISION.md`, `docs/HANDOFF_NEW_CURSOR/08_BACKLOG_SYNTHESE.md` |
| Cache session | `docs/HANDOFF_NEW_CURSOR/CACHE_MEMOIRE_TRANSFERT.md` |
| Architecture | `docs/ARCHITECTURE.md`, `docs/HANDOFF_NEW_CURSOR/02_ARCHITECTURE_MONOLITHE.md` |
| Synchro | `docs/HANDOFF_NEW_CURSOR/03_SYNCHRONISATION_TEMPS_REEL.md`, `reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md` |
| Plan massif | `reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md` |
| Tests | `docs/TEST_PLAN.md`, `scripts/README.md` |

---

*Pas besoin de « transférer la conversation » : le dépôt + ce dossier HANDOFF + User Rules suffisent si tu suis la checklist.*
