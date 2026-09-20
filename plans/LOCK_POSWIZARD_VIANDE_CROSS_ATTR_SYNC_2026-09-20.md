# LOCK — syncAndSubmit() perdait une viande enregistrée sous un id d'attribut différent

**Fichier gelé touché** : `public/js/pos-wizard.js` (CLAUDE.md §7)
**Date du changement** : 2026-09-20
**Document écrit** : AVANT le commit du patch (LOCK d'abord, patch ensuite — pas rétroactif).

---

## §1 — CE QUI A CHANGÉ

1 patch, **~20 lignes**, dans `syncAndSubmit()` → bloc « 4c. Sync viande selections to
original modal dropdowns » (~L4459-4494). Aucune ligne de logique prix/quota/pricing touchée.

Root cause : ce bloc déduplique les viandes PAR NOM pour peupler les `<select>` Viande 1/2/3
de la modale Vue d'origine (un seul dropdown par nom, gardant l'id de l'attribut 1). Il lisait
ensuite le COMPTE de sélection (`selections.viandes[key]`) **uniquement sous cet id gardé**.

Or le backend assigne un id de variation **distinct par attribut** pour le même nom (vérifié en
base, item 234 Tacos XL) :

```
Mexicanos : id 777 sous « Viande 1 », 784 sous « Viande 2 », 791 sous « Viande 3 »
```

Si le compte réel était enregistré sous l'id d'un AUTRE attribut portant le même nom (ex. Viande
2), ce compte restait invisible à la lecture sous l'id de Viande 1 → lu comme 0 → cette viande
disparaissait silencieusement de `selectedViandes`, donc des `<select>` synchronisés, donc de la
commande réellement soumise. Aucune erreur, aucun log — la perte était totale et silencieuse.

Fix : sommer le compte sur **tous** les ids partageant le même nom normalisé (pas seulement
l'id survivant de la déduplication) — même principe que la sommation multi-clé déjà en place
ailleurs dans ce fichier (`buildWizardInstruction`, L2629 :
`selections.viandes['v_'+v.id] + selections.viandes[v.key]`).

## §2 — CE QUI ÉTAIT INTERDIT, ET QUI A ÉTÉ RESPECTÉ

- Aucun changement de prix, quota, `max_select`, ni de la logique inclus/supplément
  (`extraFrom`, `extraPrice`) — intouchée.
- Aucun changement du nombre de dropdowns disponibles ni du comportement quand une viande
  dépasse ce nombre (géré séparément par le mécanisme « Viande supplémentaire », lui-même non
  touché et déjà vérifié correct par les tests existants).
- Aucun changement de `selections.*` en dehors de la LECTURE (le patch ne modifie que
  l'agrégation en lecture, jamais l'écriture des sélections).
- Pas de nouvelle classe CSS, pas de changement DOM en dehors des `<select>.value` déjà ciblés
  par ce bloc avant le patch.

## §3 — AUTORISATION DU PROPRIÉTAIRE

Origine : audit externe du 2026-09-20 (« Rapport complet de test — Le Cayenne » puis « Plan de
correction complet »), constat confirmé : commande POS `#2009261353` / `A0057`, Tacos XL 6
viandes, créée à 17,90 € au lieu des 20,40 € affichés au configurateur — une viande disparue.

Root cause tracée précisément dans ce fichier gelé (voir §1) après avoir d'abord vérifié et
EXCLU deux autres pistes avec preuve réelle :
- Site web (api.js `resolveLine`) : un ordre réel à 6 viandes placé contre le backend local
  produit un résultat correct (3 variations + 3 extras nommés, total exact) — PAS la cause.
- Wizard caisse, flux tuiles single-page avec données réalistes (ids distincts par attribut,
  comme en base) : le récap et le ticket affichés sont corrects — la fuite ne vient PAS de
  l'affichage ni du calcul de prix, mais spécifiquement de `syncAndSubmit()`.

Propriétaire : « approuvé, corrige le POS wizard » (message explicite, en réponse à la
présentation de ce diagnostic précis, fichier nommé, mécanisme expliqué).

Limite de confiance à signaler : je n'ai pas pu reconstituer, par le seul chemin UI accessible
en test (clics sur les tuiles), la séquence exacte qui fait qu'un compte se retrouve enregistré
sous l'id d'un attribut autre que le premier. Le défaut est réel et prouvé au niveau de la
fonction (voir §4), et le corrige la rend robuste dans tous les cas — mais je ne peux pas
garantir à 100 % que c'est l'unique mécanisme ayant produit CETTE commande précise. Signalé au
propriétaire comme piste alternative à ne pas exclure : le service `VoiceOrder` (non gelé,
non audité dans ce lot), qui alimente aussi des commandes taguées `source_surface=phone`.

## §4 — VÉRIFICATIONS

- `node --check public/js/pos-wizard.js` : syntaxe JS valide.
- `npx vitest run tests/js/posWizardViandeSyncCrossAttrIdBug.spec.js` — **nouveau test,
  rouge/vert prouvé** :
  - AVANT le patch (`git stash` du fichier) : le 3ᵉ dropdown reste sur son option vide par
    défaut (`-`), Nuggets absent → `expected [...] to include 'Nuggets'` échoue.
  - APRÈS le patch : les 3 dropdowns portent Mexicanos / Cordon Bleu / Nuggets — vert.
- `npx vitest run tests/js/posWizardTacosXL6MeatsRepro.spec.js` — 1/1 vert (flux tuiles
  single-page avec ids réalistes, non affecté par ce défaut ni régressé par le patch).
- `npx vitest run` sur 35 fichiers / 285 tests pos-wizard connexes (cart, tracker, loyalty,
  availability, cash-drawer, wizard) : **285/285 verts**, aucune régression.
- `git diff --stat -- public/js/pos-wizard.js` : 1 bloc, ~20 lignes, aucun autre fichier gelé
  touché dans le même changement.

## §5 — RÉALIGNEMENT DE L'EMPREINTE

Empreinte SHA-256 de `public/js/pos-wizard.js` dans
`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`, mise à jour **dans ce même commit** :

- Avant (HEAD actuel) :
  `d4be19d72976155d5a001bc7075e7cb5983209b4fbc2917f2524df0cfde6aa9d`
- Après (contenu avec ce patch) :
  `3bf2f9c90c9320bf61d7e8619c535cee3e82acfa6d7f1314273a89ec6258995f`

## §6 — ROLLBACK

`git revert` du commit unique qui applique le patch (aucune migration, aucune donnée touchée).
Restaurer l'empreinte baseline précédente
(`d4be19d72976155d5a001bc7075e7cb5983209b4fbc2917f2524df0cfde6aa9d`) dans le même revert.

## §7 — SUITE

Ce document est commité seul, en premier. Le commit suivant applique le patch réel sur
`public/js/pos-wizard.js` + la mise à jour de la baseline SHA-256 — le hook
`.git/hooks/pre-commit` (bloc 5, frozen-zone touch detection) l'autorisera en lisant la
citation `LOCK_POSWIZARD_VIANDE_CROSS_ATTR_SYNC_2026-09-20.md` dans le message de CE commit.

Recommandation ouverte (hors périmètre de ce LOCK, non exécutée) : si l'incident se reproduit
après ce correctif, investiguer `app/Services/VoiceOrder/VoiceOrderCatalogMatcher.php` et
`VoiceOrderDraftExtractor.php` (non gelés) — commandes automatisées taguées
`source_surface=phone`, mécanisme de résolution nom→variation distinct de celui de
`pos-wizard.js`, non audité dans ce lot.
