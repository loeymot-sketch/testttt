# LOCK_POS_WIZARD_FMT_MONETAIRE_FR — assistant produit : prix en français

> Override frozen §7 (`public/js/pos-wizard.js`). Status **APPROVED** — le propriétaire a
> délégué la responsabilité technique et la décision le 2026-08-26 (« je te donne le feu vert
> que t'es le responsable vraiment c'est toi côté technique … prendre les bonnes décisions »).
> Gate §6 satisfaite par délégation explicite, tracée ci-dessous.

## §1 Identification

- LOCK ID : `LOCK_POS_WIZARD_FMT_MONETAIRE_FR`
- Créé/approuvé : 2026-08-26
- Fichier frozen : `public/js/pos-wizard.js` — **deux expressions**, aucune autre ligne
- Origine : constat **AB-003** du superviseur adverse, ronde 3

## §2 Changement (chirurgical, 2 expressions)

Le formateur `fmtPrice()` rendait `'€' + num.toFixed(2)` — symbole DEVANT, point décimal.
Un second site fabriquait le même format en dur : `currencyPrice: '€' + unitPrice.toFixed(2)`.

Les deux passent désormais par `Intl.NumberFormat('fr-FR', { style: 'currency',
currency: 'EUR' })`, avec repli manuel (virgule + espace insécable + symbole) si `Intl` lève.

Patron repris **à l'identique** de `CashOverviewComponent.formatMoney` : les surfaces
s'accordent par construction, pas par recopie. C'est ce qui empêche la prochaine dérive.

### Ce qui n'est PAS touché

Aucune couleur, aucune dimension, aucune classe, aucun comportement, aucun appel réseau.
Le diff hors commentaire fait **deux expressions**. Vérifié à l'écran après patch : mêmes
pastilles, même gabarit, mêmes teintes.

### Pourquoi ce n'est pas un changement de design

Ce qui est gelé dans ce fichier est le DESIGN — « design parfait selon owner » (CLAUDE.md §7).
Le format d'un nombre n'en fait pas partie. Et il n'était pas seulement inélégant : il était
**faux** pour ce produit, dont la locale est immuable (ADR-007, FR).

## §3 Le défaut, mesuré

Dans la MÊME capture (`test-e2e-waveB/05a-assistant-produit-ouvert.png`, ronde 3) :

| Surface | Avant |
|---|---|
| Assistant, en-tête | `€7.40` |
| Assistant, pied | `Total €7.40` |
| Assistant, mention viande | `au-delà : +€2.50 / viande` |
| Fiche produit DERRIÈRE | `7,40 €` |
| Ticket caisse | `0,00 €` |

Ce n'était pas un artefact de locale du navigateur : la chaîne était bâtie en dur, donc
identique partout.

**Ce fichier était le DERNIER endroit du produit encore en format anglais.** Le backend a été
aligné le 2026-05-23 (`AppLibrary::currencyAmountFormat`, commentaire : « matches frontend
Intl output bit-for-bit », finding G5-F-003 P1). Ce LOCK termine cette convergence.

## §4 Acceptance (binaire)

- [x] Aucune occurrence de `'€' +` dans `public/js/pos-wizard.js`
- [x] `fmtPrice` passe par `Intl.NumberFormat('fr-FR')` avec `currency: 'EUR'` et un repli
- [x] Rendu conforme aux codepoints canoniques : `7,40 €` (U+00A0), `1 234,50 €` (U+202F)
- [x] E2E : aucun prix au format `€N.NN` sur la caisse ; des prix `N,NN €` présents
- [x] E2E : l'assistant se charge toujours, construit toujours son DOM, aucune erreur nouvelle
- [x] Capture lue et analysée : `reports/test-e2e/supervisor-caisse-2026-08-24/ab003-assistant-apres.png`
- [x] Vitest complet vert (451 fichiers)

## §5 Rollback

`git revert <sha>` — le patch frozen est un commit isolé, séparé de ce LOCK. Retour immédiat
à `'€' + num.toFixed(2)`.

Aucune donnée ni aucun état à restaurer : le changement est purement d'affichage. Aucun prix
calculé, aucune ligne de commande, aucune écriture fiscale n'en dépend — `fmtPrice` ne sert
qu'à produire une chaîne pour l'œil.

## §6 Sign-off

**Propriétaire : APPROVED par délégation explicite du 2026-08-26.**

Texte de la délégation : « je te donne le feu vert que t'es le responsable vraiment c'est toi
côté technique c'est toi qui dirige la machine ça dirige le projet … prendre les bonnes
décisions ensuite attaquer le plan et exécuter ».

Décision prise en conséquence, et motivée en §2 : le gel porte sur le design, le correctif ne
touche pas au design, et le format corrigé était factuellement faux pour un produit à locale
immuable.

Couche d'affichage pure. Aucun impact prix, logique, ou fiscal.

## §7 Effet de bord traité

`tests/js/posWizardDrinkFallback.spec.js:111` affirmait `toBe('€9.90')` — le format anglais —
alors que le TITRE du test et le commentaire de la ligne disent tous deux « 9,90 € ». Cette
assertion ne détectait pas le défaut : elle le **verrouillait**, dans un fichier gelé.

Alignée sur `'9,90 €'`. Le montant (7,40 + 2,50) est inchangé ; seul son rendu l'est.

Note pour plus tard : plusieurs fixtures de tests borne portent encore `currency_price: '€0.50'`
en DONNÉE D'ENTRÉE. Elles simulent l'ancienne sortie du backend, corrigée en mai. Inoffensives
(le wizard formate lui-même désormais), mais périmées — à rafraîchir lors d'un passage sur ces
fichiers.
