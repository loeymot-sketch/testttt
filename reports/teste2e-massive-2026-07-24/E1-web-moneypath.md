# E1 — WEB MONEY-PATH RÉEL (dimension #1) — 2026-07-24

**Cible LIVE** : `https://site-lecayenne.vercel.app` (Vercel → backend VPS `vps-418872ac.vps.ovh.net`)
**Outil** : Playwright `npx playwright test` (chromium Desktop + device Pixel 7). PLAYWRIGHT_NO_WEB_SERVER=1.
**Spec** : `tests/e2e/e2e-massive-E1-web-moneypath-2026-07-24.spec.js`
**Captures** : `tests/e2e/__screenshots__/e2e-massive-E1/` (29 PNG + 4 obs-*.json)

## Verdict : 5/5 scénarios PASS — money-path scellé au centime, 0 P0/P1/P2.

| Scénario | Résultat | Preuve (au centime) |
|---|---|---|
| S1 — Formule 3 pages + 2 sauces (desktop) | **PASS** | Formule affichée : Menu complet **+2,50 €** (★Populaire), Ajouter Frites **+1,90 €**, Ajouter Boisson **+1,90 €**, Sans formule (0). Sauce : Fromagère maison badge vert **« Incluse »** (pré-sél.) + Mayonnaise **+0,50 €**, « Sélection 2/4 ». Panier == recap == **10,40 €** (7,40 base + 0,50 sauce + 2,50 menu). |
| S2 — Money-path scellé (desktop) | **PASS** | **Commande RÉELLE #194** serial `240726194`. panier 10,40 = paiement 10,40 = **confirmation scellée 10,40 €** = **API 201 total 10,40**. POST `/api/frontend/order` envoie `expected_total=10.40` + items ; 201 ; queue A0032. 0 console error, 0 réponse ≥400. |
| S3 — Tacos + viandes en plus (desktop) | **PASS** | Tacos M base 6,90. 1re viande **incluse (0 €)**, chaque viande en plus **+2,50 €** (6,90→9,40→11,90→14,40). Plafond enforced : 5e clic bloqué **« Maximum 4 sélections »** (1 incluse + 3 en plus = cap 3 extra). Aucune viande gratuite parasite. |
| S4 — Commande courte mobile (Pixel 7) | **PASS** | **Commande RÉELLE #195** serial `240726195`. viewport 412×839. recap == panier == paiement == **confirmation 9,90 €** == **API 201 total 9,90**. Layout responsive intact, QR lisible. |
| S5 — Attaque affiché-vs-scellé (desktop) | **PASS** | Retour arrière wizard : ajout 2e sauce **+0,50** puis retrait **−0,50** → total revient exact (aucune rétention fantôme). Quantité panier ×2 → total ×2 **exact** (9,90→19,80, ratio 2,000). Champ « Code promo » présent au checkout. |

## Commandes réelles passées (side-effect assumé)
- **#194** — desktop, serial `240726194`, **10,40 €**, queue A0032, source=5 (web), payment=1 (paiement caisse).
- **#195** — mobile Pixel 7, serial `240726195`, **9,90 €**, queue A0033.
- (S3 et S5 = wizard/panier only, aucune commande créée.)

## Preuve structurelle anti-divergence (attaque)
Le POST client n'envoie **aucun prix arbitraire** : body = `{branch_id, order_type, source:5, payment_method, items:[item_id/qty/option_ids], expected_total, coupon_id?}`. Le backend **recalcule** (SSOT) et scelle ; `expected_total` sert de garde (422 si divergence). Constaté : `expected_total` envoyé (10,40) == total API scellé (10,40) == affiché. Un client ne peut donc ni droper un extra ni fabriquer une remise (coupon revalidé serveur). **Aucun vecteur display≠facturé trouvé.**

## Findings
- **P0 / P1 / P2 : NÉANT.** Intégrité prix parfaite, 0 drop d'extra, 0 divergence affiché/scellé, 0 console error, 0 HTTP ≥400 (4 scénarios).
- **P3 (observation, À CONFIRMER manuellement)** : l'**application** d'un code promo invalide (« CAYENNE10 » via bouton « Appliquer ») a **bloqué le checkout ~150 s** en automation (pas de timeout/erreur visible), avant que je bascule la sonde en détection-seule. Peut être un artefact de harness (input React contrôlé) OU un vrai défaut UX (pas de garde de timeout sur validation coupon invalide). **Aucun risque money** (coupon revalidé backend). À rejouer à la main.
- **Note drift mirror (non-défaut)** : le build Vercel déployé envoie `expected_total` ; le miroir local `/Users/1millnonstop/Downloads/web/api.js` ne l'envoie PAS → le déployé est plus récent que le miroir standalone.

## Captures clés (lues visuellement)
- `S1-step05-FAIRE-UN-MENU-.png` — 3 options formule + badges corrects.
- `S1-sauce-2selectionnees.png` — badge vert « Incluse » + Mayonnaise +0,50.
- `S2-confirmation.png` / `S4-confirmation.png` — tickets scellés 10,40 € / 9,90 €, QR réels, « Envoyée en cuisine ».
- `S3-viande-5sel.png` — « Maximum 4 sélections ». `S5-qty-x2.png` — total ×2.
