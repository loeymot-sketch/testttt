# DISPUTE Round 1 — VAGUE A : CAISSE vente & encaissement PROFOND

- Date : 2026-06-12 · App : http://127.0.0.1:8768 (DB foodking_e2e jetable)
- Compte : pos@lecayenne.fr (caissier) · Viewport principal 1440×900 · re-capture 1366×768
- Agent : GSTACK MAIN TEAM (Architect+Tester+A11y+SRE) — capture & observation, verdict sévérité = adversaire
- Artefacts : quartet PNG + .dom.html (80KB) + .console.txt + .network.txt par état, ce dossier
- FROZEN observés sans proposition d'édition : pos-wizard.js popup, PaymentComponent.vue, PosV5TrancheRow.vue

## Produits utilisés (DB foodking_e2e réelle, items table vérifiée)
| id | nom | prix DB |
|----|-----|---------|
| 52 | Coca-Cola 33cl | 1,50 € |
| 26 | Tacos | 8,50 € |
| 51 | Tiramisu | 3,80 € |
| 34 | Grande Frites | 4,00 € |

## Incident environnement (à signaler au superviseur)
- **DISQUE QUASI PLEIN** au démarrage de la vague : 406 Mo libres / 460 Go (100%). ENOSPC sur la 1re écriture de script. Gros consommateurs hors de mon périmètre d'autorisation (Cursor state.vscdb.backup 13 Go, CloudDocs 69 Go, Parallels 34 Go) — nettoyage refusé par le classifieur de permissions. Vague exécutée en artefacts « lean » (PNG viewport, DOM 80 Ko). Risque : échec d'écriture si un agent parallèle remplit les ~400 Mo restants.

## États couverts (incrémental)

### A1 — Commande (a) : produit simple Coca-Cola 33cl ×3 → Espèces (ORDER #4522 / 1206264522)
Artefacts : `a01-pos-initial.*`, `a02-cart-coca-x3.*`, `a03-payment-modal-cash.*`, `a04-cash-10-rendu.*`, `a05-after-confirm.*`, `a06-receipt-cash.*` (+ crops `_z-a02-*`, `_z-a04-*`, `_z-a06-*`)
- Construction : tuile Boissons → Coca-Cola 33cl, stepper + ×2 → qty 3. Cart : ligne « Coca-Cola 33cl … 4,50 € », Sous-total 4,50 €, Total 4,50 €, CTA « Commande · 4,50 € ». Toast « Article ajouté au panier ».
- PaymentComponent (FROZEN, observé) : MONTANT TOTAL **4,50 €**, modes Espèces / Carte (TPE) / Multi-paiement. MONTANT REÇU « 10 » → « Monnaie à rendre **5,50 €** » ✓ (10 − 4,50).
- POST `api/admin/pos` → **201**, id=4522, serial=1206264522, total=4.5. Aucun réseau ≥400 sur tout le flux (`a06-receipt-cash.network.txt` vide).
- Receipt modal (client) : Opérateur **« Caissier Le Cayenne »** ✓ (pas « Client passage »), SIRET/TVA intra présents, ligne « 3 | Coca-Cola 33cl | 4,09 € », SOUS-TOTAL 4,09 €, TOTAL TAXES 0,41 €, VAT (10%)·Base HT 4,09 € → 0,41 €, REMISE 0,00 €, **TOTAL 4,50 €**, Espèces : 10,00 €, Rendu : 5,50 €, N°A0005, N° ticket NF525 2168, empreinte audit c45d72022475. Cohérence arithmétique : 4,0909×1,1=4,50 ✓.

