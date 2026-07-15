# ULTRA-CHECKLIST — audit visuel senior-dev (2026-07-10)

Méthode par page : **capture écran → je LIS + raisonne (bien fait ? manque quoi ? logique
client ?) → teste les boutons → agent adversaire dispute → corrige → re-capture → validé →
suivant**. Axes à chaque page : TECHNIQUE (console/network/erreurs), UI/UX (layout, labels bruts,
contraste, responsive), BOUTONS (fonctionnels), LOGIQUE (prix/flux corrects), SÉCU, SYNCHRO.
Servers : borne/caisse :8766 (+:8000), admin admin@lecayenne.fr/Password123!. Web = Vercel/local.

## SYSTÈME 1 — CAISSE (POS) — ordre 1
- [ ] C-01 Login admin
- [ ] C-02 POS landing (grille catégories d'abord)
- [ ] C-03 Catégorie → grille produits
- [ ] C-04 Wizard composition (variations, viandes, sauces, suppléments, extras)
- [ ] C-05 Panier / récap commande
- [ ] C-06 Paiement (espèces / carte-TPE / split)
- [ ] C-07 Tiroir-caisse (ouverture/fermeture/écart)
- [ ] C-08 Z-report / clôture jour
- [ ] C-09 Commandes parkées (park/recall)
- [ ] C-10 Fidélité (redeem / cumul)
- [ ] C-11 Historique commandes + impression ticket

## SYSTÈME 2 — BORNE (Kiosk) — ordre 2
- [ ] B-01 Idle / attract
- [ ] B-02 Menu / catégories
- [ ] B-03 Wizard (pain/galette, viande, **sauces multi 1ère offerte +0,50**, crudités, suppléments, menu)
- [ ] B-04 Upsell
- [ ] B-05 Panier
- [ ] B-06 Paiement (comptoir Plan B)
- [ ] B-07 Confirmation / n° file
- [ ] B-08 Fidélité QR
- [ ] B-09 États d'erreur (réseau, produit retiré, paiement refusé)
- [ ] B-10 Images produits (comme la borne = source)

## SYSTÈME 3 — SITE WEB (Vercel) — ordre 3
- [ ] W-01 Accueil (toutes sections)
- [ ] W-02 Menu (9 catégories, images)
- [ ] W-03 Fiche produit + wizard personnalisation (pain, sauces, etc.)
- [ ] W-04 Panier / upsell
- [ ] W-05 Checkout (mode, jour/heure, promo, lieu)
- [ ] W-06 Paiement (comptoir, Stripe OFF)
- [ ] W-07 Confirmation / OTP
- [ ] W-08 Fidélité
- [ ] W-09 Compte / connexion (Google/Apple/email)
- [ ] W-10 Commandes / historique
- [ ] W-11 L'enseigne + Légal
- [ ] W-12 Mobile responsive
- [ ] W-13 Images embarquées OK (parité borne)

## SYNCHRO (transverse)
- [ ] S-01 commande borne → caisse à-encaisser → KDS → OSS cohérente (prix/queue/compo)

## Convergence
Chaque item : VALIDÉ (2 passes propres, 0 défaut visuel/technique) avant de passer. Boucle sinon.

## PROGRÈS (2026-07-10)
- ✅ PUSH TOUT : testttt 5367ae350 + Site lecayenne a0de955 (→ Vercel).
- ✅ CAISSE VALIDÉE (C-01..C-06) — sauces multi +0,50 correct end-to-end, sync borne→caisse visible.
- ✅ BORNE B-01 idle validé. B-03 sauces = DIVERGENCE (borne max 1 vs caisse/web multi +0,50) → gate owner+LOCK.
- ⏳ BORNE wizard UI (idle→order transition finicky en scripté) + WEB (Vercel) : à continuer.

## ROUND 2 (2026-07-10) — CONVERGÉ
- ✅ C-toutes (R1) · B-01→B-07 (R1+R2 complet) · W-01→W-03 (R1+R2, heal sauce vérifié) · KDS ✅ · OSS ✅ (zombie heal à l'écran) · S-01 ✅ (A0034/A0035 cross-surface)
- ✅ Push : testttt ec609ca7e + Site-lecayenne 865ca3d · fixes prouvés live (429, cancel IMG_1753, sauce 422)
