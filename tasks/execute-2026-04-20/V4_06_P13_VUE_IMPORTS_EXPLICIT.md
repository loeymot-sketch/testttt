# EXECUTE V4 #6 — P13_VUE_IMPORTS_EXPLICIT

TASK_ID: P13_VUE_IMPORTS_EXPLICIT
WAVE: V4 (low-risk Vite-prep, no human gate)
RUNNER_MODE: single-session
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: F-VERIFY-18-04 (R4 dans `reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md` ligne 202)

---

## Goal

Expliciter l'extension `.vue` dans **tous** les imports de SFC (Single File Components) Vue qui en sont actuellement dépourvus dans la liste cible. C'est une préparation au switch Vite (Vite n'a pas le résolveur d'extension implicite que Laravel Mix / webpack ont par défaut).

Pas de bug aujourd'hui sous Mix. Bombe à retardement future. Très faible risque, pas de logique modifiée.

---

## Scope (FILES TOUCHED — exhaustif)

### 1. `resources/js/components/frontend/menu/MenuComponent.vue`

Ligne **65** :

```js
import LoadingComponent from "../components/LoadingComponent";
```

→ devient :

```js
import LoadingComponent from "../components/LoadingComponent.vue";
```

### 2. `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`

Ligne **466** :

```js
import LoadingComponent from "../components/LoadingComponent";
```

→ devient :

```js
import LoadingComponent from "../components/LoadingComponent.vue";
```

⚠️ Ce fichier est déjà en état modifié dans le working tree (cycles antérieurs P13_LOG_HYGIENE et K-9 observability). **Ne PAS toucher aux autres lignes** : isoler strictement la ligne 466 par contexte unique avant + après.

### 3. `resources/js/components/admin/pos/PaymentComponent.vue`

Ligne **107** :

```js
import LoadingComponent from "../components/LoadingComponent";
```

→ devient :

```js
import LoadingComponent from "../components/LoadingComponent.vue";
```

Ligne **110** :

```js
import ReceiptComponent from "./ReceiptComponent";
```

→ devient :

```js
import ReceiptComponent from "./ReceiptComponent.vue";
```

---

## Méthode d'édition

Pour chaque fichier :

1. **D'abord** lire le fichier complet via Read pour confirmer le contexte exact (le numéro de ligne peut avoir glissé de ±2 si parallèle).
2. Utiliser StrReplace avec **plusieurs lignes de contexte** (au moins 3 avant + 3 après) pour garantir l'unicité.
3. **Vérifier** que `LoadingComponent.vue` et `ReceiptComponent.vue` existent vraiment :
   ```bash
   ls resources/js/components/frontend/components/LoadingComponent.vue
   ls resources/js/components/admin/components/LoadingComponent.vue
   ls resources/js/components/admin/pos/ReceiptComponent.vue
   ```
   Si l'un n'existe pas → STOP et écrire `BLOCKED_FILE_MISSING` dans le RUN report.

---

## SUBSYSTEMS_TOUCHED / OFF_LIMITS

**SUBSYSTEMS_TOUCHED**: imports SFC dans 3 composants Vue (frontend/menu, admin/kds, admin/pos).
**SUBSYSTEMS_OFF_LIMITS**: tout `app/`, `routes/`, `database/`, `tests/`, **toute la logique** des 3 fichiers cibles (template, data, methods, computed, mounted, lifecycle…). Aucun autre import ne doit être touché — ni dans ces 3 fichiers, ni ailleurs.
**INVARIANTS_AT_RISK**: aucun. Pas de logique modifiée. Résolveur Mix tolère déjà l'extension explicite.

---

## VALIDATE

1. `git diff --stat` → exactement les 3 fichiers ci-dessus, **4 lignes ajoutées + 4 supprimées** (1 + 1 + 2). Pas plus.
2. Pour chaque fichier, `git diff <file>` doit montrer **uniquement** les changements `.vue` ajouté à la fin de la string d'import. Aucune autre modification.
3. Vérification manuelle ligne par ligne :
   ```bash
   grep -n "from \"../components/LoadingComponent.vue\"" resources/js/components/frontend/menu/MenuComponent.vue
   grep -n "from \"../components/LoadingComponent.vue\"" resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue
   grep -n "from \"../components/LoadingComponent.vue\"" resources/js/components/admin/pos/PaymentComponent.vue
   grep -n "from \"./ReceiptComponent.vue\"" resources/js/components/admin/pos/PaymentComponent.vue
   ```
   Chacune doit retourner exactement 1 ligne.
4. Vérification de non-régression — qu'aucun de ces imports n'existe encore SANS extension :
   ```bash
   grep -nE "from \"[^\"]+/LoadingComponent\";" resources/js/components/frontend/menu/MenuComponent.vue resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue resources/js/components/admin/pos/PaymentComponent.vue
   grep -nE "from \"[^\"]+/ReceiptComponent\";" resources/js/components/admin/pos/PaymentComponent.vue
   ```
   Doit retourner **0 ligne**.
5. **Note** : pas de build npm dans VALIDATE — Mix accepte les deux formes, le test runtime est hors-scope. Documenter ce choix dans le RUN report.

---

## REPORT_FILE attendu

`reports/execution/RUN_P13_VUE_IMPORTS_EXPLICIT_2026-04-20.md`

Sections obligatoires :
- FILES TOUCHED + diff exact (4 lignes)
- VALIDATE OUTPUT (sorties des 4 greps positifs + 2 greps négatifs)
- Note explicite : aucune autre occurrence balayée dans le repo (hors-scope finding).

---

## SCOPE_PRESSURE — interdits absolus

- ❌ Étendre la chasse aux autres imports SFC implicites du repo. Le finding cible **ces 3 fichiers** uniquement. Toute autre extension = scope creep, sera revertée.
- ❌ Toucher à autre chose dans les 3 fichiers (template, autres imports, options).
- ❌ Toucher au build (`webpack.mix.js`, `vite.config.*`, `package.json`).
- ❌ Lancer `npm install`, `npm run dev`, `npm run prod` (build hors-scope).
- ❌ `git add` / `git commit`.

Si l'un des 3 fichiers est déjà en état "modifié" pour une autre raison (cas de `KitchenDisplaySystemComponent.vue`), **ne PAS rebaser sur HEAD ni sur l'index** — modifier directement le working tree avec contexte unique. Cf. leçon V3 #4 (anti-pattern `.env.example`).

---

## SUCCESS CRITERIA

- 3 fichiers modifiés.
- 4 lignes changées au total (1 + 1 + 2), aucune autre.
- Tous les greps de VALIDATE passent.
- Fichiers cibles `.vue` réellement présents sur disque.
- Report écrit avec sortie inline.
