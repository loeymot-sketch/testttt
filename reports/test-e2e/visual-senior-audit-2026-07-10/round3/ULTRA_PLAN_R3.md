# ULTRA-PLAN R3 — VALIDATION 100 % (2026-07-11)
> Objectif owner : 100 % validé — caisse, borne, web + TOUS les systèmes secondaires.
> Axes par page : TECHNIQUE (console/réseau) · UI/UX · BOUTONS · LOGIQUE client · SÉCURITÉ · SYNCHRO.
> Boucle : capture → analyse senior → adversaire → corrige → re-valide.

## ÉTAT CUMULÉ (R1+R2 — déjà VALIDÉ, preuves en captures/)
| Système | Pages validées | Preuves clés |
|---|---|---|
| CAISSE money-path | login, landing, grille, wizard, panier, paiement (6) | 9,50€ e2e, sync 27 cmd borne, 124 tests |
| BORNE flux complet | idle, menu, wizard-sauce, panier, upsell, paiement, confirmation (8) | #A0035 sans 429, images 35/35 |
| WEB | home, menu 38 img, wizard (heal 1-sauce vérifié) | 865ca3d Vercel, parité VERT |
| KDS / OSS | boards capturés | zombie heal à l'écran, sync A0034 |
| Adversaires | 2 workflows R1 + disputes live R2 | 1 P1 réel healé, 422=idempotence élucidé |
| Backend | 640 tests, 2 cycles P0+P1+P2=0 | NF525 4941 append-only |

## R3 — CE QUI MANQUE POUR 100 % (anchors route:list vérifiés)
### Vague A — CAISSE secondaire  ✅
- [x] A1 `/admin/historique` (le vrai anchor, PAS order-history=404) — 2860 entrées, origine Borne, N°file, montant, paiement (Remboursé/À encaisser), statut, action ✅
- [x] A2 `/admin/encaissement` — 26 cartes commandes en attente (borne+caisse), boutons Encaisser ✅
- [x] A3 `/admin/cash-overview` (Vue Caisse Unifiée) — Grand total/Caisse/Borne/Livreur, réconciliation (fond 50€, attendu tiroir), filtres ✅
### Vague B — ADMIN secondaires  ✅ (+1 finding)
- [x] B1 `/admin/dashboard` — Total ventes 38 514,42€ / 2854 cmd / 81 articles, Audit Trail NF525 à l'écran, SLA, répartition canal ✅
- [x] B2 `/admin/items` — **FINDING P2** : pollué par fixtures test (E2E-*/wval*/lorem). **No-leak PROUVÉ** (KioskMenuService=9 cats/42 items propres). Purge = action owner (cmd dédiée). Voir FINDING-B2 ✅
- [x] B3 `/admin/stock/rupture` — « Gestion Produits & Stock », catégorie-scoped, vrais produits EN STOCK, miniatures réelles, 0 pollution ✅
- [x] B4 `/admin/online-orders` — 2437 entrées, 5637/5633 = **Annulé** (sync annulation propagée) ✅
- [x] B-err `/kiosk/error/payment-refused` — Paiement refusé, 3 CTA (réessayer / **payer caisse** Plan B / annuler) ✅
### Vague D — SÉCURITÉ  ✅ (voir SECURITY_D.md)
- [x] D1 endpoints data admin sans session → **401** ; les 2 « 200 » = shell SPA HTML, 0 data (vérifié au corps) ✅
- [x] D2 OSS public : **0 PII** ✅
- [x] D3 aucun secret exposé ✅
### Vague C — WEB (validé 09/07 R1/R2, heal sauce re-vérifié R2) — re-check drift différé (bas risque)
### Vague E — Adversaire final + attestation
- [~] E1 Agent adversaire dispute B2 no-leak + sécurité + pages secondaires (en cours)
- [ ] E3 ATTESTATION 100 %

## Critère 100 %
Chaque case cochée avec capture lue + 0 défaut visuel/technique/sécu OU défaut healé+re-validé.
Frozen intouché, NF525 append-only, tout poussé.
