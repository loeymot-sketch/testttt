# Audit RENDU + PARCOURS CLIENT — Mobile + Site standalone (round3, 2026-06-26)

Périmètre : `mobile/` (JSX) + `/Users/1millnonstop/Downloads/web/` (JSX). STANDALONE, READ-ONLY, aucun wireup proposé.
Canon = `mobile/data/menu.js` + `web/data/menu.js` (31 produits / 9 cats / 7 viandes). SSOT = `OwnerMenuUpdate20260623Seeder.php`.
Slugs canon valides (31) : cayenne, supreme, mega, terminator, galette-normale, galette-cayenne, chicken-burger, cheese-burger, double-cheese, fish-burger, big-burger, grill-burger, tacos-m, tacos-l, bol-frites, bol-riz, petite-frites, grande-frites, glace, tarte-daim, tiramisu, coca, coca-zero, fanta, sprite, oasis, orangina, eau-plate, capri-sun, menu-enfant-nuggets, menu-enfant-burger.

## CE QUI EST COHÉRENT (prouvé)
- **Mobile ScreenMenu / ScreenItem (`screens-main.jsx`)** : 0 slug/nom hardcodé périmé (grep phantom = vide). Items dynamiques `ITEMS.filter(...)`/`lcMenu.findItem(itemId)` (l.153, l.310).
- **Mobile wizard (`screens-item-steps.jsx`)** : viandes `lcMenu.meats.map` (l.343), sauces `lcMenu.sauces.map` (l.387) → 7 viandes + 12 sauces canon, ZÉRO liste périmée. Gère les 7 viandes mixtes.
- **Web `WebMenu` (`screens.jsx:369-468`)** : pilote `W_CATS`/`W_ITEMS` (dérivés canon), filtres cat/recherche/diète OK.
- **Web wizard (`wizard-v2.jsx`)** : viandes `M.meats.map` (l.26), extra-viande +2,50 `M.extraMeatPrice` (l.28) → canon dynamique.
- **Palette mobile** : `#F4501E` rouge Cayenne = 0 occurrence (grep vide). Mandat NOIR/ORANGE/JAUNE/BLANC respecté.
- **Servabilité** : les DEUX apps montent via `index.html` (React 18 UMD unpkg + Babel standalone `type=text/babel`, `ReactDOM.createRoot`). Mobile charge `data/menu.js`→screens ; Web charge `data/menu.js`→`api.js`→screens. Servables réelles (test visuel possible avec réseau unpkg).

## DÉFAUTS RÉELS

### [P2] web `screens.jsx:202-217` + `:164` — Home : produit FANTÔME + 2 CTA MORTES
- `onItem` (`index.html:95-99`) fait `if (!item) return;`. Les slugs passés n'existent pas → **clic = rien**.
- Preuve l.206 `onClick={()=>onItem('big-cayenne')}` "Commander Big Cayenne" : slug `big-cayenne` absent du canon → bouton mort.
- Preuve l.164 `onClick={()=>onItem('sandwich-cayenne-classique')}` "J'en profite" (offre du jour) : slug absent → bouton mort.
- Preuve l.202-205 affiche au client un produit fantôme « **Big Cayenne XL · 2 viandes** » à « **9,50 €** » — prix qui ne correspond à AUCUN item canon (le plus proche : Méga 8,00 / Terminator 9,00). Art `find(slug==='big-cayenne')` (l.212/217) = undefined → tombe sur l'emoji.
- Reco : remplacer `big-cayenne`→`mega` (ou `terminator`) + corriger nom/prix affichés ; `sandwich-cayenne-classique`→`cayenne`. Heal data-render non-frozen (`screens.jsx`).

### [P2] mobile `data/orders.js:41-46` — Commande active : noms fantômes + prix non-canon
- l.43 `name: 'Big Cayenne'` item_id 102 (=Suprême canon), line_total 9,50.
- l.45 `name: 'Bowl Frites Poulet curry'` item_id 602 (=Bol Riz canon 7,90€), line_total **10,90**.
- Rendu dans l'écran suivi de commande active (surface client). Les item_id sont canoniques mais les NOMS et prix sont périmés/incohérents. La claim brief « orders.js déjà fixé » = partielle (ids OK, libellés non).
- Reco : aligner `name`/`line_total` sur menu.js (102→« Suprême », 602→« Bol Riz » 7,90). Fixture mock, non-frozen.

### [P3] mobile `data/loyalty.js:123,150-155` — Récompenses/historique fantômes
- l.123 reward 7 `name: 'Big Cayenne −50 %'` item_id 102 (=Suprême). Récompense affichée dans le catalogue fidélité avec un nom de produit inexistant.
- l.150 « Big Cayenne · … Bowl Frites », l.152 « Big Chicken », l.154-155 « Sandwich Classique » (cat purgée). Historique mock client-visible.
- Reco : renommer reward 7 (« Cayenne −50 % » item 101) ; corriger les 3 lignes d'historique. Statique, non-frozen.

### [P3] web `screens.jsx:783` + `orders.jsx:6` — Copy marketing périmée
- l.783 prose « les **Bols Gourmands** Frites/Riz … le **Big Cayenne XL** » (Bols Gourmands = ancien nom cat, renommé « Bols » ; Big Cayenne = fantôme).
- `orders.jsx:6` historique mock « Big Cayenne XL ». Cosmétique, non-cliquable.

### [P3] mobile `screens-main.jsx:109` — Slug featured périmé (fallback gracieux)
- `findItem('tacos-xxl')` (slug absent) `|| items[0]`. Tombe sur items[0]=Cayenne (item réel, 7,40€) → AUCUN fantôme affiché, mais intention morte + fragile si l'ordre d'`ITEMS` change. Reco : `findItem('cayenne')` ou `tacos-l`.

### [P3] Parité prix mobile↔web — Capri-Sun
- mobile `menu.js:429` = **1,90 €** ; web `menu.js:400` = **1,50 €**. Seeder (l.205) dit « Capri inchangé » (valeur absolue non tranchée par grep). Un des deux affiche un prix faux au client. Reco : vérifier DB item 59 puis aligner les deux. Confiance moyenne sur la valeur canon.

### [P3-trivial] Hygiène code (aucun impact client)
- mobile `screens-item-steps.jsx:104` commentaire périmé « 15 sauces » (canon=12) — commentaire seul.
- web `wizard-v2.jsx:517-521` branche `item.bol_meat_fixed` morte (`bol_meat_fixed` null pour tous les items canon → jamais rendue ; slug `bowl-frites-` inexistant).
- Commentaires d'en-tête menu.js « 25 produits » alors qu'il y en a 31 (mobile l.443 / web l.291).

## Note neutre (hors périmètre)
Le web a reçu `api.js` ([WEB-WIREUP 2026-06-26], `index.html:53`, `PaymentPage` poste une vraie commande) — déviation du « standalone, no wireup » mais datée d'aujourd'hui = action owner récente. Non traité comme défaut (mission = cohérence menu/rendu). Mobile reste standalone pur.

## Verdict
Cœur RENDU/PARCOURS cohérent au canon (menus, wizards, viandes, palette, servabilité = propres). Résidus = **fixtures mock + 1 bloc home web non migrés** au menu canon : 2× P2 (web home fantôme+CTA mortes ; mobile commande active), 4× P3. Tous = heal data-render NON-frozen, aucun fichier frozen, aucun wireup requis.
