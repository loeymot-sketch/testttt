# 🔴 P0 — Playwright vise par défaut un serveur qui sert **un autre worktree**

- **Découvert le** : 2026-08-25, en vérifiant l'effet d'un correctif de sonde de santé
- **Comment** : le correctif fonctionnait en CLI mais pas via HTTP. Le serveur ne servait pas mon code.

---

## 1. Le fait, prouvé par une mesure différentielle

Même URL, deux ports, deux réponses :

```
:8000  /api/healthz  →  queue_pending = 0
:8766  /api/healthz  →  queue_pending = 1490
```

Parce que ce ne sont pas les mêmes fichiers :

| Port | Répertoire servi | HEAD | Branche |
|---|---|---|---|
| **8000** | `.claude/worktrees/goal-caisse-vision-2026-08-24/public` | `6b9f4a965` | `goal/caisse-vision-2026-08-24` |
| **8766** | `public/` (arbre de travail principal) | `43b120c7d` | `pos/category-first-caisse-2026-06-23` |

Écart entre les deux : **89 fichiers, 15 356 insertions, 154 suppressions.**

## 2. Pourquoi c'est grave

`playwright.config.js:12` :

```js
const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000';
```

**Le défaut est `localhost:8000`.** Et `PLAYWRIGHT_BASE_URL` n'était pas définie dans cet
environnement. Donc toute campagne E2E lancée sans la définir mesure **le code d'un autre
worktree**, avec 15 000 lignes d'écart.

Les deux symptômes que cela produit sont précisément ceux qui font perdre le plus de temps :

- **Un correctif appliqué ici paraît « ne pas marcher »** — le test interroge du code qui ne
  l'a pas reçu. On corrige alors une deuxième fois, ou on cherche une cause qui n'existe pas.
- **Un défaut déjà corrigé là-bas paraît « déjà résolu »** — le test passe au vert sur du code
  qui n'est pas celui qu'on livrera.

Un harnais qui mesure le mauvais binaire ne produit pas des faux négatifs isolés : il produit une
**confiance mal placée**, ce qui est pire qu'une absence de test.

## 3. Ce que cela n'invalide pas

Par honnêteté sur mes propres mesures de cette session :

- Les relevés faits par `php artisan tinker` et `php artisan test` portaient **bien** sur l'arbre
  de travail principal — 344 alertes SLA, 1 490 travaux `notifications`, 27 identifiants morts,
  59 articles vendables : tout cela tient.
- En revanche, mes vérifications HTTP sur `/healthz` et `/api/health/ready` ont touché **`:8000`**,
  donc l'autre worktree. Les conclusions de routage restent valables (les routes sont définies
  dans les deux), mais je le signale plutôt que de le passer sous silence.

## 4. Ce qui a été fait

Une garde a été ajoutée à `tests/Playwright/global-setup.js` : avant toute campagne, elle dépose
un marqueur éphémère dans le `public/` de l'arbre de travail et vérifie que le serveur ciblé le
sert bien. Si le serveur est ailleurs, la campagne **s'arrête avec un message explicite** au lieu
de produire des résultats sur le mauvais code.

Test associé : `tests/js/e2eGardeMemeArbreDeTravail.spec.js`.

## 5. Décision demandée

- **A)** **Garder la garde et laisser le défaut à `:8000`.** *(recommandé — la garde suffit :
  elle échoue bruyamment, on ne change les habitudes de personne)*
- **B)** Changer le défaut de `playwright.config.js` pour le port de l'arbre principal.
  ⚠️ Casserait les scripts et habitudes existants qui comptent sur `:8000`.
- **C)** Exiger `PLAYWRIGHT_BASE_URL` explicitement, sans défaut du tout. Le plus strict ; oblige
  à écrire la cible à chaque campagne.

**Question annexe, votre appel** : le worktree `goal/caisse-vision-2026-08-24` est-il encore un
chantier en cours, ou un vestige ? S'il est abandonné, son serveur sur `:8000` continue de piéger
toutes les campagnes E2E lancées par réflexe.