### A2 — Commande (b) : Tacos composé via wizard popup caisse [FROZEN observé] → Multi-paiement tranche unique Ticket Restaurant (serial 1206264536, fiscal A0010)
Artefacts : `b01-cat-tacos.*`, `b02-wizard-step1.*`, `b05-cart-tacos.*` (échec volontaire 1er run), `b10-invalid-add-no-sauce.*`, `b11-wizard-voir-tous-sauces.*`, `b12-wizard-viande-sauce-selected.*`, `b13-cart-tacos.*`, `b14-payment-multi.*`, `b15-payment-multi-tr.*`, `b16-after-confirm.*`, `b17-receipt-tr.*` + crops `_z-b10/_z-b11/_z-b17`
- Wizard S25 single-page (FROZEN `public/js/pos-wizard.js`) : header « Tacos €8.50 », sections `.wizard-section` « Choisis tes viandes (Obligatoire, Jusqu'à 4 choix, 0/4) », « Choisis ta sauce (Obligatoire) » 6 visibles + bouton « Voir tous (+4) » → expansion 11→15 options ✓, « Choisis ta formule » Menu (Frites + Boisson) +€3.00. Footer « Total €8.50 » + « Ajouter au panier ».
- **Probe validation** : « Ajouter au panier » avec viande 1/4 mais SANS sauce obligatoire → wizard reste ouvert, item non ajouté ; sondes DOM : 0 toast, 0 `[role=alert]`, 0 marqueur invalid (`b10-invalid-add-no-sauce.png` ne montre aucun message d'erreur visible). Rejet silencieux apparent — cf. A-SUS-5.
- Sélection valide (Poulet mariné + Algérienne) → cart : « Tacos ✎ 1× Poulet mariné, 1× Algérienne — 8,50 € », Sous-total 8,50, Total 8,50, CTA « Commande · 8,50 € ».
- PaymentComponent Multi-paiement (FROZEN observé) : état initial « Ajoutez une tranche pour commencer », COUVERT 0,00 € / RESTE DÛ 8,50 €, confirm désactivé tant que reste dû > 0 ✓. « + Ajouter une tranche » → tranche préremplie 8,50 ; select méthodes = **« 1:Espèces, 2:Carte, 3:MFS, 5:Ticket Restaurant, 4:Autre »** (cf. A-SUS-4). Méthode → Ticket Restaurant : COUVERT 8,50 € / RESTE DÛ 0,00 €.
- Confirm → receipt : « 1 | Tacos | 7,73 € », « Viande 1: Poulet mariné ,Sauce (1ère Gratuite): Algérienne », SOUS-TOTAL 7,73 €, TAXES 0,77 €, **TOTAL 8,50 €**, « Type de paiement: Ticket Restaurant », N°A0010, NF525 2171, empreinte 610e834db522. Arithmétique 7,7273×1,1=8,50 ✓. Aucun réseau ≥400.

## Intégrité numérique (relevés chiffre par chiffre)

| Commande | Cart POS | Modal paiement | Receipt TOTAL | POST JSON | pos-orders show | historique | DB orders.total |
|---|---|---|---|---|---|---|---|
| (a) #4522 / 1206264522 Coca ×3 espèces | 4,50 € | 4,50 € | 4,50 € (rendu 5,50/10) | 4.5 | (à relever) | (à relever) | (à relever) |
| (b) 1206264536 Tacos wizard TR | 8,50 € | 8,50 € (multi couvert 8,50/reste 0,00) | 8,50 € | n/a (quote 200 capté) | (à relever) | (à relever) | (à relever) |

## Anomalies suspectées (evidence factuelle, sévérité = adversaire)

- **A-SUS-1 — Ticket client : colonne « Prix » de ligne affichée en HT sans marqueur HT.** Receipt #1206264522 : ligne « 3 × Coca-Cola 33cl → 4,09 € » alors que le client paie 3×1,50 = 4,50 TTC. La colonne s'appelle juste « Prix » ; seul « SOUS-TOTAL: 4,09 € » + « VAT (10%)· Base HT » révèlent que c'est du HT. Un client lisant le ticket ne retrouve ni le prix unitaire (1,50) ni le prix TTC de la ligne (4,50). Evidence : `a06-receipt-cash.png` + `_z-a06-receipt.png`. Code : `resources/js/components/admin/pos/ReceiptComponent.vue` (~l.143-155 : « ticket prints NO per-line tax, only the netted per-rate » — choix assumé HT par ligne ?). À arbitrer par l'adversaire (cohérent NF525 mais lisibilité client).
- **A-SUS-2 — Typographie deux-points incohérente sur le ticket** : « Rendu : 5,50 € » (espace avant :) vs « Espèces: 10,00 € » / « SOUS-TOTAL: » (pas d'espace). Evidence : `_z-a06-receipt.png`. Famille proche du gate « : » orphelin tracker mais occurrence distincte (ticket).
- **A-SUS-3 — « Type de commande: À emporter » wrap maladroit** sur le preview 80mm (« À » seul en fin de ligne, « emporter » à la ligne). Evidence : `_z-a06-receipt.png`. Mineur, preview-only à confirmer sur rendu papier.
- **A-SUS-4 — Méthode de paiement « MFS » exposée dans le dropdown tranche multi-paiement FR.** Select tranche = « Espèces / Carte / **MFS** / Ticket Restaurant / Autre ». « MFS » (Mobile Financial Service, reliquat template SaaS Bangladesh) est cryptique pour un caissier français et n'est pas un moyen de paiement V1 Le Cayenne (mandat owner = Espèces/TR/Terminal manuel). Vérifié code : `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue:22` (`$t('label.mobile_banking')`, composant FROZEN — observation seulement) MAIS la string vit dans `resources/js/languages/fr.json:917` `"mobile_banking": "MFS"` (NON frozen). Evidence : log TRANCHE-SELECT-OPTIONS `["1:Espèces","2:Carte","3:MFS","5:Ticket Restaurant","4:Autre"]` + `b15-payment-multi-tr.*`. Question adjacente pour l'adversaire : « Autre » est-il souhaitable en V1 (traçabilité NF525 du type de paiement) ?
- **A-SUS-5 — Wizard caisse : rejet silencieux de « Ajouter au panier » quand une section Obligatoire (sauce) n'est pas remplie.** Item non ajouté, wizard reste ouvert, aucun toast / aria-alert / marqueur visuel d'erreur détecté (sonde DOM : toasts=[], invalidMarks=0) ni visible sur la capture. Un caissier pressé ne sait pas pourquoi le bouton « ne marche pas ». FROZEN `public/js/pos-wizard.js` — observation seulement, pas de proposition d'édition. Evidence : `b10-invalid-add-no-sauce.png` + `_z-b10-invalid.png` + log INVALID-ADD-FEEDBACK.
- **A-SUS-6 — Ticket : ligne d'options « Viande 1: Poulet mariné ,Sauce (1ère Gratuite): Algérienne »** — virgule collée-inversée (« mariné ,Sauce » : espace AVANT la virgule, aucun après). Evidence : dump texte receipt + `_z-b17-receipt.png`. **Root cause confirmée (file:line)** : `resources/js/components/admin/pos/ReceiptComponent.vue:126-128` — `{{ variation.name }}` suivi d'un saut de ligne template puis `<span>, </span>` → le whitespace de template se rend comme espace AVANT la virgule (« Poulet mariné , Sauce »). Même motif dupliqué côté ticket cuisine `:311-313`. Composant NON frozen.

## Gates/P3 connus revus (NE PAS re-compter)

- « VAT (10%) » DATA `taxes.name` : revu sur receipt #1206264522 — toujours présent (gate connu).
- Dates « 12-06-2026 » à tirets : présent aussi sur l'en-tête du ticket client (même famille que le gate listes).
- Banner « MODE TEST — IMPRESSION BYPASSÉE » : marqueur E2E attendu (`bypass-printing-marker`), pas une anomalie.
