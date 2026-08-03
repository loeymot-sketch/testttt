# Devis handoff — Claude Design (Catalog Studio FoodKing)

Ce dossier est une **copie jetable** pour charger tout le contexte dans **Claude Design** (Anthropic Cloud). Tu peux le **supprimer** du dépôt après upload.

## Contenu

| Élément | Rôle |
|--------|------|
| `copie/` | Copies des fichiers source (doctrine, design system, modèle de données, composants Vue, runtime POS/Kiosk, i18n FR, captures) |
| `INVENTAIRE-FICHIERS.txt` | Liste plate de tous les fichiers dans `copie/` |
| `PROMPT-CLAUDE-DESIGN-COLLER.txt` | Prompt technique long (anglais) à coller **après** avoir attaché les fichiers |
| `LIREMOI.md` | Ce fichier — messages courts à copier |

## Ordre d’upload recommandé dans Claude Design

1. Glisser-déposer **tout le dossier `copie/`** (ou sélectionner tous les fichiers dedans).
2. Coller le **Message 1** ci-dessous.
3. Coller le contenu de **`PROMPT-CLAUDE-DESIGN-COLLER.txt`** (Message 2).

Si Claude Design limite la taille : priorité `copie/A-brief` → `B-design-system` → `C-data-model` → `D-vue-components` → le reste.

---

## Message 1 — à coller en premier (court)

```
Projet FoodKing (SaaS restauration). Je joins un dossier "copie" avec le contexte
technique : doctrine (CLAUDE.md, AGENTS.md), design system CV1 + tokens borne,
schéma BDD wizard (item_wizard_steps, item_attributes, extras, addons), services
Composer Laravel, composants Vue admin actuels (Catalog Studio, éditeur wizard,
stock), runtime POS (pos-wizard.js) et Kiosk (KioskWizardComponent), fr.json,
quelques captures POS/kiosk.

Ta mission : produire un design admin complet type Shopify pour UNE page "Catalog
Studio" : catégories, produits, wizard POS+Kiosk, stock parallèle, publication avec
diff. Respect strict des tokens et contraintes du brief.

Dans mon prochain message je colle le brief technique détaillé (prompt).
```

---

## Message 2 — à coller ensuite

Ouvre le fichier **`PROMPT-CLAUDE-DESIGN-COLLER.txt`**, sélectionne tout (Cmd+A), copie, colle dans Claude Design.

---

## Message 3 — optionnel (si le premier rendu est trop générique)

```
Itère sur l’écran 3 (éditeur wizard) : prends un vrai exemple "tacos" avec 5 étapes
préremplies (viande, sauces, crudités, accompagnement, boisson), source_type réels,
et la prévisualisation live à droite : en haut rendu POS une seule page dense, en
bas rendu Kiosk multi-pages avec points de pagination. Les deux doivent ressembler
à nos captures jointes, pas à un cadre téléphone générique.
```

---

## Après livraison Claude Design

Transfère à l’équipe dev (Cursor) : maquettes + fiche composants + README de mapping
écran → fichier `.vue` — pour intégration sans ambiguïté.
