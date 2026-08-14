# LOCK — badge « 2e viande » rendu visible dans le wizard de caisse

**Fichier gelé touché** : `public/js/pos-wizard.js` (CLAUDE.md §7)
**Date du changement** : 2026-08-14
**Document écrit** : AVANT le commit du patch (LOCK d'abord, patch ensuite — pas rétroactif).

---

## §1 — CE QUI A CHANGÉ

4 patches, **18 lignes**, purement d'AFFICHAGE — aucune ligne de logique prix/quota/pricing
touchée :

1. Badge quota viande (render initial, ~L3126) : au lieu de rester bloqué sur « 1/1 incluse »
   quand une 2e viande différente est ajoutée en supplément payant, affiche « X incluse(s) + Y
   supp. ».
2. Même badge, jumeau dans `updateSinglePageUI()` (~L3466) — même règle, pour l'état après clic.
3. Compteur sur la tuile viande (render initial, ~L3151) : `count` devient `✓count` (coche +
   nombre) dès qu'une viande est comptée, gratuite ou supplément.
4. Même compteur, jumeau dans `updateSinglePageUI()` (~L3504).

## §2 — CE QUI ÉTAIT INTERDIT, ET QUI A ÉTÉ RESPECTÉ

- Aucun changement de `VIANDE_SUPPL_PRICE`, `detectViandeCountFromData`, ni de la logique
  `total < max` qui route un clic vers gratuit ou supplément (`bindSinglePageEvents`,
  L5984-6024) — intouchée.
- Aucun changement de `selections.*` (état) — seulement du texte affiché (`textContent`,
  concaténation dans le HTML généré).
- Pas de nouvelle classe CSS ajoutée à `pos-wizard.css` (également gelé) — tout est du texte
  inline dans les `<span>` déjà existants.

## §3 — AUTORISATION DU PROPRIÉTAIRE

Diagnostiqué en conversation le 2026-08-14 : l'owner a signalé ne pas voir l'effet du clic sur
une 2e viande différente pour sandwich/galette/tacos. Diagnostic réel (sub-agent + reproduction
navigateur sur la caisse dev, 4 combinaisons testées : Sandwich Classique, Tacos M, Galette
Normale, Tacos L) : **le mécanisme fonctionne** (le prix passe bien de 7,40€ à 9,90€, un tag
« +2,50€ » apparaît) mais le badge quota principal restait visuellement figé — confusion UX
constatée, pas défaut technique.

Trois options exactes ont été présentées à l'owner via un choix structuré (preview avant/après
pour chacune) :
- (a) garder le comportement payant, juste plus visible ← **choisie explicitement**
- (b) rendre la 2e viande gratuite comme sur Tacos L (impact revenu)
- (c) ça dépend du produit, à trancher au cas par cas

L'owner a choisi **(a)**, sur la preview textuelle exacte : « 🥩 Viande [1 incluse + 1 supp.] »
+ tuiles avec coche. C'est cette preview qui a été implémentée mot pour mot.

## §4 — VÉRIFICATIONS

- `node --check public/js/pos-wizard.js` : syntaxe JS valide.
- `npx vitest run tests/js/posWizardViandeSupplementUnified.spec.js` : **5/5 verts** (le spec
  qui simule exactement le clic 2e-viande, prix + supplément).
- `npx vitest run` sur 6 specs pos-wizard connexes (meat images, bridge extra qty, drink
  fallback, frites sauce, onion cuit, composer aware, cart line math) : **39/39 verts au total**.
- `git diff --stat -- public/js/pos-wizard.js` : 18 lignes, 4 blocs, aucun autre fichier gelé
  touché dans le même changement.
- Aucun test n'assertait sur le texte littéral du badge/compteur avant ce patch (vérifié par
  grep) — aucune régression de test existant à gérer.

## §5 — RÉALIGNEMENT DE L'EMPREINTE

Empreinte SHA-256 de `public/js/pos-wizard.js` dans
`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`, mise à jour **dans ce même commit** :

- Avant (HEAD actuel, non modifié depuis le LOCK_POSWIZARD_BOUTON_ROUE_2026-08-13) :
  `bee8e8c86885b2cd385446229b41d622b6a9707dc402cc12050483147bb7dfe2`
- Après (contenu avec ce patch) :
  `24eaac96230b4f37fa26a24a2a71e2a05d6d81c9f7679a128f3b5835464560d3`

## §6 — ROLLBACK

`git revert` du commit unique qui applique le patch (aucune migration, aucune donnée touchée —
affichage seul). Restaurer l'empreinte baseline précédente
(`bee8e8c86885b2cd385446229b41d622b6a9707dc402cc12050483147bb7dfe2`) dans le même revert si le
patch est défait.

## §7 — SUITE

Ce document est commité seul, en premier. Le commit suivant applique le patch réel sur
`public/js/pos-wizard.js` — le hook `pre-commit` (`.cursor/hooks/safety-check.sh`) l'autorisera
en lisant la citation `LOCK_POSWIZARD_VIANDE_BADGE_2026-08-14.md` dans le message de CE commit.
