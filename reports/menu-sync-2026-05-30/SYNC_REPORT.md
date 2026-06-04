# Menu price sync — caisse/borne → frontends standalone (2026-05-30)

**Goal** : aligner web + mobile (standalone séparés) sur les MAJ caisse/borne, incluant la mise à jour des prix. Audit + structure + test après.

## SSOT
**DB MySQL `foodking` items table** (live, 45 items actifs status=5, last update 2026-05-30 19:40). Source canonique consommée par POS + Kiosk via `KioskMenuService`/`PricingService`. `config/menu.php` = STALE pre-reset (ne pas utiliser). Snapshot : `db-prices.tsv`.

## Méthode (advisor-validated)
1. Extraction DB live (`mysql --default-character-set=utf8mb4`).
2. Harness Node déterministe : load `window.LC.menu.items` des 2 trees, diff par NAME contre db-prices.tsv. **Test décisif = frontend-vs-DB** (pas mobile-vs-web — un sentinel mobile==web passe avec les 2 frontends faux).
3. Edits surgical. 4. Test : DB-parity + Preview MCP visual + arithmétique panier + adversarial 7-angles.

## 4 drifts corrigés (35 autres items déjà alignés)

| Produit | Caisse/DB | Frontend (avant) | Action |
|---|---|---|---|
| Sandwich Cayenne | **7,00 €** | 7,50 € | → 7,00 |
| Sandwich Classique | **6,50 €** | 7,00 € | → 6,50 |
| Tacos (1 viande) | **8,50 €** | "Tacos M" 6,90 € | rename + → 8,50 |
| Big Tacos (2 viandes) | **11,50 €** | "Tacos L" 8,90 € | rename + → 11,50 |
| Menu formule (addon) | **3,00 €** | 2,50 € | → 3,00 |

### ⚠️ FLAG OWNER — décision Tacos (override documenté)
Le frontend portait un commentaire owner-decision 2026-05-30 (Tacos M/L 6,90/8,90). La sync met **8,50/11,50** car :
- La **caisse/borne sert 8,50/11,50 LIVE** (DB items 26/27, kiosk render vérifié).
- Consumer-safety : l'app ne doit pas afficher en-dessous du prix encaissé.
- Directive owner : « aligner l'app sur la caisse ».

**Si 6,90/8,90 est le prix retail voulu → corriger la CAISSE** (la DB est la source, l'app la reflète). Override chronologique : la board-note (12:22) est plus récente que le prix DB (créé 2026-05-28), mais la caisse n'a PAS adopté 6,90/8,90 → la caisse reste 8,50/11,50, donc l'app suit la caisse.

## Refs périmées nettoyées (au-delà des prix)
- 3ème valeur Tacos périmée : `orders.js` "Tacos L 7,90" (ni 8,90 ni 11,50)
- Marquee + restaurant card + onboarding (mobile) + about/footer/5 legal HTML (web) : "tacos M&L" → "Tacos & Big Tacos"
- Wizard label formule rendu DYNAMIQUE (anti-drift futur)

## Preuves (test après — exigé owner)
- **DB-parity** : MOBILE 44/0/0 · WEB 44/0/0 (matched/mismatch/missing)
- **Visual Preview MCP** : Sandwich Cayenne 7,00 € · Tacos 8,50 € · Big Tacos 11,50 € rendus
- **Arithmétique panier** : Tacos+Menu 11,50 · Big Tacos+Menu 14,50 · SC×2 14,00 · Tacos+Cheddar 9,40 (via `priceFor()`)
- **Sentinel mobile↔web** : bit-identical GREEN
- **Adversarial 7-angles** : GO, 0 P0/P1
- **0 spec mobile-e2e** ne dépend des valeurs changées

## Isolation
2 commits séparés (mobile sur `heal/cms-pr1-quickwins-2026-05-18` ; web sur repo `/Users/1millnonstop/Downloads/web` main). Frontends restent STANDALONE, 0 wireup API. 0 frozen-zone touch, 0 backend touch.

## Backlog non-bloquant (adversarial P2/P3)
- orders.js demo line_totals historiques (prix d'époque — correct, immutabilité)
- web/orders.jsx "Big Cayenne XL" suffixe (pré-existant, hors scope)
- DB `item_extras` polluée de doublons périmés (NF525-deferred, DB-side, n'affecte pas frontend qui lit items 801-809 propres)
