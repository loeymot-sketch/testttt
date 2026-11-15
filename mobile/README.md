# App mobile Le Cayenne — plan du dossier

L'application que le propriétaire a désignée comme « l'application réelle » (2026-11-14). Publication : `PUBLICATION_APP.md`.

## Ce que charge l'app (dans cet ordre, depuis `index.html`)

| Fichier | Rôle |
|---|---|
| `index.html` | Page d'entrée : configuration, navigation entre écrans (`App`), feuilles et messages |
| `styles.css` | Jetons (couleurs, **marges d'écran**), mise en page commune, accueil, mouvement |
| `redesigns-styles.css` | Assistant de personnalisation (`rdw-*`) et fidélité (`rdl-*`) |
| `api/` | `storage.js` (mémoire locale), `client.js` (appels serveur), `catalog-sync.js` (carte relue au serveur) |
| `data/` | Carte de repli hors ligne, fidélité, commandes, profil |
| `icons.jsx`, `shared.jsx`, `image-slot.js` | Icônes, briques communes (onglets, en-tête, images) |
| `hooks/`, `components/` | QR de fidélité, code-barres, assistant d'utilisation des points |
| `screens-onboarding.jsx` | Accueil de marque, présentation, connexion, code SMS |
| `screens-item-steps.jsx` | Assistant de personnalisation d'un plat |
| `screens-main.jsx` | Accueil, carte, panier, confirmation, commandes, profil, fidélité |
| `screens-modals.jsx` | Feuilles (paiement, suppression de compte…), détail de commande, message |

## Autour

- `tools/build-www.mjs` — construit le paquet publiable (`mobile/www/`).
- `tools/banc-marges/` — banc de mesure des marges d'écran (voir ci-dessous).
- `tests/` — tests statiques, un fichier = `node mobile/tests/<nom>.spec.js`.
- `assets/`, `uploads/`, `vendor/` — images et bibliothèques embarquées.
- `archive/prototype-2026-05/` — ancienne planche de maquettes et captures de mise au point. **Non chargée par l'app, non entretenue.**
- `CONNECTION_PLAN.md`, `WALLET_PLAN.md`, `HOW_TO_RESUME.md` — notes de conception historiques.

## Marges d'écran (barre d'état, encoche, barre d'accueil) — règle unique

Le téléphone dit lui-même la place prise en haut et en bas ; l'app ne réserve **aucune valeur fixe**.
Tout passe par les jetons définis en tête de `styles.css` :

| Jeton | Sens |
|---|---|
| `--lc-inset-top` / `--lc-inset-bottom` | Marge réelle du téléphone (`env()`, ou valeur posée par Capacitor sur Android ancien) |
| `--lc-safe-top` | Où commence le contenu d'un écran |
| `--lc-safe-bottom` | Dégagement minimal sous le dernier bouton d'un écran sans onglets |
| `--lc-tabs-h` | Hauteur totale de la barre d'onglets, marge basse comprise |

Pour un nouvel écran :

- **défilant** : `<div className="lc-screen">` (commence sous la barre d'état) ; avec la barre d'onglets, ajouter `lc-screen--tabs` ;
- **fixe** (colonne entière) : `className="lc-page"` ;
- **pied fixe** (total + bouton) : `className="lc-foot"` ;
- ne jamais écrire `bottom: 24` ou `padding: '… 36px'` sur un élément ancré en bas : utiliser les jetons.

Vérifier :

```bash
node mobile/tests/mobile-safe-area.spec.js            # aucun retour de valeur fixe
python3 -m http.server 8797                            # dans mobile/ ; serveur local sur :8766
node mobile/tools/banc-marges/parcours.mjs iphone15    # puis iphone-se, pixel-geste, android-3b, android-ancien, sans-marge
```

Le banc parcourt l'app écran par écran et sort en erreur s'il reste un texte ou un bouton dans une marge, ou coupé sur un côté.

## Personnalisation : quantités, viande en plus, note (2026-11-15)

L'assistant (`screens-item-steps.jsx`) ne décide d'aucune limite : il lit le **profil publié du serveur** pour le plat
(`api/client.js` → `profileChoiceIds().limits`) et propose exactement ce que le serveur accepte.

- **Viandes** : chaque toucher ajoute une viande ; la même peut être prise deux fois si deux emplacements la proposent
  (Tacos L/XL, Méga, Terminator…). Emplacements pleins → bouton « + en plus » (extra serveur « Viande supplémentaire »).
- **Suppléments** : plafond = `max_select` de l'étape « Suppléments » du profil (viande en plus comprise). Si l'étape a
  `allow_repeat`, un compteur − n + permet de doubler ; sinon chaque supplément se prend une fois.
- **Viande en plus** : 1 tant que l'étape n'autorise pas la répétition ; sinon jusqu'à `LC_MAX_EXTRA_MEATS` (4) dans la
  limite du plafond. Pour l'ouvrir : dans l'admin, étape « Suppléments » du profil → répétition autorisée (+ plafond
  voulu), puis publier. Aucune mise à jour de l'app n'est nécessaire.
- **Note** : composant `NoteField` (récapitulatif de l'assistant et fiche d'un produit simple), 190 caractères,
  envoyée à la cuisine en `[Note : …]`.
- Tout refus est expliqué à l'écran (jauge qui tressaute + message) : jamais un toucher sans réponse.

## Ruptures (2026-11-15)

- La fiche d'un plat est demandée **avec la branche** (`/api/frontend/item/details/{id}?branch_id=`) et gardée 45 s :
  sans la branche, le serveur n'indique pas la rupture d'une viande ou d'un supplément.
- Un choix vendu avec le plat mais en rupture reste **visible, grisé, étiqueté « Rupture »** (viandes, sauces,
  suppléments) ; le toucher explique. Il ne peut pas être pris « en plus » (l'extra « Viande supplémentaire » est
  générique côté serveur : c'est l'app qui empêche de demander une viande en rupture).
- Un produit entier en rupture : « Épuisé » sur la carte et bouton d'ajout désactivé.
- La même viande en plus deux fois part en cuisine sous la forme `Viandes en plus : 2× Tenders.` (les lecteurs de
  la cuisine dédoublonnent les noms répétés).
